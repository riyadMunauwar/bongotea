<?php

use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Page;
use App\Models\Product;
use App\Support\Checkout\CheckoutConfig;
use App\Support\Checkout\CheckoutField;
use App\Support\Checkout\CheckoutFieldType;
use App\Support\Checkout\FieldPattern;
use App\Support\Checkout\PricingFingerprint;
use App\Support\Orders\CapturedAnswer;
use Illuminate\Support\Str;

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

    $response->assertJsonPath('redirect', route('checkout.order-complete', $order->order_number));
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

    /** A form written to disk while the page still offered both. */
    $stale = capturePayload($page, [
        'quantities' => [$jacket->getKey() => 1, $boots->getKey() => 1],
    ]);

    $boots->delete();

    /**
     * That visitor is holding a form quoting 2,480 for the pair. Selling them
     * the jacket alone collects 1,050 at a door where they agreed to something
     * else, so their submission is turned away with a reload rather than
     * silently re-priced (ADR-0007).
     */
    $this->postJson(route('checkout.store'), $stale)
        ->assertUnprocessable()
        ->assertJsonPath('errors.page.0', 'This page has changed since you opened it. Reload it to see the current prices, then order again.');

    /**
     * The page itself is not off the air, which is what the refusal above costs
     * and all it costs. Taking it down over a deleted upsell would turn a
     * merchandising slip into dead ad spend; the jacket is still exactly what
     * it was, at the price it was, and the next visitor buys it.
     */
    $this->postJson(route('checkout.store'), capturePayload($page->refresh(), [
        'quantities' => [$jacket->getKey() => 1],
    ]))->assertCreated();

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
     * Linking a product changes what the page would collect, so this is the
     * fingerprint's refusal. The visitor is told to reload rather than to fix a
     * field, because the form on their screen has no box for the scarf to
     * complain about: ADR-0007 keeps a refusal nobody can edit their way out of
     * in the page-level slot.
     */
    $this->postJson(route('checkout.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonPath('errors.page.0', 'This page has changed since you opened it. Reload it to see the current prices, then order again.');

    expect(Order::count())->toBe(0);
});

