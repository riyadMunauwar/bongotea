<?php

use App\Enums\PaymentMethod;
use App\Filament\Clusters\Settings\Pages\PaymentMethods;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings\GeneralSettings;
use App\Support\Settings\PaymentSettings;
use Filament\Navigation\NavigationGroup;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

it('sits in the Settings cluster beside General, one section per method, with the rest named as coming later', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    $this->get(PaymentMethods::getUrl())
        ->assertOk()
        ->assertSeeLivewire(PaymentMethods::class);

    Livewire::test(PaymentMethods::class)
        ->assertSee('Cash on Delivery')
        ->assertSee('SSLCommerz')
        ->assertSee('Store id')
        ->assertSee('Store password')
        ->assertSee('Sandbox')
        ->assertSee('bKash')
        ->assertSee('App key')
        ->assertSee('App secret')
        ->assertSee('Username')
        ->assertSee('Password')
        ->assertSee('Sandbox host')
        ->assertSee('Live host')
        ->assertSee('Notification URL')
        /** What the Merchant hands to bKash's support desk, read-only and never saved. */
        ->assertSee(route('payment.notify', ['method' => 'bkash']))
        ->assertSee('aamarPay')
        ->assertSee('Signature key')
        ->assertSee(route('payment.notify', ['method' => 'aamarpay']))
        ->assertSee('Coming later')
        ->assertSee('PayStation')
        ->assertDontSee('aamarPay, PayStation');

    $subNavigation = collect(SettingsCluster::getClusteredComponents())->map(fn (string $page): string => $page::getNavigationLabel());

    expect($subNavigation)->toContain('General')->toContain('Payment Methods');
});

it('loads the defaults when nothing has been saved, with the credentials blank', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    Livewire::test(PaymentMethods::class)
        ->assertSchemaStateSet([
            'cashOnDeliveryEnabled' => true,
            'sslcommerzEnabled' => false,
            'sslcommerzSandbox' => true,
            'sslcommerzStoreId' => null,
            'sslcommerzStorePassword' => null,
            'bkashEnabled' => false,
            'bkashSandbox' => true,
            'bkashAppKey' => null,
            'bkashAppSecret' => null,
            'bkashUsername' => null,
            'bkashPassword' => null,
            'bkashSandboxHost' => 'https://tokenized.sandbox.bka.sh/v1.2.0-beta',
            'bkashLiveHost' => 'https://tokenized.pay.bka.sh/v1.2.0-beta',
            'aamarpayEnabled' => false,
            'aamarpaySandbox' => true,
            'aamarpayStoreId' => null,
            'aamarpaySignatureKey' => null,
        ])
        ->assertDontSee('A value is stored');
});

