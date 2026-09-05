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

        /** Carried through untouched, the way the Checkout tab submits both. */
        'delivery_options' => $page->checkout_config->deliveryOptionsToArray(),
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

/**
 * The same Page's Checkout offering the Delivery Options given, replacing
 * whatever it offered before.
 *
 * Written and read back through the Config the way the Checkout tab does, so a
 * test can never store a shape the admin could not have produced.
 *
 * @param  list<array{label: string, amount: int}>  $options
 */
function deliveringBy(Page $page, array $options): Page
{
    $page->checkout_config = CheckoutConfig::fromArray([
        'fields' => $page->checkout_config->fieldsToArray(),
        'delivery_options' => $options,
    ]);

    $page->save();

    return $page->refresh();
}

it('starts every page off charging what the installation charged', function () {
    $page = sellingPage(Product::factory()->create());

    /**
     * One option, added with no question asked, is exactly what a Page did
     * before it could price its own delivery — so a Merchant who never opens
     * the Checkout tab sees no change at all. The same shape the backfill
     * wrote onto every Page that already existed.
     */
    expect($page->checkout_config->deliveryOptionsToArray())
        ->toBe([['label' => 'Delivery', 'amount' => 6000]]);

    $this->get('/promo')
        ->assertOk()
        ->assertSee('data-delivery-amount="6000"', escape: false)
        ->assertDontSee('name="delivery_option"', escape: false);

    $this->postJson(route('checkout.store'), capturePayload($page))->assertCreated();

    expect(Order::sole()->delivery_charge)->toBe(6000);
});

it('reads a config written before delivery could be priced as the charge it was priced by', function () {
    $page = sellingPage(Product::factory()->create());

    /** The shape stored before this column knew about Delivery Options. */
    $page->checkout_config = CheckoutConfig::fromArray([
        'fields' => $page->checkout_config->fieldsToArray(),
    ]);

    expect($page->checkout_config->deliveryOptionsToArray())
        ->toBe([['label' => 'Delivery', 'amount' => 6000]]);
});

it('adds a single delivery option to the total without asking anything', function () {
    $page = deliveringBy(sellingPage(Product::factory()->create(['price' => 99000])), [
        ['label' => 'Home delivery', 'amount' => 8000],
    ]);

    /** Nothing to choose between is nothing to ask about. */
    $this->get('/promo')
        ->assertOk()
        ->assertDontSee('name="delivery_option"', escape: false)
        ->assertSee('Home delivery');

    $this->postJson(route('checkout.store'), capturePayload($page))->assertCreated();

    $order = Order::sole();

    expect($order->delivery_label)->toBe('Home delivery')
        ->and($order->delivery_charge)->toBe(8000)
        ->and($order->total)->toBe(107000);
});

it('charges nothing and shows no delivery row for a page that offers no delivery', function () {
    $page = deliveringBy(sellingPage(Product::factory()->create(['price' => 99000])), []);

    $html = $this->get('/promo')->assertOk()->getContent();

    expect($html)->not->toContain('data-gio-delivery-label')
        ->and($html)->toContain('data-delivery-amount="0"');

    $this->postJson(route('checkout.store'), capturePayload($page))->assertCreated();

    $order = Order::sole();

    /**
     * No arrangement was agreed, so there is no name to record — which is a
     * different thing from an arrangement that happened to be free.
     */
    expect($order->delivery_label)->toBeNull()
        ->and($order->delivery_charge)->toBe(0)
        ->and($order->total)->toBe(99000);
});

it('lets the customer choose between the arrangements a page offers', function () {
    $page = deliveringBy(sellingPage(Product::factory()->create(['price' => 99000])), [
        ['label' => 'Inside Dhaka', 'amount' => 6000],
        ['label' => 'Outside Dhaka', 'amount' => 12000],
    ]);

    $this->get('/promo')
        ->assertOk()
        ->assertSee('name="delivery_option"', escape: false)
        ->assertSee('Inside Dhaka')
        ->assertSee('Outside Dhaka');

    $this->postJson(route('checkout.store'), capturePayload($page, [
        'delivery_option' => 'Outside Dhaka',
    ]))->assertCreated();

    $order = Order::sole();

    expect($order->delivery_label)->toBe('Outside Dhaka')
        ->and($order->delivery_charge)->toBe(12000)
        ->and($order->total)->toBe(111000);
});

