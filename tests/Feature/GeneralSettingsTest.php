<?php

use App\Models\Customer;
use App\Models\Setting;
use App\Support\PhoneNumber;
use App\Support\Settings\GeneralSettings;
use App\Support\Settings\SettingsGroup;
use Illuminate\Support\Facades\DB;

it('answers every general setting from config when nothing has been saved', function () {
    /**
     * A fresh installation, and this whole suite, runs with no Settings row
     * at all and behaves exactly as it did before Settings existed: the
     * `GIOPAGES_*` environment variables are the defaults (ADR-0012).
     */
    $general = app(GeneralSettings::class);

    expect($general->currencyCode)->toBe(config('giopages.currency.code'))
        ->and($general->currencySymbol)->toBe(config('giopages.currency.symbol'))
        ->and($general->currencyDecimals)->toBe((int) config('giopages.currency.decimals'))
        ->and($general->phoneCountryCode)->toBe((string) config('giopages.phone.country_code'))
        ->and($general->phoneTrunkPrefix)->toBe((string) config('giopages.phone.trunk_prefix'))
        ->and($general->phoneNationalLengthMin)->toBe((int) config('giopages.phone.national_length.min'))
        ->and($general->phoneNationalLengthMax)->toBe((int) config('giopages.phone.national_length.max'))
        ->and($general->checkoutRateLimit)->toBe((int) config('giopages.checkout.rate_limit'));
});

it('lets a saved row win over config, one property at a time', function () {
    $general = app(GeneralSettings::class);
    $general->currencySymbol = '$';
    $general->checkoutRateLimit = 5;
    $general->save();

    $this->app->forgetInstance(GeneralSettings::class);

    $reloaded = app(GeneralSettings::class);

    expect($reloaded)->not->toBe($general)
        ->and($reloaded->currencySymbol)->toBe('$')
        ->and($reloaded->checkoutRateLimit)->toBe(5)
        /** A key the row never mentioned keeps its config default. */
        ->and($reloaded->currencyCode)->toBe(config('giopages.currency.code'))
        ->and($reloaded->phoneCountryCode)->toBe((string) config('giopages.phone.country_code'));
});

it('ignores a key the group no longer knows and defaults one it has since gained', function () {
    /**
     * The group class is the schema of record for its row: a property with a
     * default can be added at any time and old rows keep working, and a
     * property that is dropped leaves nothing behind that can break a read.
     */
    Setting::query()->create([
        'key' => GeneralSettings::key(),
        'value' => ['currencySymbol' => '£', 'somethingWeStoppedStoring' => 'ignored'],
    ]);

    $general = app(GeneralSettings::class);

    expect($general->currencySymbol)->toBe('£')
        ->and($general->currencyDecimals)->toBe((int) config('giopages.currency.decimals'))
        ->and(isset($general->somethingWeStoppedStoring))->toBeFalse();
});

it('reads a null in the row as the empty value of the property, never as an error', function () {
    /**
     * The row is the one input that must never throw: a `TypeError` from
     * `load()` would take down every request, including the Settings page
     * that could repair the row.
     */
    Setting::query()->create([
        'key' => GeneralSettings::key(),
        'value' => ['currencySymbol' => null, 'phoneTrunkPrefix' => null, 'checkoutRateLimit' => null],
    ]);

    $general = app(GeneralSettings::class);

    expect($general->currencySymbol)->toBe('')
        ->and($general->phoneTrunkPrefix)->toBe('')
        ->and($general->checkoutRateLimit)->toBe(0);
});

it('has forgotten the maximum quantity per line, and still loads a row that holds one', function () {
    Setting::query()->create([
        'key' => GeneralSettings::key(),
        'value' => ['currencySymbol' => '£', 'checkoutMaxQuantity' => 20],
    ]);

    $general = app(GeneralSettings::class);

    expect($general->currencySymbol)->toBe('£')
        ->and(property_exists($general, 'checkoutMaxQuantity'))->toBeFalse()
        ->and(config()->has('giopages.checkout.max_quantity'))->toBeFalse();
});

it('queries a group once per request, and not at all when nothing asks for it', function () {
    DB::enableQueryLog();

    expect(DB::getQueryLog())->toBeEmpty();

    app(GeneralSettings::class);
    app(GeneralSettings::class)->currencySymbol;
    app(GeneralSettings::class)->checkoutRateLimit;

    $settingsQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'settings'));

    expect($settingsQueries)->toHaveCount(1);
});

it('keeps a secret property out of the row in plain text and hands it back whole', function () {
    $group = new class extends SettingsGroup
    {
        public string $apiKey = '';

        public string $label = 'plain';

        public static function key(): string
        {
            return 'test-secret-group';
        }

        /** @return list<string> */
        public static function secrets(): array
        {
            return ['apiKey'];
        }
    };

    $group->apiKey = 'sk_live_very_secret';
    $group->save();

    $stored = (string) DB::table('settings')->where('key', 'test-secret-group')->value('value');

    expect($stored)->not->toContain('sk_live_very_secret')
        ->and($stored)->toContain('plain');

    expect($group::load()->apiKey)->toBe('sk_live_very_secret');
});

it('reads and stores a customer phone number by the rules the merchant saved', function () {
    $general = app(GeneralSettings::class);
    $general->phoneCountryCode = '44';
    $general->phoneTrunkPrefix = '0';
    $general->phoneNationalLengthMin = 10;
    $general->phoneNationalLengthMax = 10;
    $general->save();

    orderAsCaptured(['phone' => '07700 900123']);

    /** Ten digits was fine in Bangladesh; the row now says the country is the United Kingdom. */
    expect(PhoneNumber::isValid('+880 1712 345678'))->toBeFalse()
        ->and(Customer::sole()->phone)->toBe('+447700900123');
});

it('turns away a flood of checkout choices at the rate the merchant saved', function () {
    $general = app(GeneralSettings::class);
    $general->checkoutRateLimit = 1;
    $general->save();

    $order = orderAsCaptured();

    $this->post(route('checkout.retry.choose', $order->order_number), ['payment_method' => 'cash_on_delivery'])->assertRedirect();

    $this->post(route('checkout.retry.choose', $order->order_number), ['payment_method' => 'cash_on_delivery'])->assertTooManyRequests();
});

it('defaults the timezone to the config key, which itself defaults to Dhaka', function () {
    expect(config('giopages.timezone'))->toBe('Asia/Dhaka')
        ->and(app(GeneralSettings::class)->timezone)->toBe('Asia/Dhaka');

    $general = app(GeneralSettings::class);
    $general->timezone = 'Europe/London';
    $general->save();

    $this->app->forgetInstance(GeneralSettings::class);

    expect(app(GeneralSettings::class)->timezone)->toBe('Europe/London');
});

it('reads a timezone the row holds that PHP does not know as the default, never as an error', function (mixed $stored) {
    Setting::query()->create([
        'key' => GeneralSettings::key(),
        'value' => ['timezone' => $stored, 'currencySymbol' => '£'],
    ]);

    $general = app(GeneralSettings::class);

    expect($general->timezone)->toBe('Asia/Dhaka')
        ->and($general->currencySymbol)->toBe('£');
})->with([
    'a name that is not a zone' => 'Mars/Olympus_Mons',
    'a null' => null,
    'a blank' => '',
]);
