<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\Page;
use App\Models\Product;
use App\Support\Checkout\CheckoutConfig;

/**
 * A published Page selling exactly one Product — the only shape a Checkout can
 * appear on, because publishing any other is blocked.
 */
function sellingPage(Product $product, string $slug = 'promo'): Page
{
    $page = Page::factory()->published()->withBody('<main>[checkout]</main>')->create(['slug' => $slug]);

    $page->products()->attach($product);

    return $page;
}

/**
 * @return array<string, mixed>
 */
function capturePayload(Page $page, array $overrides = []): array
{
    return array_merge([
        'page' => $page->slug,
        'name' => 'Rahim Uddin',
        'phone' => '01712345678',
        'address' => 'House 4, Road 12, Banani',
        'district' => 'Dhaka',
        'quantity' => 1,
    ], $overrides);
}

it('captures an order at the price agreed on the page', function () {
    $product = Product::factory()->create(['name' => 'Winter Jacket', 'price' => 99000]);
    $page = sellingPage($product);

    $response = $this->postJson(route('checkout.store'), capturePayload($page, ['quantity' => 2]));

    $response->assertCreated();

    $order = Order::sole();

    expect($order->page_slug)->toBe('promo')
        ->and($order->customer_name)->toBe('Rahim Uddin')
        ->and($order->customer_district)->toBe('Dhaka')
        ->and($order->subtotal)->toBe(198000)
        ->and($order->delivery_charge)->toBe(6000)
        /** The figure the courier collects at the door includes delivery. */
        ->and($order->total)->toBe(204000);

    $line = $order->lines()->sole();

    expect($line->product_name)->toBe('Winter Jacket')
        ->and($line->unit_price)->toBe(99000)
        ->and($line->quantity)->toBe(2)
        ->and($line->line_total)->toBe(198000)
        ->and($line->product_id)->toBe($product->id);

    $response->assertJsonPath('redirect', route('checkout.confirmation', $order->order_number));
});

it('holds the price captured even after the product is repriced', function () {
    $product = Product::factory()->create(['price' => 99000]);
    $page = sellingPage($product);

    $this->postJson(route('checkout.store'), capturePayload($page))->assertCreated();

    $product->update(['price' => 129000]);

    /**
     * Reading the price live is a correctness bug, not a trade-off: the
     * fulfiller collects 1,290 for an order placed at 990, and in COD that is a
     * refused delivery and a courier fee on a returned parcel.
     */
    $order = Order::sole();

    expect($order->lines()->sole()->unit_price)->toBe(99000)
        ->and($order->total)->toBe(105000);
});

it('takes no price from the visitor', function () {
    $product = Product::factory()->create(['price' => 99000]);
    $page = sellingPage($product);

    $this->postJson(route('checkout.store'), capturePayload($page, [
        'unit_price' => 1,
        'price' => 1,
        'subtotal' => 1,
        'total' => 1,
        'delivery_charge' => 0,
    ]))->assertCreated();

    /** Every figure is derived server-side from the Page's own Product. */
    $order = Order::sole();

    expect($order->lines()->sole()->unit_price)->toBe(99000)
        ->and($order->subtotal)->toBe(99000)
        ->and($order->delivery_charge)->toBe(6000)
        ->and($order->total)->toBe(105000);
});