it('quotes the delivery amount that a capture from the same page will collect', function () {
    $page = deliveringBy(sellingPage(Product::factory()->create()), [
        ['label' => 'Inside Dhaka', 'amount' => 6000],
        ['label' => 'Outside Dhaka', 'amount' => 12000],
    ]);

    $html = $this->get('/promo')->assertOk()->getContent();

    preg_match('/value="Outside Dhaka"[^>]*data-amount="(\d+)"/', $html, $quoted);

    $this->postJson(route('checkout.store'), capturePayload($page, [
        'delivery_option' => 'Outside Dhaka',
    ]))->assertCreated();

    $order = Order::sole();

    /**
     * The one place the form and the door can be shown to agree. A form
     * totalling up to less than the courier collects is a refused parcel and a
     * fee in both directions.
     */
    expect((int) ($quoted[1] ?? 0))->toBe($order->delivery_charge)
        ->and($order->total)->toBe($order->subtotal + $order->delivery_charge);
});

it('takes no delivery figure from the visitor', function () {
    $page = deliveringBy(sellingPage(Product::factory()->create(['price' => 99000])), [
        ['label' => 'Inside Dhaka', 'amount' => 6000],
        ['label' => 'Outside Dhaka', 'amount' => 12000],
    ]);

    $this->postJson(route('checkout.store'), capturePayload($page, [
        'delivery_option' => 'Inside Dhaka',

        /** Every spelling a hand-made post might reach for. */
        'delivery_charge' => 0,
        'delivery_amount' => 0,
        'amount' => 0,
    ]))->assertCreated();

    /** A name arrives; the amount against it is read off the Page. */
    expect(Order::sole()->delivery_charge)->toBe(6000);
});

