<?php

use App\Filament\Clusters\Settings\Pages\General;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings\GeneralSettings;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * The migration that brought the permission in, so a test can replay what an
 * upgrade does to the Users who were already there.
 */
const MANAGE_SETTINGS_MIGRATION = 'database/migrations/2026_09_08_061359_create_manage_settings_permission.php';

it('shows a Settings entry last in the sidebar, holding the General page', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    $navigation = filament()->getCurrentOrDefaultPanel()->getNavigation();
    $items = collect($navigation)->flatMap(fn ($group) => $group->getItems())->values();

    expect($items->last()->getLabel())->toBe('Settings')
        ->and($items->last()->getUrl())->toBe(SettingsCluster::getUrl())
        ->and($items->count())->toBeGreaterThan(1);

    $this->get(General::getUrl())
        ->assertOk()
        ->assertSeeLivewire(General::class);
});

it('opens the cluster onto the General page', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    $this->get(SettingsCluster::getUrl())->assertRedirect(General::getUrl());
});

it('loads the current General values, from config when nothing has been saved', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    Livewire::test(General::class)
        ->assertSchemaStateSet([
            'currencyCode' => config('giopages.currency.code'),
            'currencySymbol' => config('giopages.currency.symbol'),
            'currencyDecimals' => (int) config('giopages.currency.decimals'),
            'phoneCountryCode' => (string) config('giopages.phone.country_code'),
            'phoneTrunkPrefix' => (string) config('giopages.phone.trunk_prefix'),
            'phoneNationalLengthMin' => (int) config('giopages.phone.national_length.min'),
            'phoneNationalLengthMax' => (int) config('giopages.phone.national_length.max'),
            'deliveryCharge' => (float) config('giopages.delivery.charge'),
            'checkoutMaxQuantity' => (int) config('giopages.checkout.max_quantity'),
            'checkoutRateLimit' => (int) config('giopages.checkout.rate_limit'),
        ]);
});

it('saves the General page as one row, says so, and shows the saved values on reopening', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    Livewire::test(General::class)
        ->fillForm([
            'currencyCode' => 'GBP',
            'currencySymbol' => '£',
            'currencyDecimals' => 2,
            'phoneCountryCode' => '44',
            'phoneTrunkPrefix' => '0',
            'phoneNationalLengthMin' => 10,
            'phoneNationalLengthMax' => 10,
            'deliveryCharge' => '4.50',
            'checkoutMaxQuantity' => 20,
            'checkoutRateLimit' => 10,
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(Setting::count())->toBe(1)
        ->and(Setting::sole()->key)->toBe(GeneralSettings::key());

    Livewire::test(General::class)
        ->assertSchemaStateSet([
            'currencyCode' => 'GBP',
            'currencySymbol' => '£',
            'currencyDecimals' => 2,
            'phoneCountryCode' => '44',
            'phoneTrunkPrefix' => '0',
            'phoneNationalLengthMin' => 10,
            'phoneNationalLengthMax' => 10,
            'deliveryCharge' => 4.5,
            'checkoutMaxQuantity' => 20,
            'checkoutRateLimit' => 10,
        ]);
});

it('saves a blank trunk prefix for a country that dials none', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    Livewire::test(General::class)
        ->fillForm(['phoneTrunkPrefix' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->app->forgetInstance(GeneralSettings::class);

    expect(app(GeneralSettings::class)->phoneTrunkPrefix)->toBe('');
});

/**
 * Every stored price and total is an integer in minor units whose scale is the
 * decimals value, so changing it once money exists would rescale all of it.
 */
it('locks the currency decimals once a product or order exists, and keeps the stored value on save', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    Livewire::test(General::class)
        ->assertFormFieldEnabled('currencyDecimals')
        ->fillForm(['currencyDecimals' => 3])
        ->call('save')
        ->assertHasNoFormErrors();

    Product::factory()->trashed()->create();
    $this->app->forgetInstance(GeneralSettings::class);

    Livewire::test(General::class)
        ->assertFormFieldDisabled('currencyDecimals')
        ->assertSee('already stored at this scale')
        ->fillForm(['currencyDecimals' => 0, 'currencySymbol' => '$'])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->app->forgetInstance(GeneralSettings::class);

    expect(app(GeneralSettings::class)->currencyDecimals)->toBe(3)
        ->and(app(GeneralSettings::class)->currencySymbol)->toBe('$');
});

it('sends a signed-out visitor to the login screen rather than forbidding the page', function () {
    $this->get(General::getUrl())->assertRedirect('/admin/login');
});

it('refuses invalid input with field errors and saves nothing', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    Livewire::test(General::class)
        ->fillForm([
            'deliveryCharge' => '-5',
            'phoneNationalLengthMin' => 'ten',
            'checkoutMaxQuantity' => 0,
        ])
        ->call('save')
        ->assertHasFormErrors(['deliveryCharge', 'phoneNationalLengthMin', 'checkoutMaxQuantity']);

    expect(Setting::count())->toBe(0);
});

