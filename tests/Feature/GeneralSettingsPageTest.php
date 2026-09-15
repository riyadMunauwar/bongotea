<?php

use App\Filament\Clusters\Settings\Pages\General;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\Page;
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
            'timezone' => config('giopages.timezone'),
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
            'timezone' => 'Europe/London',
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
            'timezone' => 'Europe/London',
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
            'timezone' => 'Mars/Olympus_Mons',
        ])
        ->call('save')
        ->assertHasFormErrors(['deliveryCharge', 'phoneNationalLengthMin', 'checkoutMaxQuantity', 'timezone']);

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

/**
 * The migration that brought this permission in hands it straight to every
 * User who was already there, which was right when roles did not exist. It
 * stays in place as history of what ran and now grants nothing that matters:
 * authority comes through a Role and no other way (ADR-0025), so replaying
 * the upgrade over a User who holds no Role opens nothing.
 */
it('opens nothing for the direct grant the migration hands out', function () {
    $existing = User::factory()->create();

    Artisan::call('migrate:rollback', ['--path' => MANAGE_SETTINGS_MIGRATION, '--force' => true]);
    Artisan::call('migrate', ['--path' => MANAGE_SETTINGS_MIGRATION, '--force' => true]);

    $this->actingAs($existing->fresh())->get(General::getUrl())->assertForbidden();

    /** A Role is what opens it, and the only thing that does. */
    $this->actingAs(User::factory()->managingSettings()->create())->get(General::getUrl())->assertOk();
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

it('offers every page that is not in the bin, drafts included', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    $published = Page::factory()->published()->create(['title' => 'Winter Promo']);
    $draft = Page::factory()->create(['title' => 'Spring Draft']);
    $binned = Page::factory()->create(['title' => 'Retired']);
    $binned->delete();

    /**
     * Drafts are offered so a Merchant can line one up before launch — the
     * Site Root shows the Placeholder until it is published — and, more
     * importantly, so unpublishing the Front Page never removes it from the
     * option set. A Select derives an implicit `in:` rule from its options, so
     * a vanished option makes the *whole* General page unsaveable.
     */
    Livewire::test(General::class)
        ->fillForm(['frontPageId' => $published->getKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    Livewire::test(General::class)
        ->fillForm(['frontPageId' => $draft->getKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    /** A Page in the bin is not offered, and the Select's own rule refuses it. */
    Livewire::test(General::class)
        ->fillForm(['frontPageId' => $binned->getKey()])
        ->call('save')
        ->assertHasFormErrors(['frontPageId']);
});

/**
 * The dropdown is not drawn from the rendered HTML. `options()` is a closure,
 * so Filament marks the field as having dynamic options and the browser asks
 * for them over `callSchemaComponentMethod(<the field's key>, …)` as the
 * dropdown opens, replacing whatever it was holding with the answer. An answer
 * of null is a dropdown with nothing in it, whatever the page shipped.
 */
it('answers the browser with every page when the dropdown opens', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    Page::factory()->published()->create(['title' => 'Kettle Landing']);

    $page = Livewire::test(General::class)->instance();

    $key = $page->getSchema('form')->getFlatFields(withHidden: true)['frontPageId']->getKey();

    $options = $page->callSchemaComponentMethod($key, 'getOptionsForJs');

    expect($options)->toBeArray()
        ->and(collect($options)->pluck('label')->implode("\n"))->toContain('Kettle Landing');
});

it('saves the chosen front page', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    $page = Page::factory()->published()->create();

    Livewire::test(General::class)
        ->fillForm(['frontPageId' => $page->getKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(GeneralSettings::class)->frontPageId)->toBe($page->getKey());
});

it('reads a cleared front page as the placeholder', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    $page = Page::factory()->published()->create();

    app(GeneralSettings::class)->with(['frontPageId' => $page->getKey()])->save();

    Livewire::test(General::class)
        ->fillForm(['frontPageId' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    /**
     * A non-nullable int property reads a stored null as zero, which is the
     * group's own wording for "no Front Page".
     */
    expect(app(GeneralSettings::class)->frontPageId)->toBe(0);
});

it('stays saveable when the designated page has been binned', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    $page = Page::factory()->published()->create(['title' => 'Retired']);

    app(GeneralSettings::class)->with(['frontPageId' => $page->getKey()])->save();

    $page->delete();

    /**
     * The stored value is forced into the options whatever state it is in.
     * Without that guard, binning the Front Page would leave currency, timezone
     * and phone rules unsaveable — an error on a field the Merchant never
     * touched, raised by something they did on another screen entirely. The
     * group's own guard cannot cover it: validation runs before `with()` does.
     */
    Livewire::test(General::class)
        ->fillForm(['currencySymbol' => '৳৳'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(GeneralSettings::class)->currencySymbol)->toBe('৳৳');
});