it('refuses a capture naming an arrangement the page does not offer', function () {
    $page = deliveringBy(sellingPage(Product::factory()->create()), [
        ['label' => 'Inside Dhaka', 'amount' => 6000],
        ['label' => 'Outside Dhaka', 'amount' => 12000],
    ]);

    $this->postJson(route('checkout.store'), capturePayload($page, [
        'delivery_option' => 'Free',
    ]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.delivery_option.0', 'Please choose one of the delivery options listed.');

    $this->postJson(route('checkout.store'), capturePayload($page))
        ->assertUnprocessable()
        ->assertJsonPath('errors.delivery_option.0', 'Please choose how you would like it delivered.');

    /**
     * Charging one of them anyway is the outcome this refusal exists to
     * prevent: in COD an amount nobody agreed to is a parcel refused at the
     * door and a courier fee in both directions.
     */
    expect(Order::count())->toBe(0);
});

it('ignores a delivery choice sent to a page that offers none to make', function () {
    $page = deliveringBy(sellingPage(Product::factory()->create()), [
        ['label' => 'Home delivery', 'amount' => 8000],
    ]);

    /** A form written to disk while the Page still offered two. */
    $this->postJson(route('checkout.store'), capturePayload($page, [
        'delivery_option' => 'Outside Dhaka',
    ]))->assertCreated();

    $order = Order::sole();

    expect($order->delivery_label)->toBe('Home delivery')
        ->and($order->delivery_charge)->toBe(8000);
});

it('holds the arrangement captured even after it is renamed and repriced', function () {
    $page = deliveringBy(sellingPage(Product::factory()->create()), [
        ['label' => 'Inside Dhaka', 'amount' => 6000],
        ['label' => 'Outside Dhaka', 'amount' => 12000],
    ]);

    $this->postJson(route('checkout.store'), capturePayload($page, [
        'delivery_option' => 'Inside Dhaka',
    ]))->assertCreated();

    deliveringBy($page, [
        ['label' => 'City delivery', 'amount' => 9000],
        ['label' => 'Outside Dhaka', 'amount' => 15000],
    ]);

    /**
     * Snapshotted like a Product's name and price, and for the same reason:
     * what was agreed at Capture is what gets collected, and a User explaining
     * the figure on the confirmation call needs the words the Customer saw.
     */
    $order = Order::sole();

    expect($order->delivery_label)->toBe('Inside Dhaka')
        ->and($order->delivery_charge)->toBe(6000);
});

it('prices delivery the same whatever district the parcel is going to', function () {
    $page = deliveringBy(sellingPage(Product::factory()->create()), [
        ['label' => 'Flat rate', 'amount' => 7000],
    ]);

    foreach (['Dhaka', 'Sylhet', 'Rangamati'] as $district) {
        $this->postJson(route('checkout.store'), capturePayload($page, ['district' => $district]))
            ->assertCreated();
    }

    /**
     * District settles nothing about what an Order costs. It is kept as
     * fulfilment data a courier API wants and cannot recover from a free-text
     * address later (ADR-0005).
     */
    expect(Order::pluck('delivery_charge')->all())->toBe([7000, 7000, 7000])
        ->and(Order::pluck('customer_district')->all())->toBe(['Dhaka', 'Sylhet', 'Rangamati']);
});

it('prices delivery the same with the district question switched off', function () {
    $page = deliveringBy(sellingPage(Product::factory()->create()), [
        ['label' => 'Flat rate', 'amount' => 7000],
    ]);

    $page = checkoutAsking($page, ['district' => ['enabled' => false]]);

    $this->postJson(route('checkout.store'), capturePayload($page))->assertCreated();

    $order = Order::sole();

    expect($order->customer_district)->toBeNull()
        ->and($order->delivery_charge)->toBe(7000)
        ->and($order->total)->toBe($order->subtotal + 7000);
});

it('drops a delivery option nothing could be chosen by', function () {
    $config = CheckoutConfig::fromArray(['delivery_options' => [
        ['label' => 'Inside Dhaka', 'amount' => 6000],

        /** Nameless: a radio button nobody can tell apart or agree to. */
        ['label' => '  ', 'amount' => 9000],

        /** A second option under one name is a choice that decides nothing. */
        ['label' => 'Inside Dhaka', 'amount' => 20000],

        /** Below nothing is a column the amount could not be stored in. */
        ['label' => 'Refunded delivery', 'amount' => -500],
    ]]);

    expect($config->deliveryOptionsToArray())->toBe([
        ['label' => 'Inside Dhaka', 'amount' => 6000],
        ['label' => 'Refunded delivery', 'amount' => 0],
    ]);
});

/**
 * The same Page's Checkout with the Product picker switched on or off.
 *
 * Written through `toArray()` and read back through `fromArray()`, which is the
 * round trip the Checkout tab performs on save, so a test can never store a
 * shape the admin could not have produced.
 */
function picking(Page $page, bool $picker = true): Page
{
    $page->checkout_config = CheckoutConfig::fromArray([
        ...$page->checkout_config->toArray(),
        'product_picker' => $picker,
    ]);

    $page->save();

    return $page->refresh();
}

it('sells every product a page links while the picker is off', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket', 'price' => 99000]);
    $boots = Product::factory()->create(['name' => 'Hiking Boots', 'price' => 149000]);

    $page = sellingPage([$jacket, $boots]);

    /** Off in a new Config, so this is the Checkout every Page already had. */
    expect($page->checkout_config->productPicker)->toBeFalse()
        ->and($page->offersProductChoice())->toBeFalse();

    /** The same markup it drew before there was a picker to switch on. */
    $this->get('/promo')
        ->assertOk()
        ->assertDontSee('name="selected[]"', escape: false)
        ->assertSee('<div class="gio-checkout-product-head">', escape: false);

    /** Nothing names a selection, and both are sold. */
    $this->postJson(route('checkout.store'), capturePayload($page))->assertCreated();

    expect(Order::sole()->lines->pluck('product_name')->all())
        ->toBe(['Winter Jacket', 'Hiking Boots']);
});

it('offers an unticked checkbox per product once the picker is on', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket', 'price' => 99000]);
    $boots = Product::factory()->create(['name' => 'Hiking Boots', 'price' => 149000]);

    $page = picking(sellingPage([$jacket, $boots]));

    $html = $this->get('/promo')->assertOk()->getContent();

    preg_match_all('/<input[^>]*name="selected\[\]"[^>]*>/', $html, $picks);

    expect($picks[0])->toHaveCount(2);

    /**
     * Nothing is pre-selected. On a cash-on-delivery page a pre-ticked upsell
     * is not a lost sale, it is a refused parcel and a courier fee in both
     * directions on the whole order — including the item the customer wanted.
     */
    foreach ($picks[0] as $pick) {
        expect($pick)->not->toContain('checked');
    }

    expect($html)->toContain('value="'.$jacket->getKey().'"')
        ->and($html)->toContain('value="'.$boots->getKey().'"')
        /** Nothing chosen is nothing to pay for, delivery aside. */
        ->and($html)->toContain('৳0.00');
});