it('rejects incomplete details and captures nothing', function (string $field, string $value, string $message) {
    $page = sellingPage(Product::factory()->create());

    $this->postJson(route('checkout.store'), capturePayload($page, [$field => $value]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.'.$field.'.0', $message);

    /**
     * An order without an address is not an order, it is a lead. Half-capturing
     * one would make every downstream number — conversion, revenue, the
     * fulfilment queue — ambiguous.
     */
    expect(Order::count())->toBe(0);
})->with([
    'no name' => ['name', '', 'Please enter your name.'],
    'no phone' => ['phone', '', 'Please enter a phone number we can call you on.'],
    'unreachable phone' => ['phone', '12', 'Please enter a valid phone number we can call you on.'],
    'no address' => ['address', '', 'Please enter the address we should deliver to.'],
    'no district' => ['district', '', 'Please choose your district.'],
    'district we do not deliver to' => ['district', 'Atlantis', 'Please choose your district from the list.'],
    'no quantity' => ['quantity', '0', 'Please order at least one.'],
]);

it('refuses an order against a page that is not published', function () {
    $product = Product::factory()->create();
    $page = Page::factory()->withBody('<main>[checkout]</main>')->create(['slug' => 'promo']);
    $page->products()->attach($product);

    $this->postJson(route('checkout.store'), capturePayload($page))
        ->assertUnprocessable()
        ->assertJsonPath('errors.page.0', 'This page is no longer taking orders.');

    expect(Order::count())->toBe(0);
});

it('refuses an order once the page has nothing left to sell', function () {
    $product = Product::factory()->create();
    $page = sellingPage($product);

    /**
     * The visitor is submitting a copy of the page that was rendered to disk
     * before the product went away. Taking cash for something that cannot ship
     * is the one outcome worse than a lost order.
     */
    $product->delete();

    $this->postJson(route('checkout.store'), capturePayload($page))
        ->assertUnprocessable()
        ->assertJsonPath('errors.page.0', 'This page is no longer taking orders.');

    expect(Order::count())->toBe(0);
});

it('puts two spellings of one number behind a single customer', function () {
    $page = sellingPage(Product::factory()->create());

    $this->postJson(route('checkout.store'), capturePayload($page, ['phone' => '01712-345678']))->assertCreated();
    $this->postJson(route('checkout.store'), capturePayload($page, ['phone' => '+8801712345678']))->assertCreated();

    /**
     * A phone number is an identity. Storing the two spellings apart would not
     * look like a bug; it would look like two customers.
     */
    expect(Customer::count())->toBe(1)
        ->and(Customer::sole()->phone)->toBe('+8801712345678')
        ->and(Order::count())->toBe(2);
});

it('answers a plain form post with a redirect the browser will not resubmit', function () {
    $page = sellingPage(Product::factory()->create());

    /** No JSON accept header: the visitor's scripts never ran. */
    $response = $this->post(route('checkout.store'), capturePayload($page));

    $response->assertRedirect(route('checkout.confirmation', Order::sole()->order_number));

    /** 303, so a refresh of the confirmation page cannot capture a second order. */
    expect($response->getStatusCode())->toBe(303);
});

it('shows the visitor their order without printing their details back', function () {
    $product = Product::factory()->create(['name' => 'Winter Jacket', 'price' => 99000]);
    $page = sellingPage($product);

    $this->postJson(route('checkout.store'), capturePayload($page, ['quantity' => 2]))->assertCreated();

    $order = Order::sole();

    $response = $this->get(route('checkout.confirmation', $order->order_number));

    $response->assertOk()
        ->assertSee($order->order_number)
        ->assertSee('Winter Jacket')
        ->assertSee('৳2040.00');

    /**
     * This URL is a bearer handle that ends up in browser history and Referer
     * headers, so the page shows the order and not the customer.
     */
    $response->assertDontSee('Rahim Uddin')
        ->assertDontSee('01712345678')
        ->assertDontSee('Banani');
});

it('does not keep the confirmation page anywhere it could be seen again', function () {
    $page = sellingPage(Product::factory()->create());

    $this->postJson(route('checkout.store'), capturePayload($page))->assertCreated();

    $response = $this->get(route('checkout.confirmation', Order::sole()->order_number));

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('X-Robots-Tag'))->toContain('noindex');
});

it('404s a confirmation page for an order that does not exist', function () {
    $this->get(route('checkout.confirmation', 'AAAA2222'))->assertNotFound();
});

it('hands a token to a client that cannot pass the origin check', function () {
    /**
     * The Checkout island ships inside a page served off disk with no session
     * and no token. This endpoint is what turns "a webview that omits
     * Sec-Fetch-Site cannot order" into "it pays one extra round trip".
     */
    $response = $this->getJson(route('checkout.token'));

    $response->assertOk();

    expect($response->json('token'))->toBeString()->not->toBeEmpty();
});

it('turns away a flood of captures from one address', function () {
    config(['giopages.checkout.rate_limit' => 1]);

    $page = sellingPage(Product::factory()->create());

    $this->postJson(route('checkout.store'), capturePayload($page))->assertCreated();

    $this->postJson(route('checkout.store'), capturePayload($page))->assertTooManyRequests();

    expect(Order::count())->toBe(1);
});

it('checks a capture against the checkout config of the page it came from', function () {
    $product = Product::factory()->create();

    $asksForAnAddress = sellingPage($product, 'asks');

    $doesNotAskForAnAddress = sellingPage($product, 'does-not');
    $doesNotAskForAnAddress->checkout_config = CheckoutConfig::fromArray([
        'fields' => [['key' => 'address', 'label' => 'Delivery address', 'required' => false]],
    ]);
    $doesNotAskForAnAddress->save();

    /**
     * One payload, two Pages, two answers. The form a visitor filled in and the
     * rules their answers are checked against are the same definition, so a
     * Customer is never refused for leaving out something they were never
     * asked for.
     */
    $this->postJson(route('checkout.store'), capturePayload($asksForAnAddress, ['address' => '']))
        ->assertUnprocessable()
        ->assertJsonPath('errors.address.0', 'Please enter the address we should deliver to.');

    $this->postJson(route('checkout.store'), capturePayload($doesNotAskForAnAddress, ['address' => '']))
        ->assertCreated();

    expect(Order::count())->toBe(1);
});
