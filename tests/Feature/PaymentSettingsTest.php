<?php

use App\Enums\PaymentMethod;
use App\Models\Product;
use App\Support\Settings\GeneralSettings;
use App\Support\Settings\PaymentSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Save the Payment settings the way a Merchant already did, and forget the
 * request's copy so the next read comes from the row.
 *
 * @param  array<string, mixed>  $values
 */
function storePaymentSettings(array $values): void
{
    app(PaymentSettings::class)->with($values)->save();

    app()->forgetInstance(PaymentSettings::class);
}

it('behaves exactly as before when no row has been saved: cash on delivery on, every gateway off', function () {
    $settings = app(PaymentSettings::class);

    expect($settings->cashOnDeliveryEnabled)->toBeTrue()
        ->and($settings->sslcommerzEnabled)->toBeFalse()
        ->and($settings->sslcommerzSandbox)->toBeTrue()
        ->and($settings->sslcommerzStoreId)->toBe('')
        ->and($settings->sslcommerzStorePassword)->toBe('')
        ->and($settings->enabledMethods())->toBe([PaymentMethod::CashOnDelivery])
        ->and($settings->usableMethods())->toBe([PaymentMethod::CashOnDelivery])
        ->and(PaymentMethod::CashOnDelivery->isUsable())->toBeTrue()
        ->and(PaymentMethod::SslCommerz->isUsable())->toBeFalse();
});

it('names every gateway\'s credentials as secrets, the bKash hosts not, and none for cash on delivery', function () {
    $settings = app(PaymentSettings::class);

    expect(PaymentSettings::secrets())->toBe(['sslcommerzStoreId', 'sslcommerzStorePassword', 'bkashAppKey', 'bkashAppSecret', 'bkashUsername', 'bkashPassword', 'aamarpayStoreId', 'aamarpaySignatureKey', 'paystationMerchantId', 'paystationPassword'])
        ->and($settings->credentialsFor(PaymentMethod::SslCommerz))->toBe(['sslcommerzStoreId', 'sslcommerzStorePassword'])
        ->and($settings->credentialsFor(PaymentMethod::Bkash))->toBe(['bkashAppKey', 'bkashAppSecret', 'bkashUsername', 'bkashPassword'])
        ->and($settings->credentialsFor(PaymentMethod::AamarPay))->toBe(['aamarpayStoreId', 'aamarpaySignatureKey'])
        ->and($settings->credentialsFor(PaymentMethod::PayStation))->toBe(['paystationMerchantId', 'paystationPassword'])
        ->and($settings->credentialsFor(PaymentMethod::CashOnDelivery))->toBe([])
        ->and($settings->paystationEnabled)->toBeFalse()
        ->and($settings->paystationSandbox)->toBeTrue()
        ->and($settings->paystationMerchantId)->toBe('')
        ->and($settings->paystationPassword)->toBe('')
        ->and($settings->bkashEnabled)->toBeFalse()
        ->and($settings->bkashSandbox)->toBeTrue()
        ->and($settings->aamarpayEnabled)->toBeFalse()
        ->and($settings->aamarpaySandbox)->toBeTrue()
        ->and($settings->aamarpayStoreId)->toBe('')
        ->and($settings->aamarpaySignatureKey)->toBe('')
        ->and($settings->bkashSandboxHost)->toBe('https://tokenized.sandbox.bka.sh/v1.2.0-beta')
        ->and($settings->bkashLiveHost)->toBe('https://tokenized.pay.bka.sh/v1.2.0-beta');
});

it('offers bKash through the same rule as every method: on, all four credentials filled, currency BDT', function () {
    $settings = app(PaymentSettings::class)->with(['bkashEnabled' => true, 'bkashAppKey' => 'k', 'bkashAppSecret' => 's', 'bkashUsername' => 'u']);

    expect($settings->refusal(PaymentMethod::Bkash))->toBe('bKash has blank credentials in Settings.');

    $settings = $settings->with(['bkashPassword' => 'p']);

    expect($settings->refusal(PaymentMethod::Bkash))->toBeNull()
        ->and($settings->usableMethods())->toBe([PaymentMethod::CashOnDelivery, PaymentMethod::Bkash]);
});

it('offers aamarPay through the same rule as every method: on, both credentials filled, currency BDT', function () {
    $settings = app(PaymentSettings::class)->with(['aamarpayEnabled' => true, 'aamarpayStoreId' => 'aamarpaytest']);

    expect($settings->refusal(PaymentMethod::AamarPay))->toBe('aamarPay has blank credentials in Settings.');

    $settings = $settings->with(['aamarpaySignatureKey' => 'k']);

    expect($settings->refusal(PaymentMethod::AamarPay))->toBeNull()
        ->and($settings->usableMethods())->toBe([PaymentMethod::CashOnDelivery, PaymentMethod::AamarPay]);
});

it('seals the credentials in the row and leaves the switches readable', function () {
    storePaymentSettings(['sslcommerzEnabled' => true, 'sslcommerzStoreId' => 'store_live_1', 'sslcommerzStorePassword' => 'pw_live_1']);

    $row = (string) DB::table('settings')->where('key', PaymentSettings::key())->value('value');

    expect($row)->not->toContain('store_live_1')
        ->and($row)->not->toContain('pw_live_1')
        ->and($row)->toContain('"sslcommerzEnabled":true')
        ->and(app(PaymentSettings::class)->sslcommerzStoreId)->toBe('store_live_1')
        ->and(app(PaymentSettings::class)->sslcommerzStorePassword)->toBe('pw_live_1');
});

