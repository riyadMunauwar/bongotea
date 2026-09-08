<?php

use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Page;
use App\Models\Product;
use App\Support\Money;
use App\Support\Settings\PaymentSettings;
use Spatie\Activitylog\Models\Activity;

/**
 * Make SSLCommerz usable for the installation the way a Merchant would: on,
 * with both credentials filled, under the default BDT currency.
 */
function offeringSslCommerz(): void
{
    app(PaymentSettings::class)->with([
        'sslcommerzEnabled' => true,
        'sslcommerzStoreId' => 'store_live_1',
        'sslcommerzStorePassword' => 'pw_live_1',
    ])->save();
}

/**
 * A published Page offering SSLCommerz first and cash on delivery second,
 * with the chooser drawn.
 */
function pageOfferingSslCommerz(): Page
{
    offeringSslCommerz();

    return payingBy(sellingPage(Product::factory()->create(['price' => 99000])), ['sslcommerz', 'cash_on_delivery']);
}

/**
 * An Order captured under SSLCommerz, which no driver can start a Payment for
 * yet: Unpaid from the moment it exists.
 */
function unpaidOrder(?Page $page = null): Order
{
    $page ??= pageOfferingSslCommerz();

    test()->postJson(route('checkout.store'), capturePayload($page, ['payment_method' => 'sslcommerz']))->assertCreated();

    return Order::sole();
}

it('draws the chooser with a usable online method first, as the page lists it', function () {
    $page = pageOfferingSslCommerz();

    $html = $this->get('/promo')->assertOk()->getContent();

    expect($html)->toContain('name="payment_method"')
        ->and(strpos($html, 'gio-checkout-choice-label">SSLCommerz<'))->toBeLessThan(strpos($html, 'gio-checkout-choice-label">Cash on Delivery<'))
        ->and($html)->toContain('value="sslcommerz"')
        ->and($page->checkout_config->offeredMethods())->toBe([PaymentMethod::SslCommerz, PaymentMethod::CashOnDelivery]);
});

it('captures an order under a usable online method and answers with the retry page, because no payment can start yet', function () {
    $page = pageOfferingSslCommerz();

    $response = $this->postJson(route('checkout.store'), capturePayload($page, ['payment_method' => 'sslcommerz']))
        ->assertCreated();

    $order = Order::sole();

    /** Captured exactly as a cash order is — the figures, the lines, the details — and Unpaid rather than turned away (ADR-0013). */
    expect($order->payment_method)->toBe(PaymentMethod::SslCommerz)
        ->and($order->isUnpaid())->toBeTrue()
        ->and($order->total)->toBe(105000)
        ->and($order->customer_name)->toBe('Rahim Uddin');

    $response->assertJsonPath('redirect', route('checkout.retry', $order->order_number));
});

it('sends a plain form post under an online method to the retry page with a 303', function () {
    $page = pageOfferingSslCommerz();

    $this->post(route('checkout.store'), capturePayload($page, ['payment_method' => 'sslcommerz']))
        ->assertStatus(303)
        ->assertRedirect(route('checkout.retry', Order::sole()->order_number));
});

it('still refuses a method the page never listed, however usable the installation finds it', function () {
    offeringSslCommerz();

    /** Offers cash only; SSLCommerz is usable but not this page's. */
    $page = sellingPage(Product::factory()->create());

    $this->postJson(route('checkout.store'), capturePayload($page, ['payment_method' => 'sslcommerz']))
        ->assertUnprocessable()
        ->assertJsonPath('errors.payment_method.0', 'Please choose one of the payment methods listed.');

    expect(Order::count())->toBe(0);
});

it('captures a method the page lists but can no longer offer as unpaid, rather than turning the customer away', function () {
    $page = pageOfferingSslCommerz();

    /** Written from the page as the customer's screen has it, before the installation switches the gateway off. */
    $payload = capturePayload($page, ['payment_method' => 'sslcommerz']);

    app(PaymentSettings::class)->with(['sslcommerzEnabled' => false])->save();

    expect($page->refresh()->checkout_config->offeredMethods())->toBe([PaymentMethod::CashOnDelivery])
        ->and($page->checkout_config->acceptedMethods())->toBe([PaymentMethod::SslCommerz, PaymentMethod::CashOnDelivery]);

    /** A Merchant's toggle is not the customer's mistake: the order is kept and the retry page offers what can be offered now (ADR-0013). */
    $this->postJson(route('checkout.store'), $payload)
        ->assertCreated()
        ->assertJsonPath('redirect', route('checkout.retry', Order::sole()->order_number));

    expect(Order::sole()->payment_method)->toBe(PaymentMethod::SslCommerz)
        ->and(Order::sole()->isUnpaid())->toBeTrue();

    $this->get(route('checkout.retry', Order::sole()->order_number))
        ->assertOk()
        ->assertSee('Cash on Delivery')
        ->assertDontSee('value="sslcommerz"', escape: false);
});