it('saves the aamarPay section with both credentials sealed', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    Livewire::test(PaymentMethods::class)
        ->fillForm([
            'aamarpayEnabled' => true,
            'aamarpayStoreId' => 'aamarpay_store_live_1',
            'aamarpaySignatureKey' => 'sig_live_1',
            'aamarpaySandbox' => false,
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertSet('data.aamarpayStoreId', null)
        ->assertSet('data.aamarpaySignatureKey', null)
        ->assertDontSee('sig_live_1');

    $row = (string) DB::table('settings')->where('key', PaymentSettings::key())->value('value');

    expect($row)->not->toContain('aamarpay_store_live_1')
        ->and($row)->not->toContain('sig_live_1')
        ->and($row)->toContain('"aamarpayEnabled":true')
        ->and($row)->not->toContain('aamarpayNotificationUrl');

    app()->forgetInstance(PaymentSettings::class);

    expect(app(PaymentSettings::class)->aamarpayStoreId)->toBe('aamarpay_store_live_1')
        ->and(app(PaymentSettings::class)->aamarpaySignatureKey)->toBe('sig_live_1')
        ->and(app(PaymentSettings::class)->aamarpaySandbox)->toBeFalse()
        ->and(PaymentMethod::AamarPay->isUsable())->toBeTrue();
});

it('saves the bKash section with its four credentials sealed and the hosts readable', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    Livewire::test(PaymentMethods::class)
        ->fillForm([
            'bkashEnabled' => true,
            'bkashAppKey' => 'app_key_live_1',
            'bkashAppSecret' => 'app_secret_live_1',
            'bkashUsername' => 'merchant_live_1',
            'bkashPassword' => 'pw_live_1',
            'bkashLiveHost' => 'https://tokenized.pay.bka.sh/v1.2.0-beta/',
            'bkashSandbox' => false,
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertSet('data.bkashAppKey', null)
        ->assertSet('data.bkashAppSecret', null)
        ->assertSet('data.bkashUsername', null)
        ->assertSet('data.bkashPassword', null)
        ->assertDontSee('app_secret_live_1');

    $row = (string) DB::table('settings')->where('key', PaymentSettings::key())->value('value');

    expect($row)->not->toContain('app_key_live_1')
        ->and($row)->not->toContain('app_secret_live_1')
        ->and($row)->not->toContain('merchant_live_1')
        ->and($row)->not->toContain('pw_live_1')
        ->and($row)->toContain('"bkashLiveHost":"https:\/\/tokenized.pay.bka.sh\/v1.2.0-beta\/"')
        ->and($row)->not->toContain('bkashNotificationUrl');

    app()->forgetInstance(PaymentSettings::class);

    expect(app(PaymentSettings::class)->bkashAppKey)->toBe('app_key_live_1')
        ->and(app(PaymentSettings::class)->bkashPassword)->toBe('pw_live_1')
        ->and(app(PaymentSettings::class)->bkashSandbox)->toBeFalse()
        ->and(PaymentMethod::Bkash->isUsable())->toBeTrue();
});

it('saves one row, seals the credentials, keeps them out of the browser, and logs them as rotated', function () {
    $user = User::factory()->managingSettings()->create();
    $this->actingAs($user);

    Livewire::test(PaymentMethods::class)
        ->fillForm([
            'sslcommerzEnabled' => true,
            'sslcommerzSandbox' => false,
            'sslcommerzStoreId' => 'store_live_1',
            'sslcommerzStorePassword' => 'pw_live_1',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified()
        /** Once stored, the typed credentials leave Livewire state like they never entered it. */
        ->assertSet('data.sslcommerzStoreId', null)
        ->assertSet('data.sslcommerzStorePassword', null)
        ->assertSee('A value is stored')
        ->assertSee('fi-revealable', escape: false)
        ->assertDontSee('store_live_1')
        ->assertDontSee('pw_live_1');

    expect(Setting::count())->toBe(1)
        ->and(Setting::sole()->key)->toBe(PaymentSettings::key());

    $row = (string) DB::table('settings')->where('key', PaymentSettings::key())->value('value');

    expect($row)->not->toContain('store_live_1')
        ->and($row)->not->toContain('pw_live_1');

    app()->forgetInstance(PaymentSettings::class);

    $settings = app(PaymentSettings::class);

    expect($settings->sslcommerzEnabled)->toBeTrue()
        ->and($settings->sslcommerzSandbox)->toBeFalse()
        ->and($settings->sslcommerzStoreId)->toBe('store_live_1')
        ->and($settings->sslcommerzStorePassword)->toBe('pw_live_1')
        ->and(PaymentMethod::SslCommerz->isUsable())->toBeTrue();

    $entry = Activity::sole();

    expect($entry->causer->is($user))->toBeTrue()
        ->and($entry->getProperty('group'))->toBe(PaymentSettings::key())
        ->and($entry->attribute_changes->get('old'))->toBe([
            'sslcommerzEnabled' => false,
            'sslcommerzSandbox' => true,
            'sslcommerzStoreId' => null,
            'sslcommerzStorePassword' => null,
        ])
        ->and($entry->attribute_changes->get('attributes'))->toBe([
            'sslcommerzEnabled' => true,
            'sslcommerzSandbox' => false,
            'sslcommerzStoreId' => null,
            'sslcommerzStorePassword' => null,
        ])
        ->and(json_encode($entry->properties))->not->toContain('store_live_1')
        ->and(json_encode($entry->properties))->not->toContain('pw_live_1');
});

it('keeps the stored credentials when the page is saved with them blank', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    app(PaymentSettings::class)->with(['sslcommerzStoreId' => 'store_live_1', 'sslcommerzStorePassword' => 'pw_live_1'])->save();
    app()->forgetInstance(PaymentSettings::class);

    Livewire::test(PaymentMethods::class)
        ->assertSee('A value is stored')
        ->fillForm(['sslcommerzEnabled' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetInstance(PaymentSettings::class);

    expect(app(PaymentSettings::class)->sslcommerzStoreId)->toBe('store_live_1')
        ->and(app(PaymentSettings::class)->sslcommerzStorePassword)->toBe('pw_live_1')
        ->and(app(PaymentSettings::class)->sslcommerzEnabled)->toBeTrue();
});

it('warns when the installation currency is not BDT, and not otherwise', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    Livewire::test(PaymentMethods::class)
        ->assertDontSee('The currency is');

    app(GeneralSettings::class)->with(['currencyCode' => 'GBP'])->save();

    Livewire::test(PaymentMethods::class)
        ->assertSee('The currency is GBP.')
        ->assertSee('Cash on Delivery is unaffected');
});

it('stores a replacement logo on the public local disk, keeps only its path in the row, and draws it on the checkout', function () {
    Storage::fake(PaymentSettings::LOGO_DISK);
    Storage::fake('staging');

    $this->actingAs(User::factory()->managingSettings()->create());

    $logo = UploadedFile::fake()->createWithContent(
        'mine.svg',
        file_get_contents(public_path(PaymentMethod::SslCommerz->logoPath())),
    );

    Livewire::test(PaymentMethods::class)
        ->fillForm(['sslcommerzLogo' => $logo])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetInstance(PaymentSettings::class);

    $path = app(PaymentSettings::class)->sslcommerzLogo;

    expect($path)->toStartWith(PaymentSettings::LOGO_DIRECTORY.'/')
        ->and($path)->toEndWith('.svg');

    Storage::disk(PaymentSettings::LOGO_DISK)->assertExists($path);

    expect(PaymentMethod::SslCommerz->logoUrl())->toBe(Storage::disk(PaymentSettings::LOGO_DISK)->url($path))
        ->and(PaymentMethod::CashOnDelivery->logoUrl())->toBe(asset(PaymentMethod::CashOnDelivery->logoPath()));

    /** The row holds the path and nothing else about the file. */
    $row = Setting::query()->findOrFail(PaymentSettings::key())->value;

    expect($row['sslcommerzLogo'])->toBe($path);
});

it('hides the page from a User without the permission and forbids its URL', function () {
    $this->actingAs(User::factory()->create());

    $navigation = filament()->getCurrentOrDefaultPanel()->getNavigation();
    $labels = collect($navigation)->flatMap(fn (NavigationGroup $group): array|Arrayable => $group->getItems())->map->getLabel();

    expect($labels)->not->toContain('Settings')
        ->and(PaymentMethods::canAccess())->toBeFalse();

    $this->get(PaymentMethods::getUrl())->assertForbidden();
});

it('logs nothing when a save changes nothing', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    Livewire::test(PaymentMethods::class)->call('save')->assertHasNoFormErrors();

    expect(Setting::count())->toBe(1)
        ->and(Activity::count())->toBe(0);
});