/**
 * The one place the usable-method rule lives: on, every credential filled,
 * and, for an online method, the installation currency is BDT.
 */
it('words why a method cannot be offered, and offers it once every condition is met', function () {
    $settings = app(PaymentSettings::class);

    expect($settings->refusal(PaymentMethod::SslCommerz))->toBe('SSLCommerz is switched off in Settings.')
        ->and($settings->refusal(PaymentMethod::Bkash))->toBe('bKash is switched off in Settings.')
        ->and($settings->refusal(PaymentMethod::AamarPay))->toBe('aamarPay is switched off in Settings.')
        ->and($settings->refusal(PaymentMethod::PayStation))->toBe('PayStation is switched off in Settings.')
        ->and($settings->refusal(PaymentMethod::CashOnDelivery))->toBeNull();

    $settings = $settings->with(['sslcommerzEnabled' => true, 'sslcommerzStoreId' => 'store_live_1']);

    /** On, but one credential still blank: listed by the tab, offered nowhere. */
    expect($settings->isEnabled(PaymentMethod::SslCommerz))->toBeTrue()
        ->and($settings->refusal(PaymentMethod::SslCommerz))->toBe('SSLCommerz has blank credentials in Settings.')
        ->and($settings->enabledMethods())->toBe([PaymentMethod::CashOnDelivery, PaymentMethod::SslCommerz])
        ->and($settings->usableMethods())->toBe([PaymentMethod::CashOnDelivery]);

    $settings = $settings->with(['sslcommerzStorePassword' => 'pw_live_1']);

    expect($settings->refusal(PaymentMethod::SslCommerz))->toBeNull()
        ->and($settings->usableMethods())->toBe([PaymentMethod::CashOnDelivery, PaymentMethod::SslCommerz]);

    $settings = $settings->with(['cashOnDeliveryEnabled' => false]);

    expect($settings->refusal(PaymentMethod::CashOnDelivery))->toBe('Cash on Delivery is switched off in Settings.')
        ->and($settings->usableMethods())->toBe([PaymentMethod::SslCommerz]);
});

it('refuses every online method when the installation currency is not BDT, and cash on delivery not at all', function () {
    app(GeneralSettings::class)->with(['currencyCode' => 'gbp'])->save();

    storePaymentSettings(['sslcommerzEnabled' => true, 'sslcommerzStoreId' => 'store_live_1', 'sslcommerzStorePassword' => 'pw_live_1']);

    $settings = app(PaymentSettings::class);

    expect($settings->currencyIsSupported())->toBeFalse()
        ->and($settings->refusal(PaymentMethod::SslCommerz))->toBe('SSLCommerz takes BDT only, and the currency is GBP.')
        ->and($settings->usableMethods())->toBe([PaymentMethod::CashOnDelivery]);

    app(GeneralSettings::class)->with(['currencyCode' => 'BDT'])->save();

    expect($settings->usableMethods())->toBe([PaymentMethod::CashOnDelivery, PaymentMethod::SslCommerz]);
});

it('filters a page\'s list to the usable methods in the page\'s order, keeps the raw list whole, and falls back to cash', function () {
    $page = payingBy(sellingPage(Product::factory()->create()), ['sslcommerz', 'bkash', 'cash_on_delivery']);

    expect($page->checkout_config->offeredMethods())->toBe([PaymentMethod::CashOnDelivery]);

    storePaymentSettings(['sslcommerzEnabled' => true, 'sslcommerzStoreId' => 'store_live_1', 'sslcommerzStorePassword' => 'pw_live_1']);

    $config = $page->refresh()->checkout_config;

    expect($config->offeredMethods())->toBe([PaymentMethod::SslCommerz, PaymentMethod::CashOnDelivery])
        ->and(PaymentMethod::names($config->paymentMethods))->toBe(['sslcommerz', 'bkash', 'cash_on_delivery']);

    /** Nothing usable left in the list: the page still has one way to order. */
    storePaymentSettings(['cashOnDeliveryEnabled' => false, 'sslcommerzEnabled' => false]);

    expect($page->refresh()->checkout_config->offeredMethods())->toBe([PaymentMethod::CashOnDelivery]);
});

it('draws the default logo until a replacement is stored, then the replacement from the public disk', function () {
    Storage::fake(PaymentSettings::LOGO_DISK);

    expect(PaymentMethod::SslCommerz->logoUrl())->toBe(asset('images/payment-methods/sslcommerz.svg'))
        ->and(app(PaymentSettings::class)->logoPathFor(PaymentMethod::SslCommerz))->toBeNull();

    storePaymentSettings(['sslcommerzLogo' => 'payment-methods/mine.svg']);

    expect(app(PaymentSettings::class)->logoPathFor(PaymentMethod::SslCommerz))->toBe('payment-methods/mine.svg')
        ->and(PaymentMethod::SslCommerz->logoUrl())->toBe(Storage::disk(PaymentSettings::LOGO_DISK)->url('payment-methods/mine.svg'))
        ->and(PaymentMethod::CashOnDelivery->logoUrl())->toBe(asset('images/payment-methods/cash-on-delivery.svg'));
});

it('names each method\'s properties by one rule', function () {
    expect(PaymentSettings::property(PaymentMethod::CashOnDelivery, 'Enabled'))->toBe('cashOnDeliveryEnabled')
        ->and(PaymentSettings::property(PaymentMethod::SslCommerz, 'Logo'))->toBe('sslcommerzLogo')
        ->and(PaymentMethod::CashOnDelivery->isOnline())->toBeFalse()
        ->and(PaymentMethod::SslCommerz->isOnline())->toBeTrue();
});