it('renders no checkbox for the one product a page links', function () {
    $product = Product::factory()->create(['name' => 'Winter Jacket', 'price' => 99000]);

    $page = picking(sellingPage($product));

    /**
     * A choice of one is not a choice. Asking a customer to tick the only thing
     * on the page is a box between them and the order, and forgetting it is a
     * sale lost to a form nobody meant to make optional.
     */
    expect($page->offersProductChoice())->toBeFalse();

    $this->get('/promo')->assertOk()->assertDontSee('name="selected[]"', escape: false);

    $this->postJson(route('checkout.store'), capturePayload($page))->assertCreated();

    expect(Order::sole()->lines->pluck('product_name')->all())->toBe(['Winter Jacket']);
});

it('captures a line only for the products the customer ticked', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket', 'price' => 99000]);
    $boots = Product::factory()->create(['name' => 'Hiking Boots', 'price' => 149000]);

    $page = picking(sellingPage([$jacket, $boots]));

    /**
     * The form posts a quantity for every product it drew, ticked or not — the
     * stepper is hidden rather than removed, so the page can still say which
     * products it offered. What was chosen is the ticked list and nothing else.
     */
    $this->postJson(route('checkout.store'), capturePayload($page, [
        'selected' => [$boots->getKey()],
        'quantities' => [$jacket->getKey() => 1, $boots->getKey() => 2],
    ]))->assertCreated();

    $order = Order::sole();

    expect($order->lines->pluck('product_name')->all())->toBe(['Hiking Boots'])
        ->and($order->lines->sole()->quantity)->toBe(2)
        ->and($order->subtotal)->toBe(298000)
        ->and($order->total)->toBe(304000);
});

