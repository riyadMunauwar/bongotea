<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\Page;
use App\Models\Product;
use App\Support\Checkout\CheckoutConfig;
use App\Support\Checkout\CheckoutField;

/**
 * A published Page selling whatever Products it is handed. One is the shape
 * every Page had before Products could be linked in numbers; more is what a
 * combo or an upsell page looks like.
 */
function sellingPage(Product|iterable $products, string $slug = 'promo'): Page
{
    $page = Page::factory()->published()->withBody('<main>[checkout]</main>')->create(['slug' => $slug]);

    $page->products()->attach($products instanceof Product
        ? $products
        : collect($products)->map(fn (Product $product): int => $product->getKey())->all());

    return $page;
}

/**
 * A submission of one of everything the Page sells.
 *
 * Quantities are keyed by Product id, the way the rendered form names them, so
 * a test never has to know what order the admin arranged them in.
 *
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
        'quantities' => $page->products->mapWithKeys(
            fn (Product $product): array => [$product->getKey() => 1],
        )->all(),
    ], $overrides);
}

it('captures an order at the price agreed on the page', function () {
    $product = Product::factory()->create(['name' => 'Winter Jacket', 'price' => 99000]);
    $page = sellingPage($product);

    $response = $this->postJson(route('checkout.store'), capturePayload($page, [
        'quantities' => [$product->getKey() => 2],
    ]));

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
]);

it('rejects an unusable quantity against the product it was given for', function (string $quantity, string $message) {
    $product = Product::factory()->create();
    $page = sellingPage($product);

    /**
     * Reported against the line rather than the form, so the visitor is told
     * which of several items they have to fix — and the error lands in a slot
     * the rendered form actually has.
     */
    $response = $this->postJson(route('checkout.store'), capturePayload($page, [
        'quantities' => [$product->getKey() => $quantity],
    ]))->assertUnprocessable();

    /**
     * Read as a key rather than a path: the key *contains* a dot, which is
     * exactly what the form's error slot is named after.
     */
    expect($response->json('errors')['quantities.'.$product->getKey()][0])->toBe($message)
        ->and(Order::count())->toBe(0);
})->with([
    'none of it' => ['0', 'Please order at least one.'],
    'more than we can take' => ['101', 'That is more than we can take in a single order.'],
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

it('captures one line per product, each at its own price and quantity', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket', 'price' => 99000]);
    $boots = Product::factory()->create(['name' => 'Hiking Boots', 'price' => 149000]);

    $page = sellingPage([$jacket, $boots]);

    $this->postJson(route('checkout.store'), capturePayload($page, [
        'quantities' => [$jacket->getKey() => 2, $boots->getKey() => 1],
    ]))->assertCreated();

    $order = Order::sole();
    $lines = $order->lines->keyBy('product_name');

    expect($order->lines)->toHaveCount(2)
        ->and($lines['Winter Jacket']->unit_price)->toBe(99000)
        ->and($lines['Winter Jacket']->quantity)->toBe(2)
        ->and($lines['Winter Jacket']->line_total)->toBe(198000)
        ->and($lines['Hiking Boots']->unit_price)->toBe(149000)
        ->and($lines['Hiking Boots']->quantity)->toBe(1)
        ->and($lines['Hiking Boots']->line_total)->toBe(149000)
        /** The subtotal is the sum of the lines, and the total is still that plus delivery. */
        ->and($order->subtotal)->toBe(347000)
        ->and($order->delivery_charge)->toBe(6000)
        ->and($order->total)->toBe(353000);
});

it('matches a quantity to its product by id, not by the order they are linked in', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket', 'price' => 99000]);
    $boots = Product::factory()->create(['name' => 'Hiking Boots', 'price' => 149000]);

    $page = sellingPage([$jacket, $boots]);

    /**
     * The submission arrives with the boots first. Resolving by position would
     * put the boots' quantity against the jacket — the wrong item in the wrong
     * number, collected at the door — and a reorder in the admin would do the
     * same to every form already open on a phone.
     */
    $this->postJson(route('checkout.store'), capturePayload($page, [
        'quantities' => [$boots->getKey() => 5, $jacket->getKey() => 1],
    ]))->assertCreated();

    $lines = Order::sole()->lines->keyBy('product_name');

    expect($lines['Winter Jacket']->quantity)->toBe(1)
        ->and($lines['Hiking Boots']->quantity)->toBe(5);
});

