<?php

use App\Enums\PaymentMethod;
use App\Enums\PaymentState;
use App\Models\Order;
use App\Support\Payments\CashOnDelivery;
use App\Support\Payments\PaymentGateway;
use App\Support\Payments\Verification;
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

it('resolves only cash on delivery to a usable driver for now', function () {
    /**
     * The four online cases exist so a Page can already name them, and
     * resolve to nothing usable until each has its driver — so they are
     * offered nowhere whatever a Page says.
     */
    expect(PaymentMethod::CashOnDelivery->gateway())->toBeInstanceOf(CashOnDelivery::class)
        ->and(PaymentMethod::CashOnDelivery->isUsable())->toBeTrue();

    foreach ([PaymentMethod::Bkash, PaymentMethod::SslCommerz, PaymentMethod::AamarPay, PaymentMethod::PayStation] as $online) {
        expect($online->gateway())->toBeNull()
            ->and($online->isUsable())->toBeFalse();
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
        ->and($cash->paymentReferenceFromNotification(Request::create('/', 'POST', ['tran_id' => 'x'])))->toBeNull();

    /** The two calls only an online method receives are refused outright rather than answered with something plausible. */
    expect(fn () => $cash->start(Order::factory()->create(), 'ABC12345-1', 'https://example.test/notify'))
        ->toThrow(LogicException::class);

    expect(fn () => $cash->verify('anything', 105000))->toThrow(LogicException::class);
});

it('answers a verification in the payment states', function () {
    $verification = new Verification(PaymentState::Paid, ['status' => 'Completed']);

    expect($verification->isPaid())->toBeTrue()
        ->and($verification->response)->toBe(['status' => 'Completed'])
        ->and((new Verification(PaymentState::Pending))->isPaid())->toBeFalse()
        ->and(array_map(fn (PaymentState $state): string => $state->value, PaymentState::cases()))
        ->toBe(['pending', 'paid', 'failed', 'cancelled', 'expired']);
});