it('shows the unpaid order by number alone on the retry page, with the page\'s methods offered again and its own picked', function () {
    $order = unpaidOrder();

    $response = $this->get(route('checkout.retry', $order->order_number))
        ->assertOk()
        ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('<meta name="robots" content="noindex, nofollow">', escape: false)
        ->assertSee($order->order_number)
        ->assertSee($order->lines->first()->product_name)
        ->assertSee(Money::format($order->total))
        ->assertSee('not yet paid')
        ->assertSee('SSLCommerz')
        ->assertSee('Cash on Delivery')
        ->assertSee('action="'.route('checkout.retry.choose', $order->order_number).'"', escape: false)
        ->assertDontSee('Rahim Uddin')
        ->assertDontSee('01712345678')
        ->assertDontSee('Banani');

    /** The method the order was placed under opens picked, so retrying it needs no extra tap. */
    expect($response->getContent())
        ->toContain('value="sslcommerz" checked')
        ->not->toContain('value="cash_on_delivery" checked');
});

it('sends an order that is not unpaid from the retry page to its thank-you page', function () {
    $this->postJson(route('checkout.store'), capturePayload(sellingPage(Product::factory()->create())))->assertCreated();

    $order = Order::sole();

    expect($order->isUnpaid())->toBeFalse();

    $this->get(route('checkout.retry', $order->order_number))
        ->assertRedirect(route('checkout.confirmation', $order->order_number));
});

it('switches an unpaid order to cash on delivery, logs it, and lands on the thank-you page', function () {
    $order = unpaidOrder();

    $this->post(route('checkout.retry.choose', $order->order_number), ['payment_method' => 'cash_on_delivery'])
        ->assertStatus(303)
        ->assertRedirect(route('checkout.confirmation', $order->order_number));

    $order->refresh();

    expect($order->payment_method)->toBe(PaymentMethod::CashOnDelivery)
        ->and($order->isUnpaid())->toBeFalse();

    /** The Order's own trail records the switch, old and new, like any other change to it. */
    $entry = Activity::query()->where('subject_type', Order::class)->where('event', 'updated')->latest('id')->firstOrFail();

    expect($entry->attribute_changes->get('old'))->toBe(['payment_method' => 'sslcommerz'])
        ->and($entry->attribute_changes->get('attributes'))->toBe(['payment_method' => 'cash_on_delivery']);

    $this->get(route('checkout.confirmation', $order->order_number))
        ->assertOk()
        ->assertSee('Your order is confirmed')
        ->assertSee('Pay on delivery')
        ->assertDontSee(route('checkout.retry', $order->order_number));
});

it('keeps an unpaid order unpaid when the customer chooses an online method again, and returns to the retry page', function () {
    $order = unpaidOrder();

    $this->post(route('checkout.retry.choose', $order->order_number), ['payment_method' => 'sslcommerz'])
        ->assertStatus(303)
        ->assertRedirect(route('checkout.retry', $order->order_number));

    expect($order->refresh()->payment_method)->toBe(PaymentMethod::SslCommerz)
        ->and($order->isUnpaid())->toBeTrue();
});

it('refuses a method the page does not offer on the retry page and changes nothing', function (?string $method) {
    $order = unpaidOrder();

    $this->from(route('checkout.retry', $order->order_number))
        ->post(route('checkout.retry.choose', $order->order_number), array_filter(['payment_method' => $method]))
        ->assertRedirect(route('checkout.retry', $order->order_number))
        ->assertSessionHasErrors('payment_method');

    expect($order->refresh()->payment_method)->toBe(PaymentMethod::SslCommerz);
})->with([
    'a method the page never listed' => ['bkash'],
    'a name nothing knows' => ['gold-bars'],
    'no method at all' => [null],
]);

it('offers the page\'s methods as they stand now, and cash on delivery alone once the page is gone', function () {
    $page = pageOfferingSslCommerz();
    $order = unpaidOrder($page);

    /** The installation switched SSLCommerz off after the capture: the retry page draws what can be offered now. */
    app(PaymentSettings::class)->with(['sslcommerzEnabled' => false])->save();

    expect($order->refresh()->retryPaymentMethods())->toBe([PaymentMethod::CashOnDelivery]);

    $this->get(route('checkout.retry', $order->order_number))
        ->assertOk()
        ->assertSee('Cash on Delivery')
        ->assertDontSee('value="sslcommerz"', escape: false);

    $this->post(route('checkout.retry.choose', $order->order_number), ['payment_method' => 'sslcommerz'])
        ->assertSessionHasErrors('payment_method');

    /** Gone for good, not binned: an Order's page relation reads trashed Pages, so only a force delete leaves it with none. */
    $page->forceDelete();

    expect($order->refresh()->page)->toBeNull()
        ->and($order->retryPaymentMethods())->toBe([PaymentMethod::CashOnDelivery]);

    $this->get(route('checkout.retry', $order->order_number))->assertOk()->assertSee('Cash on Delivery');
});

it('links the thank-you page of an unpaid order to the retry page and calls its figure a total', function () {
    $order = unpaidOrder();

    $this->get(route('checkout.confirmation', $order->order_number))
        ->assertOk()
        ->assertSee('Your order is saved')
        ->assertSee(route('checkout.retry', $order->order_number))
        ->assertSee('SSLCommerz')
        ->assertSee('Total')
        ->assertDontSee('Pay on delivery')
        ->assertDontSee('Rahim Uddin');
});
