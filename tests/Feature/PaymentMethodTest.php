<?php

use App\Enums\PaymentMethod;
use App\Enums\PaymentState;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Payments\AamarPay;
use App\Support\Payments\Bkash;
use App\Support\Payments\CashOnDelivery;
use App\Support\Payments\PaymentGateway;
use App\Support\Payments\SslCommerz;
use App\Support\Payments\Verification;
use App\Support\Settings\PaymentSettings;
use Illuminate\Http\Request;

it('knows every payment method by name, with a label and a default logo each', function () {
    /** The five the system will ever offer, spelled one way for Orders, Pages and Settings alike (ADR-0013). */
    expect(array_map(fn (PaymentMethod $method): string => $method->value, PaymentMethod::cases()))
        ->toBe(['cash_on_delivery', 'bkash', 'sslcommerz', 'aamarpay', 'paystation']);

    foreach (PaymentMethod::cases() as $method) {
        expect($method->getLabel())->not->toBe('')
            ->and(public_path($method->logoPath()))->toBeFile();
    }

    expect(PaymentMethod::labels())->toBe([
        'cash_on_delivery' => 'Cash on Delivery',
        'bkash' => 'bKash',
        'sslcommerz' => 'SSLCommerz',
        'aamarpay' => 'aamarPay',
        'paystation' => 'PayStation',
    ]);
});

it('resolves cash on delivery, SSLCommerz, bKash and aamarPay to drivers, and offers only cash until Settings say otherwise', function () {
    /**
     * The four online cases exist so a Page can already name them. Three have
     * a driver so far, and on an installation with no Payment settings saved
     * no online method is usable — so they are offered nowhere whatever a
     * Page says. `PaymentSettingsTest` is where one becomes usable.
     */
    expect(PaymentMethod::CashOnDelivery->gateway())->toBeInstanceOf(CashOnDelivery::class)
        ->and(PaymentMethod::CashOnDelivery->isUsable())->toBeTrue()
        ->and(PaymentMethod::SslCommerz->gateway())->toBeInstanceOf(SslCommerz::class)
        ->and(PaymentMethod::SslCommerz->gateway()->isOnline())->toBeTrue()
        ->and(PaymentMethod::SslCommerz->gateway()->credentialProperties())->toBe(app(PaymentSettings::class)->credentialsFor(PaymentMethod::SslCommerz))
        ->and(PaymentMethod::SslCommerz->gateway()->takesNotificationUrl())->toBeTrue()
        ->and(PaymentMethod::Bkash->gateway())->toBeInstanceOf(Bkash::class)
        ->and(PaymentMethod::Bkash->gateway()->isOnline())->toBeTrue()
        ->and(PaymentMethod::Bkash->gateway()->credentialProperties())->toBe(app(PaymentSettings::class)->credentialsFor(PaymentMethod::Bkash))
        /** Registered once through bKash's support desk, never sent with a Payment. */
        ->and(PaymentMethod::Bkash->gateway()->takesNotificationUrl())->toBeFalse()
        ->and(PaymentMethod::AamarPay->gateway())->toBeInstanceOf(AamarPay::class)
        ->and(PaymentMethod::AamarPay->gateway()->isOnline())->toBeTrue()
        ->and(PaymentMethod::AamarPay->gateway()->credentialProperties())->toBe(app(PaymentSettings::class)->credentialsFor(PaymentMethod::AamarPay))
        /** Registered once through aamarPay's support desk, never sent with a Payment. */
        ->and(PaymentMethod::AamarPay->gateway()->takesNotificationUrl())->toBeFalse()
        ->and(PaymentMethod::online())->toBe([PaymentMethod::Bkash, PaymentMethod::SslCommerz, PaymentMethod::AamarPay, PaymentMethod::PayStation]);

    expect(PaymentMethod::PayStation->gateway())->toBeNull();

    foreach (PaymentMethod::online() as $online) {
        expect($online->isUsable())->toBeFalse();
    }
});

it('names a list of methods the way they are stored and posted', function () {
    expect(PaymentMethod::names([PaymentMethod::Bkash, PaymentMethod::CashOnDelivery]))->toBe(['bkash', 'cash_on_delivery'])
        ->and(PaymentMethod::names([]))->toBe([]);
});

it('reads a stored name totally', function () {
    expect(PaymentMethod::fromStored('bkash'))->toBe(PaymentMethod::Bkash)
        ->and(PaymentMethod::fromStored('gold-bars'))->toBeNull()
        ->and(PaymentMethod::fromStored(42))->toBeNull()
        ->and(PaymentMethod::fromStored(null))->toBeNull();
});

it('implements the gateway contract for cash on delivery with nothing to start and nothing to verify', function () {
    $cash = new CashOnDelivery;

    /**
     * The same contract as every gateway, so the Checkout never special-cases
     * it: not online, no credentials, no notification URL, and no reference
     * to be read off any request.
     */
    expect($cash)->toBeInstanceOf(PaymentGateway::class)
        ->and($cash->isOnline())->toBeFalse()
        ->and($cash->credentialProperties())->toBe([])
        ->and($cash->takesNotificationUrl())->toBeFalse()
        ->and($cash->paymentReferenceFromReturn(Request::create('/', 'GET', ['paymentID' => 'x'])))->toBeNull()
        ->and($cash->readNotification(Request::create('/', 'POST', ['tran_id' => 'x'])))->toBeNull();

    /** The two calls only an online method receives are refused outright rather than answered with something plausible. */
    $payment = Payment::factory()->make(['order_id' => Order::factory()->create()->getKey()]);

    expect(fn () => $cash->start($payment, 'https://example.test/pay', null))->toThrow(LogicException::class);

    expect(fn () => $cash->verify($payment))->toThrow(LogicException::class);
});

it('answers a verification in the payment states', function () {
    $verification = new Verification(PaymentState::Paid, ['status' => 'Completed']);

    expect($verification->isPaid())->toBeTrue()
        ->and($verification->response)->toBe(['status' => 'Completed'])
        ->and((new Verification(PaymentState::Pending))->isPaid())->toBeFalse()
        ->and(array_map(fn (PaymentState $state): string => $state->value, PaymentState::cases()))
        ->toBe(['pending', 'paid', 'failed', 'cancelled', 'expired']);
});
