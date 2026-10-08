<?php

use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Support\Money;
use App\Support\Settings\PaymentSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;

/**
 * Make SSLCommerz usable for the store the way a Merchant would: on, with
 * both credentials filled, under the default BDT currency.
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
 * The gateway unreachable for every call, so a Payment started under an
 * online method is recorded Failed and the Customer lands on the Retry Page.
 * The road through a reachable gateway is `OnlinePaymentTest`'s.
 */
function gatewayDown(): void
{
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Connection timed out'));
}

/**
 * An Order captured under SSLCommerz while the gateway cannot be reached:
 * Unpaid from the moment it exists, with the attempt recorded Failed.
 */
function unpaidOrder(): Order
{
    offeringSslCommerz();
    gatewayDown();

    $order = orderAsCaptured(['payment_method' => PaymentMethod::SslCommerz]);

    test()->post(route('checkout.retry.choose', $order->order_number), ['payment_method' => 'sslcommerz'])
        ->assertRedirect(route('checkout.retry', $order->order_number));

    return $order->refresh();
}

it('shows the unpaid order by number alone on the retry page, with the store\'s methods offered again and its own picked', function () {
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

it('sends an order that is not unpaid from the retry page to its order complete page', function () {
    $order = orderAsCaptured();

    expect($order->isUnpaid())->toBeFalse();

    $this->get(route('checkout.retry', $order->order_number))
        ->assertRedirect(route('checkout.order-complete', $order->order_number));
});

it('switches an unpaid order to cash on delivery, logs it, and lands on the order complete page', function () {
    $order = unpaidOrder();

    $this->post(route('checkout.retry.choose', $order->order_number), ['payment_method' => 'cash_on_delivery'])
        ->assertStatus(303)
        ->assertRedirect(route('checkout.order-complete', $order->order_number));

    $order->refresh();

    expect($order->payment_method)->toBe(PaymentMethod::CashOnDelivery)
        ->and($order->isUnpaid())->toBeFalse();

    /** The Order's own trail records the switch, old and new, like any other change to it. */
    $entry = Activity::query()->where('subject_type', Order::class)->where('event', 'updated')->latest('id')->firstOrFail();

    expect($entry->attribute_changes->get('old'))->toBe(['payment_method' => 'sslcommerz'])
        ->and($entry->attribute_changes->get('attributes'))->toBe(['payment_method' => 'cash_on_delivery']);

    $this->get(route('checkout.order-complete', $order->order_number))
        ->assertOk()
        ->assertSee('Order placed')
        ->assertSee('Pay on delivery')
        ->assertDontSee(route('checkout.retry', $order->order_number));
});

it('keeps an unpaid order unpaid when the customer chooses an online method again and the gateway is still down, and returns to the retry page', function () {
    $order = unpaidOrder();

    $this->post(route('checkout.retry.choose', $order->order_number), ['payment_method' => 'sslcommerz'])
        ->assertStatus(303)
        ->assertRedirect(route('checkout.retry', $order->order_number));

    expect($order->refresh()->payment_method)->toBe(PaymentMethod::SslCommerz)
        ->and($order->isUnpaid())->toBeTrue();
});

it('refuses a method the store does not take on the retry page and changes nothing', function (?string $method) {
    $order = unpaidOrder();

    $this->from(route('checkout.retry', $order->order_number))
        ->post(route('checkout.retry.choose', $order->order_number), array_filter(['payment_method' => $method]))
        ->assertRedirect(route('checkout.retry', $order->order_number))
        ->assertSessionHasErrors('payment_method');

    expect($order->refresh()->payment_method)->toBe(PaymentMethod::SslCommerz);
})->with([
    'a method the store has not switched on' => ['bkash'],
    'a name nothing knows' => ['gold-bars'],
    'no method at all' => [null],
]);

it('offers the store\'s methods as they stand now', function () {
    $order = unpaidOrder();

    /** The store switched SSLCommerz off after the capture: the retry page draws what can be offered now. */
    app(PaymentSettings::class)->with(['sslcommerzEnabled' => false])->save();

    expect($order->refresh()->retryPaymentMethods())->toBe([PaymentMethod::CashOnDelivery]);

    $this->get(route('checkout.retry', $order->order_number))
        ->assertOk()
        ->assertSee('Cash on Delivery')
        ->assertDontSee('value="sslcommerz"', escape: false);

    $this->post(route('checkout.retry.choose', $order->order_number), ['payment_method' => 'sslcommerz'])
        ->assertSessionHasErrors('payment_method');
});

it('sends an unpaid order from the order complete page to its retry page', function () {
    $order = unpaidOrder();

    /** The Order Complete Page draws one state, a settled Order; an Unpaid Order has only a way to finish. */
    $this->get(route('checkout.order-complete', $order->order_number))
        ->assertRedirect(route('checkout.retry', $order->order_number));
});