it('refuses a submission that leaves out a product the page sells', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket']);
    $scarf = Product::factory()->create(['name' => 'Wool Scarf']);

    $page = sellingPage([$jacket, $scarf]);

    /**
     * A hand-made post rather than a form that went stale: the fingerprint is
     * this page's current one, and the scarf is simply missing from it. That is
     * the case `unofferedProducts()` is kept for now that the fingerprint turns
     * a stale form away first — without it the refusal would land on
     * `quantities.<id>` and read to the visitor as "please order at least one"
     * of something their screen never showed.
     */
    $this->postJson(route('checkout.store'), capturePayload($page, [
        'quantities' => [$jacket->getKey() => 1],
    ]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.page.0', 'This page has changed since you opened it. Reload it to see the current prices, then order again.');

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

    $response->assertRedirect(route('checkout.order-complete', Order::sole()->order_number));

    /** 303, so a refresh of the Order Complete Page cannot capture a second order. */
    expect($response->getStatusCode())->toBe(303);
});

it('shows the visitor their order without printing their details back', function () {
    $product = Product::factory()->create(['name' => 'Winter Jacket', 'price' => 99000]);
    $page = sellingPage($product);

    $this->postJson(route('checkout.store'), capturePayload($page, [
        'quantities' => [$product->getKey() => 2],
    ]))->assertCreated();

    $order = Order::sole();

    $response = $this->get(route('checkout.order-complete', $order->order_number));

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

it('does not keep the order complete page anywhere it could be seen again', function () {
    $page = sellingPage(Product::factory()->create());

    $this->postJson(route('checkout.store'), capturePayload($page))->assertCreated();

    $response = $this->get(route('checkout.order-complete', Order::sole()->order_number));

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('X-Robots-Tag'))->toContain('noindex');
});

it('404s an order complete page for an order that does not exist', function () {
    $this->get(route('checkout.order-complete', 'AAAA2222'))->assertNotFound();
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

    /** The markup alone: the script beside it names the row's marker whether or not one was drawn. */
    $markup = (string) preg_replace('/<(style|script)>.*?<\/\1>/s', '', $html);

    preg_match('/data-gio-subtotal>([^<]*)</', $html, $subtotal);
    preg_match('/data-gio-total>([^<]*)</', $html, $total);

    /**
     * No row to pick from, no line in the summary, and the total the page was
     * rendered with is the subtotal — what a visitor whose scripts never run
     * reads and orders from.
     */
    expect($markup)->not->toContain('data-gio-delivery-option')
        ->and($markup)->not->toContain('data-gio-delivery-label')
        ->and($html)->toContain('data-delivery-amount="0"')
        ->and($total[1] ?? null)->toBe($subtotal[1] ?? '');

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
     * courier collecting a delivery charge for an empty parcel. Losing a
     * product moves the fingerprint, so this is refused there; the hand-made
     * post that reaches `nothingChosenIsOnSale()` with a current fingerprint is
     * pinned further down. Either way ADR-0007 keeps a refusal nobody can edit
     * their way out of in the page-level slot.
     */
    $this->postJson(route('checkout.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonPath('errors.page.0', 'This page has changed since you opened it. Reload it to see the current prices, then order again.');

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

it('sells only what was ticked on a form drawn before the picker was switched off', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket', 'price' => 99000]);
    $boots = Product::factory()->create(['name' => 'Hiking Boots', 'price' => 149000]);

    $page = picking(sellingPage([$jacket, $boots]));

    /** Written to disk while the page still asked the customer to tick. */
    $payload = capturePayload($page, ['selected' => [$jacket->getKey()]]);

    picking($page, picker: false);

    /**
     * Nothing priced moved, so the fingerprint has nothing to turn this away
     * for — and should not: the customer ticked one item and read a total for
     * one item. Reading the merchant's switch as it stands now and ignoring
     * the ticks the older form sent would sell them both, which is an item
     * nobody chose in a cash-on-delivery parcel and a refusal at the door.
     */
    $this->postJson(route('checkout.store'), $payload)->assertCreated();

    $order = Order::sole();

    expect($order->lines->pluck('product_name')->all())->toBe(['Winter Jacket'])
        ->and($order->subtotal)->toBe(99000);
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
        ->assertJsonPath('errors.page.0', 'This page has changed since you opened it. Reload it to see the current prices, then order again.');

    expect(Order::count())->toBe(0);
});

/**
 * One question a Merchant added of their own, in the shape the Checkout tab
 * saves a row in.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function question(string $key, string $type = 'text', array $overrides = []): array
{
    return array_merge([
        'key' => $key,
        'label' => Str::headline($key),
        'type' => $type,
        'enabled' => true,
        'required' => false,
    ], $overrides);
}

/**
 * The same Page's Checkout, asking its built-in questions and the Merchant's
 * own — with theirs placed straight after one of the built-ins rather than
 * appended, because where a question sits is the point of one field list.
 *
 * Written through `fieldsToArray()` and read back through `fromArray()`, which
 * is the round trip the Checkout tab performs on save.
 *
 * @param  list<array<string, mixed>>  $questions
 */
function checkoutAlsoAsking(Page $page, array $questions, string $after = 'phone'): Page
{
    $fields = [];

    foreach ($page->checkout_config->fieldsToArray() as $field) {
        $fields[] = $field;

        if ($field['key'] === $after) {
            $fields = [...$fields, ...$questions];
        }
    }

    return checkoutOf($page, $fields);
}

/**
 * The same Page's Checkout with exactly these fields, in this order.
 *
 * @param  list<array<string, mixed>>  $fields
 */
function checkoutOf(Page $page, array $fields): Page
{
    $page->checkout_config = CheckoutConfig::fromArray([
        'fields' => $fields,

        /** Carried through untouched, the way the Checkout tab submits both. */
        'delivery_options' => $page->checkout_config->deliveryOptionsToArray(),
    ]);

    $page->save();

    return $page->refresh();
}

/**
 * What an Order recorded for the Merchant's own questions, in its stored shape.
 *
 * @return list<array<string, mixed>>
 */
function snapshotOf(Order $order): array
{
    return array_map(fn (CapturedAnswer $answer): array => $answer->toArray(), $order->answers);
}

it('asks a question the merchant added in the place they put it', function () {
    $page = sellingPage(Product::factory()->create());

    $page = checkoutAlsoAsking($page, [question('gift_note')], after: 'phone');

    /**
     * One ordered list, both kinds of field. A question a merchant added is not
     * pushed to the end of the form because it arrived last (ADR-0006).
     */
    expect(array_map(fn (CheckoutField $field): string => $field->key(), $page->checkout_config->enabledFields()))
        ->toBe(['name', 'phone', 'gift_note', 'district', 'address']);

    $html = $this->get('/promo')->assertOk()->getContent();

    expect(strpos($html, 'name="gift_note"'))
        ->toBeGreaterThan(strpos($html, 'name="phone"'))
        ->toBeLessThan(strpos($html, 'name="address"'));
});

it('draws a question the merchant added with the control its type names', function () {
    $page = sellingPage(Product::factory()->create());

    checkoutAlsoAsking($page, [
        question('gift_note', 'text'),
        question('directions', 'textarea'),
        question('floor', 'number'),
        question('colour', 'select', ['options' => [['value' => 'sky', 'label' => 'Sky blue']]]),
        question('wrapping', 'radio', ['options' => [['value' => 'paper', 'label' => 'Brown paper']]]),
        question('terms', 'checkbox', ['label' => 'I agree to the terms']),
    ]);

    $html = $this->get('/promo')->assertOk()->getContent();

    expect($html)
        ->toMatch('/<input[^>]*name="gift_note"[^>]*type="text"/')
        ->toMatch('/<textarea[^>]*name="directions"/')
        ->toMatch('/<input[^>]*name="floor"[^>]*type="number"/')
        ->toContain('<option value="sky">Sky blue</option>')
        ->toMatch('/<input name="wrapping" value="paper"[^>]*type="radio"/')
        ->toContain('Brown paper')
        /** A checkbox is worded beside the box rather than above it. */
        ->toMatch('/<input name="terms"[^>]*type="checkbox"[^>]*value="1"/')
        ->toContain('I agree to the terms');
});

it('records an answer with the question it was asked', function () {
    $page = sellingPage(Product::factory()->create());

    $page = checkoutAlsoAsking($page, [
        question('colour', 'select', [
            'label' => 'Which colour?',
            'options' => [
                ['value' => 'sky', 'label' => 'Sky blue'],
                ['value' => 'moss', 'label' => 'Moss green'],
            ],
        ]),
        question('gift_note', 'text', ['label' => 'Gift note']),
    ]);

    $this->postJson(route('checkout.store'), capturePayload($page, [
        'colour' => 'moss',
        'gift_note' => '  Happy birthday  ',
    ]))->assertCreated();

    /**
     * The key, the wording, the control and the value, in the order they were
     * asked — and for a choice, the wording of the option picked as well as the
     * value submitted. An Order records everything it needs to be read without
     * consulting anything else.
     */
    expect(snapshotOf(Order::sole()))->toBe([
        ['key' => 'colour', 'label' => 'Which colour?', 'type' => 'select', 'value' => 'moss', 'option_label' => 'Moss green'],
        ['key' => 'gift_note', 'label' => 'Gift note', 'type' => 'text', 'value' => 'Happy birthday', 'option_label' => null],
    ]);
});

it('leaves a past order reading as it did once the question changes or goes', function () {
    $page = sellingPage(Product::factory()->create());

    $page = checkoutAlsoAsking($page, [
        question('colour', 'select', [
            'label' => 'Which colour?',
            'options' => [['value' => 'moss', 'label' => 'Moss green']],
        ]),
    ]);

    $this->postJson(route('checkout.store'), capturePayload($page, ['colour' => 'moss']))
        ->assertCreated();

    /** Reworded across a campaign, which is what a landing-page system is for. */
    $page = checkoutAlsoAsking($page, [
        question('colour', 'select', [
            'label' => 'Pick a shade',
            'options' => [['value' => 'moss', 'label' => 'Forest green']],
        ]),
    ]);

    /** Then dropped from the form altogether, and then the Page itself. */
    $page = checkoutOf($page, array_values(array_filter(
        $page->checkout_config->fieldsToArray(),
        fn (array $field): bool => $field['key'] !== 'colour',
    )));

    $page->products()->detach();
    $page->forceDelete();

    expect(snapshotOf(Order::sole()))->toBe([
        ['key' => 'colour', 'label' => 'Which colour?', 'type' => 'select', 'value' => 'moss', 'option_label' => 'Moss green'],
    ]);
});

it('refuses an answer to a choice that was never offered', function (string $type) {
    $page = sellingPage(Product::factory()->create());

    $page = checkoutAlsoAsking($page, [
        question('colour', $type, ['options' => [['value' => 'moss', 'label' => 'Moss green']]]),
    ]);

    /**
     * A stale form, or a hand-made post. The list is what the answer is checked
     * against, so a value outside it is refused rather than recorded as an
     * answer nobody was ever offered.
     */
    $this->postJson(route('checkout.store'), capturePayload($page, ['colour' => 'crimson']))
        ->assertUnprocessable()
        ->assertJsonPath('errors.colour.0', 'Please choose one of the options listed.');

    $this->postJson(route('checkout.store'), capturePayload($page, ['colour' => 'moss']))
        ->assertCreated();

    expect(Order::sole()->answers[0]->value)->toBe('moss');
})->with(['select', 'radio']);

it('records a question this checkout asked and nobody answered', function () {
    $page = sellingPage(Product::factory()->create());

    $page = checkoutAlsoAsking($page, [question('gift_note', 'text', ['label' => 'Gift note'])]);

    $this->postJson(route('checkout.store'), capturePayload($page, ['gift_note' => '   ']))
        ->assertCreated();

    /**
     * Asked and left blank, which is worth telling from never asked at all: the
     * first is a customer who had nothing to say and the second is a question
     * this page does not put. Only one of the two is worth a phone call.
     */
    expect(snapshotOf(Order::sole()))->toBe([
        ['key' => 'gift_note', 'label' => 'Gift note', 'type' => 'text', 'value' => null, 'option_label' => null],
    ]);
});

it('records nothing for a question the page has stopped asking', function () {
    $page = sellingPage(Product::factory()->create());

    $page = checkoutAlsoAsking($page, [
        question('gift_note', 'text', ['enabled' => false, 'required' => true]),
    ]);

    /**
     * The payload carries an answer regardless — a stale form, or a hand-made
     * post. It was not asked for, so it is not checked and not recorded.
     */
    $this->postJson(route('checkout.store'), capturePayload($page, ['gift_note' => 'Happy birthday']))
        ->assertCreated();

    expect(Order::sole()->answers)->toBe([]);
});

it('refuses an order that leaves a compulsory question from the merchant unanswered', function () {
    $page = sellingPage(Product::factory()->create());

    $page = checkoutAlsoAsking($page, [
        question('gift_note', 'text', ['label' => 'Gift note', 'required' => true]),
    ]);

    $this->postJson(route('checkout.store'), capturePayload($page, ['gift_note' => '']))
        ->assertUnprocessable()
        ->assertJsonPath('errors.gift_note.0', 'Please answer this question.');

    expect(Order::count())->toBe(0);
});

it('tells a ticked box from one nobody touched', function () {
    $page = sellingPage(Product::factory()->create());

    $page = checkoutAlsoAsking($page, [
        question('gift_wrap', 'checkbox', ['label' => 'Gift wrap this']),
    ]);

    /**
     * An unticked checkbox is absent from a form post, so nothing arrives for
     * it at all — and "no" is what that means, rather than an answer missing.
     */
    $this->postJson(route('checkout.store'), capturePayload($page))->assertCreated();

    $this->postJson(route('checkout.store'), capturePayload($page, ['gift_wrap' => '1']))
        ->assertCreated();

    expect(Order::orderBy('id')->get()
        ->map(fn (Order $order): array => [$order->answers[0]->value, $order->answers[0]->display])
        ->all())
        ->toBe([[false, 'No'], [true, 'Yes']]);
});

it('refuses an order that leaves a compulsory box unticked', function () {
    $page = sellingPage(Product::factory()->create());

    $page = checkoutAlsoAsking($page, [
        question('terms', 'checkbox', ['label' => 'I agree to the terms', 'required' => true]),
    ]);

    /**
     * `required` would pass for a box the customer refused, because an unticked
     * one sends nothing and an absent value is what a missing answer looks like
     * too. A compulsory box has to be ticked, not merely present.
     */
    $this->postJson(route('checkout.store'), capturePayload($page))
        ->assertUnprocessable()
        ->assertJsonPath('errors.terms.0', 'Please tick this to continue.');

    $this->postJson(route('checkout.store'), capturePayload($page, ['terms' => '1']))
        ->assertCreated();
});

it('refuses an answer that is not a number to a question asked as one', function () {
    $page = sellingPage(Product::factory()->create());

    $page = checkoutAlsoAsking($page, [question('floor', 'number', ['label' => 'Which floor?'])]);

    $this->postJson(route('checkout.store'), capturePayload($page, ['floor' => 'the top one']))
        ->assertUnprocessable()
        ->assertJsonPath('errors.floor.0', 'Please answer with a number.');

    $this->postJson(route('checkout.store'), capturePayload($page, ['floor' => '4']))
        ->assertCreated();

    expect(Order::sole()->answers[0]->value)->toBe('4');
});

it('drops a question the merchant added that nothing could answer', function (array $stored) {
    $page = sellingPage(Product::factory()->create());

    $page = checkoutAlsoAsking($page, [$stored]);

    /**
     * The shape only a Config written by some other route could hold: every one
     * of these is refused on the Checkout tab. Dropped rather than thrown over,
     * because a landing page answering paid traffic with a 500 costs more than
     * one asking a merchant a question fewer than they meant.
     */
    expect(array_map(fn (CheckoutField $field): string => $field->key(), $page->checkout_config->fields))
        ->toBe(['name', 'phone', 'email', 'district', 'address']);

    $this->get('/promo')->assertOk();
})->with([
    'a choice between nothing' => [['key' => 'colour', 'label' => 'Colour', 'type' => 'select', 'options' => []]],
    'a control nothing renders' => [['key' => 'colour', 'label' => 'Colour', 'type' => 'colour-wheel']],
    'a key a built-in holds' => [['key' => 'phone', 'label' => 'Second phone', 'type' => 'text']],
    'a key the order form uses' => [['key' => 'quantities', 'label' => 'How many', 'type' => 'number']],
    'the key the pricing hash arrives under' => [['key' => 'fingerprint', 'label' => 'Fingerprint', 'type' => 'text']],
    'a key no input could carry' => [['key' => 'gift note!', 'label' => 'Gift note', 'type' => 'text']],
]);

it('takes no question of the merchant that would arrive in place of the pricing hash', function () {
    $page = sellingPage(Product::factory()->create());

    $page = checkoutAlsoAsking($page, [['key' => 'fingerprint', 'label' => 'Fingerprint', 'type' => 'text']]);

    /**
     * Two inputs of a name arrive as the last of the pair, so this question
     * would post what the customer typed where the hash the form was drawn
     * with is read from. Nothing would say so: every order on a page that is
     * spending money would be turned away as stale, and the merchant would
     * see a conversion rate at zero rather than an error.
     */
    expect(substr_count($this->get('/promo')->assertOk()->getContent(), 'name="fingerprint"'))->toBe(1);

    $this->postJson(route('checkout.store'), capturePayload($page))->assertCreated();
});

it('keeps every built-in whichever side of one a stray row claiming its key sits', function () {
    $page = sellingPage(Product::factory()->create());

    /**
     * A row claiming `phone` before the built-in's own, which is the shape only
     * a Config written by some other route could hold. Whichever of the two is
     * read, a phone is still asked, still checked as a phone and still written
     * to the column waiting for it — a Checkout cannot lose a built-in or
     * retype one however its Config was written.
     */
    $page = checkoutOf($page, [
        question('phone', 'checkbox', ['label' => 'A phone that is not one']),
        ...$page->checkout_config->fieldsToArray(),
    ]);

    $phone = collect($page->checkout_config->fields)
        ->firstWhere(fn (CheckoutField $field): bool => $field->key() === 'phone');

    expect(array_map(fn (CheckoutField $field): string => $field->key(), $page->checkout_config->fields))
        ->toBe(['phone', 'name', 'email', 'district', 'address'])
        ->and($phone->type())->toBe(CheckoutFieldType::Text)
        ->and($phone->snapshotsAnswer())->toBeFalse();

    $this->postJson(route('checkout.store'), capturePayload($page, ['phone' => '12']))
        ->assertUnprocessable()
        ->assertJsonPath('errors.phone.0', 'Please enter a valid phone number we can call you on.');

    $this->postJson(route('checkout.store'), capturePayload($page))->assertCreated();

    expect(Order::sole()->customer_phone)->toBe('+8801712345678')
        ->and(Order::sole()->answers)->toBe([]);
});

it('takes no answer to a question the page being ordered from does not ask', function () {
    $product = Product::factory()->create();

    $asks = checkoutAlsoAsking(sellingPage($product, 'asks'), [
        question('gift_note', 'text', ['label' => 'Gift note', 'required' => true]),
    ]);

    $doesNotAsk = sellingPage($product, 'does-not');

    /**
     * A Checkout Config is self-contained and per Page (ADR-0008), and the
     * questions in it are no exception: one payload, two pages, two answers.
     */
    $this->postJson(route('checkout.store'), capturePayload($asks, ['gift_note' => '']))
        ->assertUnprocessable()
        ->assertJsonPath('errors.gift_note.0', 'Please answer this question.');

    $this->postJson(route('checkout.store'), capturePayload($doesNotAsk, ['gift_note' => 'Happy birthday']))
        ->assertCreated();

    expect(Order::sole()->answers)->toBe([]);
});

/**
 * The attributes one control of the rendered form carries, read back out of the
 * HTML the visitor was served.
 *
 * This is what makes the composed tests below say anything: the browser's half
 * of a rule is never restated in the test, it is taken out of the form and
 * posted against.
 *
 * @return array<string, string>
 */
function controlOf(string $html, string $key): array
{
    $control = '/<(?:input|textarea)[^>]*name="'.preg_quote($key, '/').'"[^>]*>/';

    expect($html)->toMatch($control);

    preg_match($control, $html, $matched);
    preg_match_all('/([a-z]+)="([^"]*)"/', $matched[0], $attributes, PREG_SET_ORDER);

    return array_column($attributes, 2, 1);
}

it('tells the browser the lengths and the shape the merchant chose', function () {
    $page = sellingPage(Product::factory()->create());

    checkoutAlsoAsking($page, [
        question('nickname', 'text', ['constraints' => [
            'min_length' => 3,
            'max_length' => 40,
            'pattern' => 'letters',
        ]]),
    ]);

    expect(controlOf($this->get('/promo')->assertOk()->getContent(), 'nickname'))
        ->toHaveKey('minlength', '3')
        ->toHaveKey('maxlength', '40')
        /** The vocabulary's own expression, never one the merchant wrote. */
        ->toHaveKey('pattern', FieldPattern::Letters->attribute());
});

it('tells the browser the bounds and the stepping a number question was given', function () {
    $page = sellingPage(Product::factory()->create());

    checkoutAlsoAsking($page, [
        question('floor', 'number', ['constraints' => [
            'min' => '1',
            'max' => '20',
            'whole_numbers' => true,
        ]]),
    ]);

    expect(controlOf($this->get('/promo')->assertOk()->getContent(), 'floor'))
        ->toHaveKey('min', '1')
        ->toHaveKey('max', '20')
        /** A question that takes no decimal point does not open one on a phone. */
        ->toHaveKey('step', '1')
        ->toHaveKey('inputmode', 'numeric');
});

it('refuses at capture exactly what the rendered form told the browser to refuse', function (array $constraints, string $type, string $attribute, callable $blocked) {
    $page = sellingPage(Product::factory()->create());

    $page = checkoutAlsoAsking($page, [question('detail', $type, ['constraints' => $constraints])]);

    /**
     * The only place the "one definition, two renderings" property is
     * observable at all: the figure is read out of the form the visitor was
     * served, and an answer that figure would have blocked is then posted
     * straight past the browser.
     */
    $control = controlOf($this->get('/promo')->assertOk()->getContent(), 'detail');

    $this->postJson(route('checkout.store'), capturePayload($page, [
        'detail' => $blocked($control[$attribute]),
    ]))->assertUnprocessable()->assertJsonStructure(['errors' => ['detail']]);

    expect(Order::count())->toBe(0);
})->with([
    'longer than the longest' => [
        ['max_length' => 20], 'text', 'maxlength',
        fn (string $maxlength): string => str_repeat('a', (int) $maxlength + 1),
    ],
    'shorter than the shortest' => [
        ['min_length' => 8], 'text', 'minlength',
        fn (string $minlength): string => str_repeat('a', (int) $minlength - 1),
    ],
    'the wrong shape' => [
        ['pattern' => 'digits'], 'text', 'pattern',
        fn (): string => 'the fourth floor',
    ],
    'below the smallest' => [
        ['min' => '1'], 'number', 'min',
        fn (string $min): string => (string) ((int) $min - 1),
    ],
    'above the largest' => [
        ['max' => '20'], 'number', 'max',
        fn (string $max): string => (string) ((int) $max + 1),
    ],
    'not a whole number' => [
        ['whole_numbers' => true], 'number', 'step',
        fn (): string => '2.5',
    ],
]);

it('takes the answer the merchant said it would', function (string $type, array $constraints, string $answer) {
    $page = sellingPage(Product::factory()->create());

    $page = checkoutAlsoAsking($page, [question('detail', $type, ['constraints' => $constraints])]);

    $this->postJson(route('checkout.store'), capturePayload($page, ['detail' => $answer]))
        ->assertCreated();

    expect(Order::sole()->answers[0]->value)->toBe($answer);
})->with([
    'writing of the right length' => ['text', ['min_length' => 3, 'max_length' => 40], 'Rahim'],
    'writing of the right shape' => ['text', ['pattern' => 'letters'], 'Nur-e-Alam'],
    /** The ordinary case here, not the exception: a shape that refused it would be refusing customers. */
    'a name written in bangla' => ['text', ['pattern' => 'letters'], 'রহিম উদ্দিন'],
    'digits where digits were asked for' => ['text', ['pattern' => 'digits'], '01712345678'],
    'a number inside its bounds' => ['number', ['min' => '1', 'max' => '20'], '14'],
    'a whole number where one was asked for' => ['number', ['whole_numbers' => true], '4'],
    'a paragraph of the right length' => ['textarea', ['min_length' => 5, 'max_length' => 500], 'Leave it with the guard.'],
]);

it('words a refusal by what the merchant bounded', function (string $type, array $constraints, string $answer, string $message) {
    $page = sellingPage(Product::factory()->create());

    $page = checkoutAlsoAsking($page, [question('detail', $type, ['constraints' => $constraints])]);

    /**
     * A customer mid-purchase on a phone needs the next move rather than a
     * diagnosis, and a longest on writing means something different from a
     * largest on a number — telling them the wrong one of those is worse than
     * telling them nothing.
     */
    $this->postJson(route('checkout.store'), capturePayload($page, ['detail' => $answer]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.detail.0', $message);
})->with([
    'too short' => ['text', ['min_length' => 8], 'four', 'Please write at least 8 characters.'],
    'too long' => ['text', ['max_length' => 4], 'far too long', 'That answer is longer than we can take.'],
    'not letters' => ['text', ['pattern' => 'letters'], 'Flat 4B', 'Please answer with letters only.'],
    'not digits' => ['text', ['pattern' => 'digits'], 'Flat 4B', 'Please answer with digits only.'],
    'punctuated' => ['text', ['pattern' => 'letters_and_digits'], 'Flat 4B, ring twice', 'Please answer with letters and digits only.'],
    'below the smallest' => ['number', ['min' => '2'], '1', 'Please answer with 2 or more.'],
    'above the largest' => ['number', ['max' => '20'], '21', 'Please answer with 20 or less.'],
    'not whole' => ['number', ['whole_numbers' => true], '2.5', 'Please answer with a whole number.'],
]);

it('checks a paragraph against its shape even where the browser has no attribute for one', function () {
    $page = sellingPage(Product::factory()->create());

    $page = checkoutAlsoAsking($page, [question('directions', 'textarea', ['constraints' => ['pattern' => 'letters']])]);

    /**
     * HTML has no `pattern` on a textarea, so the asymmetry is the browser
     * stopping nothing rather than stopping the wrong thing. The answer is
     * still refused at capture, against the question that earned it.
     */
    expect(controlOf($this->get('/promo')->assertOk()->getContent(), 'directions'))
        ->not->toHaveKey('pattern');

    $this->postJson(route('checkout.store'), capturePayload($page, ['directions' => 'Flat 4B']))
        ->assertUnprocessable()
        ->assertJsonPath('errors.directions.0', 'Please answer with letters only.');
});

it('caps a written answer at what the control will hold however the config was written', function (string $type, int $ceiling) {
    $page = sellingPage(Product::factory()->create());

    /**
     * A longest above the ceiling is refused on the Checkout tab, so it can
     * only arrive from some other route. Capped rather than honoured: the
     * figure the attribute carries and the figure the rule enforces are one,
     * and neither may promise more than the answer can be stored in.
     */
    $page = checkoutAlsoAsking($page, [
        question('detail', $type, ['constraints' => ['max_length' => 9000]]),
    ]);

    expect(controlOf($this->get('/promo')->assertOk()->getContent(), 'detail'))
        ->toHaveKey('maxlength', (string) $ceiling);

    $this->postJson(route('checkout.store'), capturePayload($page, [
        'detail' => str_repeat('a', $ceiling + 1),
    ]))->assertUnprocessable()->assertJsonPath('errors.detail.0', 'That answer is longer than we can take.');

    $this->postJson(route('checkout.store'), capturePayload($page, [
        'detail' => str_repeat('a', $ceiling),
    ]))->assertCreated();
})->with([
    'a single line' => ['text', 255],
    'a paragraph' => ['textarea', 1000],
]);

it('caps a written answer even where the merchant bounded nothing', function () {
    $page = sellingPage(Product::factory()->create());

    $page = checkoutAlsoAsking($page, [question('gift_note')]);

    expect(controlOf($this->get('/promo')->assertOk()->getContent(), 'gift_note'))
        ->toHaveKey('maxlength', '255');

    $this->postJson(route('checkout.store'), capturePayload($page, [
        'gift_note' => str_repeat('a', 256),
    ]))->assertUnprocessable();
});

it('takes no rule the merchant wrote themselves however the config was written', function (array $constraints) {
    $page = sellingPage(Product::factory()->create());

    $page = checkoutAlsoAsking($page, [question('gift_note', 'text', ['constraints' => $constraints])]);

    /**
     * The vocabulary is closed. An expression, a rule string, or a shape by a
     * name nothing knows is dropped on the way in rather than compiled and run
     * on a public endpoint the merchant could never have tested against.
     */
    expect(controlOf($this->get('/promo')->assertOk()->getContent(), 'gift_note'))
        ->not->toHaveKey('pattern');

    $this->postJson(route('checkout.store'), capturePayload($page, [
        'gift_note' => 'Anything at all, 4B, ring twice',
    ]))->assertCreated();
})->with([
    'an expression of their own' => [['pattern' => '^[a-z]+$']],
    'a rule string' => [['pattern' => 'regex:/^[a-z]+$/']],
    'a shape by a name nothing knows' => [['pattern' => 'postcode']],
    'a rule under a key nothing reads' => [['rules' => ['regex:/^[a-z]+$/'], 'regex' => '.*']],
]);

it('drops a bound nothing could satisfy rather than asking the impossible', function () {
    $page = sellingPage(Product::factory()->create());

    /**
     * The pair the Checkout tab refuses outright, so it can only arrive from
     * some other route. The choice there is between a question nobody can
     * answer — a form that cannot be submitted at all, on a page spending money
     * — and one that takes a little more than was intended.
     */
    $page = checkoutAlsoAsking($page, [
        question('nickname', 'text', ['constraints' => ['min_length' => 40, 'max_length' => 10]]),
        question('floor', 'number', ['constraints' => ['min' => '20', 'max' => '2']]),
    ]);

    $html = $this->get('/promo')->assertOk()->getContent();

    expect(controlOf($html, 'nickname'))
        ->not->toHaveKey('minlength')
        ->toHaveKey('maxlength', '10')
        ->and(controlOf($html, 'floor'))
        ->not->toHaveKey('min')
        ->toHaveKey('max', '2');

    $this->postJson(route('checkout.store'), capturePayload($page, [
        'nickname' => 'Rahim',
        'floor' => '1',
    ]))->assertCreated();
});

it('drops a bound belonging to a control the question is no longer asked with', function () {
    $page = sellingPage(Product::factory()->create());

    /**
     * What a merchant leaves behind by bounding a question and then changing
     * how it is asked. A length counts characters and a smallest compares
     * figures; neither means anything on the other, and enforcing one against
     * the wrong control refuses answers for a reason nothing on screen gave.
     */
    $page = checkoutAlsoAsking($page, [
        question('floor', 'number', ['constraints' => ['min_length' => 8, 'pattern' => 'letters']]),
        question('nickname', 'text', ['constraints' => ['min' => '100', 'whole_numbers' => true]]),
        question('colour', 'select', [
            'options' => [['value' => 'sky', 'label' => 'Sky blue']],
            'constraints' => ['min_length' => 8, 'max' => '2'],
        ]),
    ]);

    $html = $this->get('/promo')->assertOk()->getContent();

    expect(controlOf($html, 'floor'))
        ->not->toHaveKey('minlength')
        ->not->toHaveKey('pattern')
        ->and(controlOf($html, 'nickname'))
        ->not->toHaveKey('min')
        ->not->toHaveKey('step');

    $this->postJson(route('checkout.store'), capturePayload($page, [
        'floor' => '4',
        'nickname' => 'Rahim',
        'colour' => 'sky',
    ]))->assertCreated();
});

it('leaves a field the system knows by name bounded by its own column', function () {
    $page = sellingPage(Product::factory()->create());

    /**
     * A built-in takes its wording and its two flags from a Config and nothing
     * else (ADR-0006): what its column will hold is the built-in's to say, so a
     * constraint written onto its row by some other route decides nothing.
     */
    $page = checkoutOf($page, array_map(
        fn (array $field): array => [...$field, 'constraints' => ['min_length' => 200, 'pattern' => 'digits']],
        $page->checkout_config->fieldsToArray(),
    ));

    expect(controlOf($this->get('/promo')->assertOk()->getContent(), 'name'))
        ->not->toHaveKey('minlength')
        ->not->toHaveKey('pattern')
        /** Its own ceiling, exactly as it always was. */
        ->toHaveKey('maxlength', '255');

    $this->postJson(route('checkout.store'), capturePayload($page))->assertCreated();
});

it('captures a complete order from a checkout that draws no products', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket', 'price' => 99000]);
    $scarf = Product::factory()->create(['name' => 'Wool Scarf', 'price' => 25000]);

    $page = sellingPage([$jacket, $scarf]);
    $page->update(['checkout_config' => CheckoutConfig::fromArray(['layout' => 'form_only'])]);

    $response = $this->postJson(route('checkout.store'), capturePayload($page));

    $response->assertCreated();

    /**
     * The panel is what a form-only layout drops, never the Order. Everything
     * the Page sells is on it, at the Page's own prices, with delivery charged
     * exactly as it would be from the stacked form — a layout decides what is
     * drawn and nothing about what is collected at the door.
     */
    $order = Order::sole();

    expect($order->lines)->toHaveCount(2)
        ->and($order->subtotal)->toBe(124000)
        ->and($order->delivery_charge)->toBe(6000)
        ->and($order->total)->toBe(130000);
});

it('charges for what the customer sent and not for what the form opened ticked', function () {
    $jacket = Product::factory()->create(['price' => 99000]);
    $scarf = Product::factory()->create(['price' => 25000]);

    $page = picking(sellingPage([$jacket, $scarf]));

    $page->update(['checkout_config' => CheckoutConfig::fromArray([
        ...$page->checkout_config->toArray(),
        'default_selection' => [$jacket->getKey(), $scarf->getKey()],
    ])]);

    /**
     * A pre-ticked box is a starting position and never an agreement: the
     * customer unticked the scarf before ordering, so the scarf is not sold
     * and not charged for. What an Order costs is settled by what was ticked
     * at Capture, which is the whole of why a Default Selection may be as
     * bold as a merchant likes.
     */
    $this->postJson(route('checkout.store'), capturePayload($page->refresh(), [
        'selected' => [$jacket->getKey()],
    ]))->assertCreated();

    $order = Order::sole();

    expect($order->lines)->toHaveCount(1)
        ->and($order->lines()->sole()->product_id)->toBe($jacket->getKey())
        ->and($order->subtotal)->toBe(99000);
});

it('leaves the fingerprint alone when the products it opens ticked change', function () {
    $jacket = Product::factory()->create(['price' => 99000]);
    $scarf = Product::factory()->create(['price' => 25000]);

    $page = picking(sellingPage([$jacket, $scarf]));

    $before = PricingFingerprint::for($page);

    /** Written against the page as the visitor's screen has it, before the merchant changes their mind. */
    $payload = capturePayload($page, ['selected' => [$jacket->getKey()]]);

    $page->update(['checkout_config' => CheckoutConfig::fromArray([
        ...$page->checkout_config->toArray(),
        'default_selection' => [$jacket->getKey(), $scarf->getKey()],
    ])]);

    $page = $page->refresh();

    /**
     * Which box opens ticked is configuration and not a figure handed over at
     * the door. Fingerprinting it would turn away everyone mid-form every time
     * a merchant changed their mind about a default, on traffic they are
     * paying for, and the refusal would read to the customer exactly like a
     * price change (ADR-0010).
     */
    expect(PricingFingerprint::for($page))->toBe($before)
        ->and($page->checkout_config->defaultSelection)->toBe([$jacket->getKey(), $scarf->getKey()]);

    $this->postJson(route('checkout.store'), $payload)->assertCreated();

    expect(Order::sole()->subtotal)->toBe(99000);
});

it('sells everything a form-only page offers however its picker is set', function () {
    $jacket = Product::factory()->create(['price' => 99000]);
    $scarf = Product::factory()->create(['price' => 25000]);

    $page = sellingPage([$jacket, $scarf]);
    $page->update(['checkout_config' => CheckoutConfig::fromArray([
        'layout' => 'form_only',
        'product_picker' => true,
    ])]);

    /**
     * The form drew no checkboxes, so the submission names nothing ticked. A
     * picker left standing on a layout that cannot draw one would refuse this
     * for having chosen nothing — every order on a live page turned away over a
     * box no customer was ever shown. The layout is read here for exactly that
     * reason.
     */
    $this->postJson(route('checkout.store'), capturePayload($page))->assertCreated();

    $order = Order::sole();

    expect($order->lines)->toHaveCount(2)
        ->and($order->subtotal)->toBe(124000);
});

/**
 * The fingerprint a rendered form actually carries, read back out of the HTML
 * a visitor was served.
 *
 * Every other test here builds a payload from the Page. This reads it from the
 * bytes, which is the only way the form and the check can be shown to agree
 * rather than merely be written to.
 */
function renderedFingerprint(string $html): string
{
    preg_match('/name="fingerprint" value="([^"]+)"/', $html, $carried);

    return $carried[1] ?? '';
}

it('captures an order from the fingerprint the rendered form carries', function () {
    $page = deliveringBy(sellingPage(Product::factory()->create(['price' => 99000])), [
        ['label' => 'Inside Dhaka', 'amount' => 6000],
    ]);

    $html = $this->get('/promo')->assertOk()->getContent();

    /**
     * The one place the form and the server can be shown to agree about what
     * the courier collects: these bytes went to a visitor, and this is the
     * value their browser would post back.
     */
    $this->postJson(route('checkout.store'), capturePayload($page, [
        'fingerprint' => renderedFingerprint($html),
    ]))->assertCreated();

    expect(Order::sole()->total)->toBe(105000);
});

it('refuses a submission that carries no fingerprint at all', function () {
    $page = sellingPage(Product::factory()->create());

    $payload = capturePayload($page);
    unset($payload['fingerprint']);

    /**
     * Which is what every page cached before this check existed will post, and
     * what a hand-made post that never looked at the form sends. Neither can be
     * shown to have agreed to anything, so neither is captured.
     */
    $this->postJson(route('checkout.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonPath('errors.page.0', 'This page has changed since you opened it. Reload it to see the current prices, then order again.');

    expect(Order::count())->toBe(0);
});

it('refuses a submission drawn before the product was repriced', function () {
    $product = Product::factory()->create(['price' => 99000]);
    $page = sellingPage($product);

    /** Written to disk while the page still said 990. */
    $payload = capturePayload($page);

    $product->update(['price' => 129000]);

    /**
     * Capturing this would be a courier collecting 1,290 at a door where the
     * customer agreed to 990 — a refused parcel, a return fee on the whole
     * order, and the defect this fingerprint exists to close (ADR-0007).
     */
    $this->postJson(route('checkout.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonPath('errors.page.0', 'This page has changed since you opened it. Reload it to see the current prices, then order again.');

    expect(Order::count())->toBe(0);
});

it('refuses a submission drawn before the delivery amount was changed', function () {
    $page = deliveringBy(sellingPage(Product::factory()->create(['price' => 99000])), [
        ['label' => 'Inside Dhaka', 'amount' => 6000],
        ['label' => 'Outside Dhaka', 'amount' => 12000],
    ]);

    $payload = capturePayload($page, ['delivery_option' => 'Outside Dhaka']);

    /** Renamed nothing, so the choice still resolves; only the figure moved. */
    deliveringBy($page, [
        ['label' => 'Inside Dhaka', 'amount' => 6000],
        ['label' => 'Outside Dhaka', 'amount' => 20000],
    ]);

    $this->postJson(route('checkout.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonPath('errors.page.0', 'This page has changed since you opened it. Reload it to see the current prices, then order again.');

    expect(Order::count())->toBe(0);
});

it('refuses a submission drawn before two delivery amounts were swapped', function () {
    $page = deliveringBy(sellingPage(Product::factory()->create(['price' => 99000])), [
        ['label' => 'Inside Dhaka', 'amount' => 6000],
        ['label' => 'Outside Dhaka', 'amount' => 12000],
    ]);

    $payload = capturePayload($page, ['delivery_option' => 'Inside Dhaka']);

    /**
     * The same two figures, against the other arrangements. Both names still
     * resolve, and the set of amounts the page charges has not moved — only
     * which arrangement carries which. A customer who agreed to 60 inside Dhaka
     * would be collected 120 at the door.
     *
     * This is why the delivery amounts are fingerprinted by position while the
     * products are fingerprinted by id: without the labels, which ADR-0007
     * keeps out, position is the only thing tying an amount to the arrangement
     * it belongs to.
     */
    deliveringBy($page, [
        ['label' => 'Inside Dhaka', 'amount' => 12000],
        ['label' => 'Outside Dhaka', 'amount' => 6000],
    ]);

    $this->postJson(route('checkout.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonPath('errors.page.0', 'This page has changed since you opened it. Reload it to see the current prices, then order again.');

    expect(Order::count())->toBe(0);
});

it('takes an order from a form drawn before the questions were edited', function () {
    $page = sellingPage(Product::factory()->create(['price' => 99000]));

    $before = PricingFingerprint::for($page);
    $payload = capturePayload($page);

    /**
     * Everything a merchant actually does to a live page at 9pm: a question
     * added, one reworded, one switched off, and the whole thing repainted.
     * None of it changes what the courier collects.
     */
    $page = checkoutAlsoAsking($page, [question('gift_note', 'text', ['required' => false])]);

    $page = checkoutAsking($page, [
        'name' => ['label' => 'Your full name'],
        'district' => ['enabled' => false],
    ]);

    $page->update(['checkout_config' => CheckoutConfig::fromArray([
        ...$page->checkout_config->toArray(),
        'style_tokens' => ['accent' => '#ff0000'],
    ])]);

    $page = $page->refresh();

    /**
     * Rejecting here would mean every save on the Checkout tab turned away the
     * orders of everyone mid-form on traffic the merchant is paying for, and
     * the refusal would read to the customer exactly like a price change. The
     * blast radius has to bear some relation to the harm (ADR-0007).
     */
    expect(PricingFingerprint::for($page))->toBe($before);

    $this->postJson(route('checkout.store'), $payload)->assertCreated();

    /**
     * Captured whole, and the answer to the question the merchant switched off
     * simply dropped — the payload still carried a district, because the form
     * on the visitor's screen still had a box for one.
     */
    $order = Order::sole();

    expect($order->total)->toBe(105000)
        ->and($order->customer_district)->toBeNull();
});

it('leaves the fingerprint alone when the products are only rearranged', function () {
    $jacket = Product::factory()->create(['price' => 99000]);
    $boots = Product::factory()->create(['price' => 149000]);

    $page = sellingPage([$jacket, $boots]);

    $before = PricingFingerprint::for($page);
    $payload = capturePayload($page);

    /**
     * A reorder in the admin changes which product is drawn first and nothing
     * about what is collected, and nothing resolves a product by the position
     * it sits at. Refusing over it would cost orders for a merchandising tidy-up.
     */
    $page->products()->sync([
        $jacket->getKey() => ['position' => 1],
        $boots->getKey() => ['position' => 0],
    ]);

    $page = $page->refresh();

    expect(PricingFingerprint::for($page))->toBe($before);

    $this->postJson(route('checkout.store'), $payload)->assertCreated();

    expect(Order::sole()->subtotal)->toBe(248000);
});

it('leaves the fingerprint alone when the order button is reworded', function () {
    $jacket = Product::factory()->create(['price' => 99000]);
    $page = sellingPage($jacket);

    $before = PricingFingerprint::for($page);

    /** Written against the page as the visitor's screen has it, before the merchant edits the copy. */
    $payload = capturePayload($page);

    $page->update(['checkout_config' => CheckoutConfig::fromArray([
        ...$page->checkout_config->toArray(),
        'order_button' => ['wording' => 'Order for {total}', 'note' => 'You pay the courier.'],
    ])]);

    $page = $page->refresh();

    /**
     * What the button says is configuration and not a figure handed over at
     * the door. Fingerprinting it would turn away everyone mid-form every time
     * a merchant edited a word on a live page, on traffic they are paying for.
     */
    expect(PricingFingerprint::for($page))->toBe($before)
        ->and($page->checkout_config->orderButton->wording)->toBe('Order for {total}')
        ->and($page->checkout_config->orderButton->note)->toBe('You pay the courier.');

    $this->postJson(route('checkout.store'), $payload)->assertCreated();

    expect(Order::sole()->subtotal)->toBe(99000);
});

it('places an order under cash on delivery when the form posts no method', function () {
    $page = sellingPage(Product::factory()->create());

    /**
     * A page offering nothing but cash on delivery with the choice hidden
     * draws no chooser, so its submission carries no method — and so does a
     * plain post that lost the radio. Both land where the form would have
     * opened.
     */
    $this->postJson(route('checkout.store'), capturePayload($page))->assertCreated();

    expect(Order::sole()->payment_method)->toBe(PaymentMethod::CashOnDelivery);
});

it('records the method a customer chose from the chooser', function () {
    $page = payingBy(sellingPage(Product::factory()->create()), ['cash_on_delivery'], hideLoneCash: false);

    $this->postJson(route('checkout.store'), capturePayload($page, [
        'payment_method' => 'cash_on_delivery',
    ]))->assertCreated();

    expect(Order::sole()->payment_method)->toBe(PaymentMethod::CashOnDelivery);
});

it('refuses a name nothing knows and captures nothing', function () {
    $page = sellingPage(Product::factory()->create());

    /**
     * Nothing on screen offers a name that is not a method at all: a
     * hand-made post, refused beside the chooser rather than placed under
     * whatever the payload claims.
     */
    $this->postJson(route('checkout.store'), capturePayload($page, ['payment_method' => 'gold-bars']))
        ->assertUnprocessable()
        ->assertJsonPath('errors.payment_method.0', 'Please choose one of the payment methods listed.');

    expect(Order::count())->toBe(0);
});

it('captures a known method the page does not offer as unpaid rather than refusing it', function () {
    $page = sellingPage(Product::factory()->create());

    /**
     * A known name the page does not offer now cannot be told from one it
     * offered when the form was drawn — a Merchant edits the payment tab
     * under live forms — so it is taken and left Unpaid, with no Payment,
     * and the Customer lands on the Retry Page (ADR-0013).
     */
    $this->postJson(route('checkout.store'), capturePayload($page, ['payment_method' => 'bkash']))
        ->assertCreated()
        ->assertJsonPath('redirect', route('checkout.retry', Order::sole()->order_number));

    expect(Order::sole()->payment_method)->toBe(PaymentMethod::Bkash)
        ->and(Order::sole()->isUnpaid())->toBeTrue()
        ->and(Order::sole()->payments()->count())->toBe(0);
});

it('reads a config written before a method could be chosen as cash on delivery alone', function () {
    $page = sellingPage(Product::factory()->create());

    /** The stored shape of every page that exists today: no payment keys at all. */
    $stored = $page->checkout_config->toArray();
    unset($stored['payment_methods'], $stored['show_payment_logos'], $stored['hide_lone_cash_choice']);

    $page->update(['checkout_config' => CheckoutConfig::fromArray($stored)]);
    $config = $page->refresh()->checkout_config;

    expect($config->paymentMethods)->toBe([PaymentMethod::CashOnDelivery])
        ->and($config->offeredMethods())->toBe([PaymentMethod::CashOnDelivery])
        ->and($config->showPaymentLogos)->toBeTrue()
        ->and($config->hideLoneCashChoice)->toBeTrue()
        ->and($config->paymentChooser()->isDrawn())->toBeFalse();

    $this->postJson(route('checkout.store'), capturePayload($page))->assertCreated();

    expect(Order::sole()->payment_method)->toBe(PaymentMethod::CashOnDelivery);
});

it('offers cash on delivery whatever a stored list holds that cannot be offered', function (array $stored, array $kept) {
    $page = payingBy(sellingPage(Product::factory()->create()), $stored);
    $config = $page->checkout_config;

    /**
     * The raw list is kept whole — minus what is not a name at all — so a
     * method switched on later reappears where the merchant put it; the
     * offered reading drops what cannot take money and falls back to cash so
     * the page always has one way to order.
     */
    expect(PaymentMethod::names($config->paymentMethods))->toBe($kept)
        ->and($config->offeredMethods())->toBe([PaymentMethod::CashOnDelivery]);

    $this->postJson(route('checkout.store'), capturePayload($page))->assertCreated();

    expect(Order::sole()->payment_method)->toBe(PaymentMethod::CashOnDelivery);
})->with([
    'an empty list' => [[], []],
    'online methods only' => [['bkash', 'sslcommerz'], ['bkash', 'sslcommerz']],
    'a name nothing knows, twice, beside a real one' => [['gold-bars', 'cash_on_delivery', 'cash_on_delivery', 42], ['cash_on_delivery']],
]);

it('leaves the fingerprint alone when the payment settings change', function () {
    $jacket = Product::factory()->create(['price' => 99000]);
    $page = sellingPage($jacket);

    $before = PricingFingerprint::for($page);

    /** Written against the page as the visitor's screen has it, before the merchant edits the payment section. */
    $payload = capturePayload($page);

    $page = payingBy($page, ['cash_on_delivery'], showLogos: false, hideLoneCash: false);

    /**
     * Which methods are listed, in what order and with what beside them is
     * configuration, not a figure handed over at the door: the total is the
     * same whichever method is picked. Fingerprinting it would turn away
     * everyone mid-form every time a merchant reordered the list (ADR-0013).
     */
    expect(PricingFingerprint::for($page))->toBe($before);

    $this->postJson(route('checkout.store'), $payload)->assertCreated();

    expect(Order::sole()->total)->toBe(105000);
});

it('says on the order complete page how the order will be paid', function () {
    $page = sellingPage(Product::factory()->create());

    $this->postJson(route('checkout.store'), capturePayload($page))->assertCreated();

    $this->get(route('checkout.order-complete', Order::sole()->order_number))
        ->assertOk()
        ->assertSee('Cash on Delivery');
});

it('places a plain form post carrying a method under that method', function () {
    $page = payingBy(sellingPage(Product::factory()->create()), ['cash_on_delivery'], hideLoneCash: false);

    /** The chooser is a real radio in a real form, so a visitor whose script never runs can still say how they will pay. */
    $this->post(route('checkout.store'), capturePayload($page, ['payment_method' => 'cash_on_delivery']))
        ->assertStatus(303);

    expect(Order::sole()->payment_method)->toBe(PaymentMethod::CashOnDelivery);
});
