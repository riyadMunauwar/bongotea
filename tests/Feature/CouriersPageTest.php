<?php

use App\Enums\Courier;
use App\Filament\Clusters\Settings\Pages\Couriers;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings\CourierSettings;
use Filament\Navigation\NavigationGroup;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

it('sits in the Settings cluster, one section per Courier, each asking for what that Courier needs', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    $this->get(Couriers::getUrl())
        ->assertOk()
        ->assertSeeLivewire(Couriers::class);

    Livewire::test(Couriers::class)
        ->assertSee('Steadfast')
        ->assertSee('API key')
        ->assertSee('Secret key')
        ->assertSee('Pathao')
        ->assertSee('Client id')
        ->assertSee('Client secret')
        ->assertSee('Username')
        ->assertSee('Password')
        ->assertSee('RedEx')
        ->assertSee('Access token')
        /** The warehouses a Courier collects from, and which of them a parcel goes from when nobody chooses. */
        ->assertSee('Pickup stores')
        ->assertSee('Default pickup store')
        /** The four defaults, once per Courier, are what make an unattended booking possible. */
        ->assertSee('Delivery type')
        ->assertSee('Item type')
        ->assertSee('Parcel weight')
        ->assertSee('Declared value');

    $subNavigation = collect(SettingsCluster::getClusteredComponents())->map(fn (string $page): string => $page::getNavigationLabel());

    expect($subNavigation)->toContain('Couriers')->toContain('Tracking');
});

it('loads the config defaults when nothing has been saved, with every credential blank', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    Livewire::test(Couriers::class)
        ->assertSchemaStateSet([
            'steadfastEnabled' => false,
            'steadfastApiKey' => null,
            'steadfastSecretKey' => null,
            'steadfastDeliveryType' => '0',
            'steadfastParcelWeight' => 0.5,
            'steadfastDeclaredValue' => 1000.0,
            'pathaoEnabled' => false,
            'pathaoClientId' => null,
            'pathaoClientSecret' => null,
            'pathaoUsername' => null,
            'pathaoPassword' => null,
            'pathaoDeliveryType' => '48',
            'pathaoItemType' => '2',
            'redexEnabled' => false,
            'redexAccessToken' => null,
            'redexPickupStore' => '',
            'redexParcelWeight' => 0.5,
            'redexDeclaredValue' => 1000.0,
        ])
        ->assertDontSee('A value is stored');
});