it('shows a customer the currency symbol saved on the page', function () {
    sellingPage(Product::factory()->create(['price' => 99000]));

    $this->actingAs(User::factory()->managingSettings()->create());

    Livewire::test(General::class)
        ->fillForm(['currencySymbol' => '$'])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->get('/promo')
        ->assertOk()
        ->assertSee('data-currency-symbol="$"', escape: false)
        ->assertDontSee('data-currency-symbol="৳"', escape: false);
});

it('hides Settings from a User without the permission and forbids the page URL', function () {
    $this->actingAs(User::factory()->create());

    $navigation = filament()->getCurrentOrDefaultPanel()->getNavigation();
    $labels = collect($navigation)->flatMap(fn ($group) => $group->getItems())->map->getLabel();

    expect($labels)->not->toContain('Settings');

    $this->get(General::getUrl())->assertForbidden();
    $this->get(SettingsCluster::getUrl())->assertForbidden();
});

it('grants the permission to every User who existed when the migration ran', function () {
    $existing = User::factory()->create();

    Artisan::call('migrate:rollback', ['--path' => MANAGE_SETTINGS_MIGRATION, '--force' => true]);

    expect($existing->fresh()->can(SettingsCluster::PERMISSION))->toBeFalse();

    Artisan::call('migrate', ['--path' => MANAGE_SETTINGS_MIGRATION, '--force' => true]);

    $later = User::factory()->create();

    $this->actingAs($existing->fresh())->get(General::getUrl())->assertOk();
    $this->actingAs($later)->get(General::getUrl())->assertForbidden();
});

it('logs one activity entry naming the User, the group, and each changed value old and new', function () {
    $user = User::factory()->managingSettings()->create();
    $this->actingAs($user);

    Livewire::test(General::class)
        ->fillForm(['currencyCode' => 'GBP', 'currencySymbol' => '£'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Activity::count())->toBe(1);

    $entry = Activity::sole();

    expect($entry->causer)->toBeInstanceOf(User::class)
        ->and($entry->causer->is($user))->toBeTrue()
        ->and($entry->description)->toContain(GeneralSettings::key())
        ->and($entry->getProperty('group'))->toBe(GeneralSettings::key())
        ->and($entry->attribute_changes->get('old'))->toBe([
            'currencyCode' => config('giopages.currency.code'),
            'currencySymbol' => config('giopages.currency.symbol'),
        ])
        ->and($entry->attribute_changes->get('attributes'))->toBe([
            'currencyCode' => 'GBP',
            'currencySymbol' => '£',
        ]);
});

it('logs a second save against the values the first one stored, not the defaults', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    Livewire::test(General::class)->fillForm(['currencyCode' => 'GBP'])->call('save');

    $this->app->forgetInstance(GeneralSettings::class);

    Livewire::test(General::class)->fillForm(['currencyCode' => 'EUR'])->call('save');

    $latest = Activity::latest('id')->first();

    expect(Activity::count())->toBe(2)
        ->and($latest->attribute_changes->get('old'))->toBe(['currencyCode' => 'GBP'])
        ->and($latest->attribute_changes->get('attributes'))->toBe(['currencyCode' => 'EUR']);
});

it('logs nothing when a save changes nothing', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    Livewire::test(General::class)->call('save')->assertHasNoFormErrors();

    expect(Setting::count())->toBe(1)
        ->and(Activity::count())->toBe(0);
});