it('refuses a capture with nothing ticked and creates no order', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket']);
    $boots = Product::factory()->create(['name' => 'Hiking Boots']);

    $page = picking(sellingPage([$jacket, $boots]));

    /**
     * An order for nothing is not an order. The refusal is named against the
     * selection so the island shows it beside the boxes the customer never
     * ticked, rather than under a field they filled in correctly.
     */
    $this->postJson(route('checkout.store'), capturePayload($page))
        ->assertUnprocessable()
        ->assertJsonPath('errors.selected.0', 'Please choose at least one item to order.');

    /** An empty tick list says the same thing as no tick list at all. */
    $this->postJson(route('checkout.store'), capturePayload($page, ['selected' => []]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.selected.0', 'Please choose at least one item to order.');

    expect(Order::count())->toBe(0);
});

it('tells the visitor to reload when everything they ticked has gone', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket']);
    $boots = Product::factory()->create(['name' => 'Hiking Boots']);

    $page = picking(sellingPage([$jacket, $boots]));

    $payload = capturePayload($page, ['selected' => [$boots->getKey()]]);

    /** Soft-deleted after the visitor ticked it and before they submitted. */
    $boots->delete();

    /**
     * The page still sells the jacket, so it is not off the air — but nothing
     * the customer agreed to buy is left, and an Order with no lines is a
     * courier collecting a delivery charge for an empty parcel. ADR-0007 keeps
     * a refusal nobody can edit their way out of in the page-level slot.
     */
    $this->postJson(route('checkout.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonPath('errors.page.0', 'This page has changed since you opened it. Please reload and try again.');

    expect(Order::count())->toBe(0);
});

it('ignores what a form claims about a product nobody ticked', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket', 'price' => 99000]);
    $boots = Product::factory()->create(['name' => 'Hiking Boots', 'price' => 149000]);

    $page = picking(sellingPage([$jacket, $boots]));

    /**
     * A quantity nobody chose decides nothing: it is not checked and it is not
     * sold. Refusing the capture over it would turn a hidden box the customer
     * never opened into a lost order.
     */
    $this->postJson(route('checkout.store'), capturePayload($page, [
        'selected' => [$jacket->getKey()],
        'quantities' => [$jacket->getKey() => 1, $boots->getKey() => 0],
    ]))->assertCreated();

    expect(Order::sole()->lines->pluck('product_name')->all())->toBe(['Winter Jacket']);
});

it('checks the quantity of a product that was ticked', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket']);
    $boots = Product::factory()->create(['name' => 'Hiking Boots']);

    $page = picking(sellingPage([$jacket, $boots]));

    $response = $this->postJson(route('checkout.store'), capturePayload($page, [
        'selected' => [$jacket->getKey()],
        'quantities' => [$jacket->getKey() => 0, $boots->getKey() => 1],
    ]))->assertUnprocessable();

    /** Read as a key rather than a path: the key contains a dot. */
    expect($response->json('errors')['quantities.'.$jacket->getKey()][0])
        ->toBe('Please order at least one.')
        ->and(Order::count())->toBe(0);
});

it('sells nothing a tick names that the page does not sell', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket', 'price' => 99000]);
    $boots = Product::factory()->create(['name' => 'Hiking Boots', 'price' => 149000]);

    $page = picking(sellingPage([$jacket, $boots]));

    $elsewhere = Product::factory()->create(['name' => 'Wool Scarf', 'price' => 49000]);

    /**
     * A tick names a Product; whether this Page sells it is read off the Page.
     * A hand-made post naming something linked to another page sells nothing
     * extra — the same rule that keeps every figure out of the request.
     */
    $this->postJson(route('checkout.store'), capturePayload($page, [
        'selected' => [$jacket->getKey(), $elsewhere->getKey()],
        'quantities' => [
            $jacket->getKey() => 1,
            $boots->getKey() => 1,
            $elsewhere->getKey() => 5,
        ],
    ]))->assertCreated();

    $order = Order::sole();

    expect($order->lines->pluck('product_name')->all())->toBe(['Winter Jacket'])
        ->and($order->subtotal)->toBe(99000);
});

it('refuses a form drawn before the picker was switched on', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket']);
    $boots = Product::factory()->create(['name' => 'Hiking Boots']);

    $page = sellingPage([$jacket, $boots]);

    /** Written to disk while the page still sold both together. */
    $payload = capturePayload($page);

    picking($page);

    /**
     * Selling both anyway would put an item the customer was never asked about
     * into a cash-on-delivery parcel. Being turned away with a message is the
     * safe direction to fail in, and the island shows it in the page-level slot
     * because the older form has no boxes to hang it on.
     */
    $this->postJson(route('checkout.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonPath('errors.selected.0', 'Please choose at least one item to order.');

    expect(Order::count())->toBe(0);
});

it('refuses a tick that names nothing this page could sell', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket']);
    $boots = Product::factory()->create(['name' => 'Hiking Boots']);

    $page = picking(sellingPage([$jacket, $boots]));

    /**
     * A hand-made post, ticking something that is not a product id at all. It
     * is refused here rather than left to reach a Capture with nothing to
     * sell, which the visitor would meet as a server error rather than as an
     * answer.
     */
    $this->postJson(route('checkout.store'), capturePayload($page, [
        'selected' => ['not-a-product'],
    ]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.page.0', 'This page has changed since you opened it. Please reload and try again.');

    expect(Order::count())->toBe(0);
});