it('saves one row, seals both of Steadfast\'s keys, keeps them out of the browser, and logs them as rotated', function () {
    $user = User::factory()->managingSettings()->create();
    $this->actingAs($user);

    Livewire::test(Couriers::class)
        ->fillForm([
            'steadfastEnabled' => true,
            'steadfastApiKey' => 'steadfast_key_live_1',
            'steadfastSecretKey' => 'steadfast_secret_live_1',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified()
        /** Once stored, the typed credentials leave Livewire state like they never entered it. */
        ->assertSet('data.steadfastApiKey', null)
        ->assertSet('data.steadfastSecretKey', null)
        ->assertSee('A value is stored')
        ->assertSee('fi-revealable', escape: false)
        ->assertDontSee('steadfast_key_live_1')
        ->assertDontSee('steadfast_secret_live_1');

    expect(Setting::count())->toBe(1)
        ->and(Setting::sole()->key)->toBe(CourierSettings::key());

    $row = (string) DB::table('settings')->where('key', CourierSettings::key())->value('value');

    expect($row)->not->toContain('steadfast_key_live_1')
        ->and($row)->not->toContain('steadfast_secret_live_1')
        ->and($row)->toContain('"steadfastEnabled":true');

    app()->forgetInstance(CourierSettings::class);

    expect(app(CourierSettings::class)->steadfastApiKey)->toBe('steadfast_key_live_1')
        ->and(app(CourierSettings::class)->steadfastSecretKey)->toBe('steadfast_secret_live_1')
        ->and(Courier::Steadfast->isUsable())->toBeTrue()
        ->and(Courier::Pathao->isUsable())->toBeFalse();

    $entry = Activity::sole();

    expect($entry->causer->is($user))->toBeTrue()
        ->and($entry->getProperty('group'))->toBe(CourierSettings::key())
        ->and($entry->attribute_changes->get('old'))->toBe([
            'steadfastEnabled' => false,
            'steadfastApiKey' => null,
            'steadfastSecretKey' => null,
        ])
        ->and($entry->attribute_changes->get('attributes'))->toBe([
            'steadfastEnabled' => true,
            'steadfastApiKey' => null,
            'steadfastSecretKey' => null,
        ])
        ->and(json_encode($entry->properties))->not->toContain('steadfast_key_live_1');
});

it('saves Pathao\'s four credentials sealed, and none of them reaches the browser again', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    Livewire::test(Couriers::class)
        ->fillForm([
            'pathaoEnabled' => true,
            'pathaoClientId' => 'pathao_client_live_1',
            'pathaoClientSecret' => 'pathao_secret_live_1',
            'pathaoUsername' => 'pathao_user_live_1',
            'pathaoPassword' => 'pathao_pw_live_1',
            'pathaoPickupStores' => [['id' => '11223', 'name' => 'Banani warehouse']],
            'pathaoPickupStore' => '11223',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertSet('data.pathaoClientId', null)
        ->assertSet('data.pathaoClientSecret', null)
        ->assertSet('data.pathaoUsername', null)
        ->assertSet('data.pathaoPassword', null)
        ->assertDontSee('pathao_secret_live_1');

    $row = (string) DB::table('settings')->where('key', CourierSettings::key())->value('value');

    expect($row)->not->toContain('pathao_client_live_1')
        ->and($row)->not->toContain('pathao_secret_live_1')
        ->and($row)->not->toContain('pathao_user_live_1')
        ->and($row)->not->toContain('pathao_pw_live_1')
        /** A default is not a credential, so it stays readable in the row. */
        ->and($row)->toContain('"pathaoPickupStore":"11223"');

    app()->forgetInstance(CourierSettings::class);

    expect(app(CourierSettings::class)->pathaoClientId)->toBe('pathao_client_live_1')
        ->and(app(CourierSettings::class)->pathaoPassword)->toBe('pathao_pw_live_1')
        ->and(app(CourierSettings::class)->pathaoPickupStore)->toBe('11223')
        ->and(Courier::Pathao->isUsable())->toBeTrue();
});

it('saves RedEx\'s token sealed, with the weight and value a parcel it will accept needs', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    Livewire::test(Couriers::class)
        ->fillForm([
            'redexEnabled' => true,
            'redexAccessToken' => 'redex_token_live_1',
            'redexParcelWeight' => 1.5,
            'redexDeclaredValue' => 2500,
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertSet('data.redexAccessToken', null)
        ->assertDontSee('redex_token_live_1');

    $row = (string) DB::table('settings')->where('key', CourierSettings::key())->value('value');

    expect($row)->not->toContain('redex_token_live_1');

    app()->forgetInstance(CourierSettings::class);

    expect(app(CourierSettings::class)->redexAccessToken)->toBe('redex_token_live_1')
        ->and(app(CourierSettings::class)->redexParcelWeight)->toBe(1.5)
        ->and(app(CourierSettings::class)->redexDeclaredValue)->toBe(2500.0)
        ->and(Courier::RedEx->isUsable())->toBeTrue();
});

it('registers several pickup stores for one Courier and names one of them the default', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    Livewire::test(Couriers::class)
        ->fillForm([
            'pathaoEnabled' => true,
            'pathaoClientId' => 'pathao_client_live_1',
            'pathaoClientSecret' => 'pathao_secret_live_1',
            'pathaoUsername' => 'pathao_user_live_1',
            'pathaoPassword' => 'pathao_pw_live_1',
            'pathaoPickupStores' => [
                ['id' => '114983', 'name' => 'Banani warehouse'],
                ['id' => '205417', 'name' => 'Uttara warehouse'],
            ],
            'pathaoPickupStore' => '205417',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetInstance(CourierSettings::class);

    $settings = app(CourierSettings::class);

    expect($settings->pickupStoresFor(Courier::Pathao))->toBe([
        '114983' => 'Banani warehouse',
        '205417' => 'Uttara warehouse',
    ])
        ->and($settings->pathaoPickupStore)->toBe('205417')
        /** A store is one Courier's own id for a warehouse, so registering Pathao's registers nothing for anybody else. */
        ->and($settings->redexPickupStores)->toBe([]);
});

it('refuses the same store id registered twice for one Courier', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    Livewire::test(Couriers::class)
        ->fillForm([
            'redexEnabled' => true,
            'redexAccessToken' => 'redex_token_live_1',
            'redexPickupStores' => [
                ['id' => '98765', 'name' => 'Banani warehouse'],
                ['id' => '98765', 'name' => 'The same one again'],
            ],
        ])
        ->call('save')
        /** Two of the same store is one store the Merchant is offered twice, and choosing between them decides nothing. */
        ->assertHasFormErrors(['redexPickupStores']);

    expect(Setting::query()->find(CourierSettings::key()))->toBeNull();
});

it('moves the default onto a store the merchant still has when they deregister the one it named', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    app(CourierSettings::class)->with([
        'pathaoEnabled' => true,
        'pathaoClientId' => 'pathao_client_live_1',
        'pathaoClientSecret' => 'pathao_secret_live_1',
        'pathaoUsername' => 'pathao_user_live_1',
        'pathaoPassword' => 'pathao_pw_live_1',
        'pathaoPickupStores' => [
            ['id' => '114983', 'name' => 'Banani warehouse'],
            ['id' => '205417', 'name' => 'Uttara warehouse'],
        ],
        'pathaoPickupStore' => '205417',
    ])->save();

    app()->forgetInstance(CourierSettings::class);

    /**
     * Uttara shuts and its row is deleted, which is a write of the whole list
     * and not a fill over it — and the Merchant is not refused over a box they
     * never touched.
     */
    Livewire::test(Couriers::class)
        ->set('data.pathaoPickupStores', [['id' => '114983', 'name' => 'Banani warehouse']])
        ->assertSchemaStateSet(['pathaoPickupStore' => '114983'])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetInstance(CourierSettings::class);

    expect(app(CourierSettings::class)->pathaoPickupStore)->toBe('114983')
        ->and(app(CourierSettings::class)->pickupStoresFor(Courier::Pathao))->toBe(['114983' => 'Banani warehouse']);
});

it('follows a corrected store id, and empties the default when the last store goes', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    app(CourierSettings::class)->with([
        'redexEnabled' => true,
        'redexAccessToken' => 'redex_token_live_1',
        'redexPickupStores' => [['id' => '98765', 'name' => 'Banani warehouse']],
        'redexPickupStore' => '98765',
    ])->save();

    app()->forgetInstance(CourierSettings::class);

    /** A mistyped id corrected: the store the default named is gone and the corrected one takes its place. */
    Livewire::test(Couriers::class)
        ->set('data.redexPickupStores', [['id' => '98764', 'name' => 'Banani warehouse']])
        ->assertSchemaStateSet(['redexPickupStore' => '98764'])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetInstance(CourierSettings::class);

    expect(app(CourierSettings::class)->redexPickupStore)->toBe('98764');

    /** And deregistering the last one leaves no default either: a default naming a store nobody has is worse than none. */
    Livewire::test(Couriers::class)
        ->set('data.redexPickupStores', [])
        ->assertSchemaStateSet(['redexPickupStore' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetInstance(CourierSettings::class);

    expect(app(CourierSettings::class)->redexPickupStore)->toBe('')
        ->and(app(CourierSettings::class)->pickupStoresFor(Courier::RedEx))->toBe([]);
});

it('names the first store the default where the merchant registered stores and named none', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    Livewire::test(Couriers::class)
        ->fillForm([
            'redexEnabled' => true,
            'redexAccessToken' => 'redex_token_live_1',
            'redexPickupStores' => [
                ['id' => '98765', 'name' => 'Banani warehouse'],
                ['id' => '43210', 'name' => 'Uttara warehouse'],
            ],
        ])
        /** Said on the screen rather than settled quietly on save: the Merchant reads where their parcels will leave from. */
        ->assertSchemaStateSet(['redexPickupStore' => '98765'])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetInstance(CourierSettings::class);

    expect(app(CourierSettings::class)->redexPickupStore)->toBe('98765');
});

it('puts the single store an installation already had into the list, with nobody retyping it', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    app(CourierSettings::class)->with(['pathaoPickupStore' => '114983'])->save();
    app()->forgetInstance(CourierSettings::class);

    Livewire::test(Couriers::class)
        ->assertSee('114983')
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetInstance(CourierSettings::class);

    /** Their one store, now registered and still the default: an installation upgrades into this by opening the page. */
    expect(app(CourierSettings::class)->pathaoPickupStores)->toBe([['id' => '114983', 'name' => '114983']])
        ->and(app(CourierSettings::class)->pathaoPickupStore)->toBe('114983');
});

it('keeps the stored credentials when the page is saved with them blank', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    app(CourierSettings::class)->with(['redexAccessToken' => 'redex_token_live_1'])->save();
    app()->forgetInstance(CourierSettings::class);

    Livewire::test(Couriers::class)
        ->assertSee('A value is stored')
        ->fillForm([
            'redexEnabled' => true,
            'redexPickupStores' => [['id' => 'RDX-7', 'name' => 'Gulshan warehouse']],
            'redexPickupStore' => 'RDX-7',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetInstance(CourierSettings::class);

    expect(app(CourierSettings::class)->redexAccessToken)->toBe('redex_token_live_1')
        ->and(app(CourierSettings::class)->redexPickupStore)->toBe('RDX-7')
        ->and(Courier::RedEx->isUsable())->toBeTrue();
});

it('hides the page from a User without the permission and forbids its URL', function () {
    $this->actingAs(User::factory()->create());

    $navigation = filament()->getCurrentOrDefaultPanel()->getNavigation();
    $labels = collect($navigation)->flatMap(fn (NavigationGroup $group): array|Arrayable => $group->getItems())->map->getLabel();

    expect($labels)->not->toContain('Settings')
        ->and(Couriers::canAccess())->toBeFalse();

    $this->get(Couriers::getUrl())->assertForbidden();
});

it('logs nothing when a save changes nothing', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    Livewire::test(Couriers::class)->call('save')->assertHasNoFormErrors();

    expect(Setting::count())->toBe(1)
        ->and(Activity::count())->toBe(0);
});
