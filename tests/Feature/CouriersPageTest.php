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
        /** The five defaults, once per Courier, are what make an unattended booking possible. */
        ->assertSee('Pickup store')
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

it('keeps the stored credentials when the page is saved with them blank', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    app(CourierSettings::class)->with(['redexAccessToken' => 'redex_token_live_1'])->save();
    app()->forgetInstance(CourierSettings::class);

    Livewire::test(Couriers::class)
        ->assertSee('A value is stored')
        ->fillForm(['redexEnabled' => true, 'redexPickupStore' => 'RDX-7'])
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