it('keeps selling the rest when one of several products is deleted', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket', 'price' => 99000]);
    $boots = Product::factory()->create(['name' => 'Hiking Boots', 'price' => 149000]);

    $page = sellingPage([$jacket, $boots]);

    $payload = capturePayload($page, [
        'quantities' => [$jacket->getKey() => 1, $boots->getKey() => 1],
    ]);

    /**
     * The visitor is holding a form that offered both. Taking the page off the
     * air over a deleted upsell turns a merchandising slip into dead ad spend;
     * the jacket is still exactly what it was, at the price it was.
     */
    $boots->delete();

    $this->postJson(route('checkout.store'), $payload)->assertCreated();

    $order = Order::sole();

    expect($order->lines->pluck('product_name')->all())->toBe(['Winter Jacket'])
        ->and($order->subtotal)->toBe(99000);
});

it('refuses to sell a product the submission never offered', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket']);
    $page = sellingPage($jacket);

    $upsell = Product::factory()->create(['name' => 'Wool Scarf']);

    $payload = capturePayload($page);

    /** Linked after the visitor's copy of the page was written to disk. */
    $page->products()->attach($upsell);

    /**
     * Selling it at one anyway would put an item nobody chose into a
     * cash-on-delivery parcel, which is refused at the door and costs the
     * merchant courier fees in both directions — on the jacket too.
     *
     * The visitor is told to reload rather than to fix a field, because the
     * form on their screen has no box for the scarf to complain about. ADR-0007
     * keeps a refusal nobody can edit their way out of in the page-level slot.
     */
    $this->postJson(route('checkout.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonPath('errors.page.0', 'This page has changed since you opened it. Please reload and try again.');

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

    $this->postJson(route('checkout.store'), capturePayload($page, [
        'quantities' => [$product->getKey() => 2],
    ]))->assertCreated();

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

/**
 * The same Page's Checkout with named fields reconfigured — switched off,
 * switched on, reworded — one merchant edit at a time.
 *
 * Written through `fieldsToArray()` and read back through `fromArray()`, which
 * is the round trip the Checkout tab performs on save, so a test can never
 * store a shape the admin could not have produced.
 *
 * @param  array<string, array<string, mixed>>  $changes  keyed by field key
 */
function checkoutAsking(Page $page, array $changes): Page
{
    $page->checkout_config = CheckoutConfig::fromArray([
        'fields' => array_map(
            fn (array $field): array => array_merge($field, $changes[$field['key']] ?? []),
            $page->checkout_config->fieldsToArray(),
        ),
    ]);

    $page->save();

    return $page->refresh();
}

it('stores nothing for a detail the page has stopped asking for', function () {
    $page = sellingPage(Product::factory()->create());

    /**
     * Left required on the way out. Required and asked-at-all are two flags: a
     * merchant trying a shorter form keeps what they configured behind it, and
     * a rule on a question nobody is asked decides nothing.
     */
    checkoutAsking($page, ['address' => ['enabled' => false, 'required' => true]]);

    /**
     * The payload carries an address regardless — a stale form, or a hand-made
     * post. It was not asked for, so it is not checked and not stored: an Order
     * carries what its Checkout collected and nothing a submission volunteered.
     */
    $this->postJson(route('checkout.store'), capturePayload($page, [
        'address' => 'House 4, Road 12, Banani',
    ]))->assertCreated();

    $order = Order::sole();

    /**
     * Null rather than a blank. "We never asked" and "they left it empty" read
     * the same to a fulfiller otherwise, and only one of the two is worth
     * calling a customer about.
     */
    expect($order->customer_address)->toBeNull()
        ->and($order->customer_name)->toBe('Rahim Uddin');
});

it('captures a null for a question the visitor left blank', function () {
    $page = sellingPage(Product::factory()->create());

    checkoutAsking($page, ['name' => ['required' => false]]);

    $this->postJson(route('checkout.store'), capturePayload($page, ['name' => '  ']))
        ->assertCreated();

    expect(Order::sole()->customer_name)->toBeNull();
});

it('takes an email address only from a page that asks for one', function () {
    $product = Product::factory()->create();

    $asks = checkoutAsking(sellingPage($product, 'asks'), [
        'email' => ['enabled' => true, 'required' => true],
    ]);

    /** Off in a new Config, so this one is the form every Page starts with. */
    $doesNotAsk = sellingPage($product, 'does-not');

    $this->postJson(route('checkout.store'), capturePayload($asks, ['email' => '']))
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'Please enter your email address.');

    $this->postJson(route('checkout.store'), capturePayload($asks, ['email' => 'rahim at example']))
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'Please enter an email address we can reach you at.');

    $this->postJson(route('checkout.store'), capturePayload($asks, ['email' => 'rahim@example.com']))
        ->assertCreated();

    $this->postJson(route('checkout.store'), capturePayload($doesNotAsk, ['email' => 'rahim@example.com']))
        ->assertCreated();

    expect(Order::where('page_slug', 'asks')->sole()->customer_email)->toBe('rahim@example.com')
        ->and(Order::where('page_slug', 'does-not')->sole()->customer_email)->toBeNull();
});

it('goes on asking for a phone number whatever the stored config says', function () {
    $page = sellingPage(Product::factory()->create());

    /**
     * The shape only a tampered Config could hold: no control on the Checkout
     * tab switches a phone off. A Customer *is* their phone number, and a
     * cash-on-delivery Order nobody can call is a lead, not an Order — so the
     * refusal lives in the Config itself rather than in the one screen that
     * edits it.
     */
    $page = checkoutAsking($page, ['phone' => ['enabled' => false, 'required' => false]]);

    expect(array_map(
        fn (CheckoutField $field): string => $field->key(),
        $page->checkout_config->enabledFields(),
    ))->toContain('phone');

    $this->postJson(route('checkout.store'), capturePayload($page, ['phone' => '']))
        ->assertUnprocessable()
        ->assertJsonPath('errors.phone.0', 'Please enter a phone number we can call you on.');

    expect(Order::count())->toBe(0);
});

it('keeps what a field was configured with across a switch off and back on', function () {
    $page = sellingPage(Product::factory()->create());

    $page = checkoutAsking($page, ['district' => [
        'label' => 'Where are we delivering?',
        'required' => false,
        'help' => 'The nearest one, if yours is not listed.',
    ]]);

    $page = checkoutAsking($page, ['district' => ['enabled' => false]]);
    $page = checkoutAsking($page, ['district' => ['enabled' => true]]);

    /**
     * Switching a question off is an experiment, not a deletion. A merchant
     * trying a shorter form and putting it back must not have to rewrite what
     * they had worded.
     */
    $district = collect($page->checkout_config->fields)
        ->firstWhere(fn (CheckoutField $field): bool => $field->key() === 'district');

    expect($district->label)->toBe('Where are we delivering?')
        ->and($district->help)->toBe('The nearest one, if yours is not listed.')
        ->and($district->required)->toBeFalse()
        ->and($district->enabled)->toBeTrue();
});

it('quotes the delivery charge that a page with no district question will collect', function () {
    $page = sellingPage(Product::factory()->create());

    checkoutAsking($page, ['district' => ['enabled' => false]]);

    $html = $this->get('/promo')->assertOk()->getContent();

    preg_match('/data-delivery-charge-default="(\d+)"/', $html, $quoted);

    $this->postJson(route('checkout.store'), capturePayload($page))->assertCreated();

    $order = Order::sole();

    /**
     * With no district to price against, the flat charge is the only figure the
     * island can show — and it has to be the one Capture adds. A form that
     * totals up to less than the courier asks for at the door is a refused
     * parcel and a fee in both directions.
     */
    expect($order->customer_district)->toBeNull()
        ->and((int) ($quoted[1] ?? 0))->toBe($order->delivery_charge)
        ->and($order->total)->toBe($order->subtotal + $order->delivery_charge);
});
