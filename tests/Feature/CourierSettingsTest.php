<?php

use App\Enums\Courier;
use App\Support\Couriers\CourierDriver;
use App\Support\Couriers\ParcelField;
use App\Support\Settings\CourierSettings;
use Illuminate\Support\Facades\DB;

/**
 * Save the Courier settings the way a Merchant already did, and forget the
 * request's copy so the next read comes from the row.
 *
 * @param  array<string, mixed>  $values
 */
function storeCourierSettings(array $values): void
{
    app(CourierSettings::class)->with($values)->save();

    app()->forgetInstance(CourierSettings::class);
}

/**
 * Every Courier with its credentials filled in, so a test about one thing
 * does not have to type seven values to get there.
 *
 * @return array<string, mixed>
 */
function everyCourierSupplied(): array
{
    $values = [];

    foreach (Courier::cases() as $courier) {
        $values[CourierSettings::property($courier, 'Enabled')] = true;

        foreach (app(CourierSettings::class)->credentialsFor($courier) as $credential) {
            $values[$credential] = 'supplied';
        }
    }

    return $values;
}

it('starts with every Courier off, nothing typed, and no Courier offered', function () {
    $settings = app(CourierSettings::class);

    expect($settings->steadfastEnabled)->toBeFalse()
        ->and($settings->pathaoEnabled)->toBeFalse()
        ->and($settings->redexEnabled)->toBeFalse()
        ->and($settings->steadfastApiKey)->toBe('')
        ->and($settings->steadfastSecretKey)->toBe('')
        ->and($settings->pathaoClientId)->toBe('')
        ->and($settings->pathaoClientSecret)->toBe('')
        ->and($settings->pathaoUsername)->toBe('')
        ->and($settings->pathaoPassword)->toBe('')
        ->and($settings->redexAccessToken)->toBe('')
        ->and($settings->enabledCouriers())->toBe([])
        ->and($settings->usableCouriers())->toBe([]);
});

it('names every Courier\'s credentials as secrets, and no parcel default among them', function () {
    $settings = app(CourierSettings::class);

    expect(CourierSettings::secrets())->toBe([
        'steadfastApiKey',
        'steadfastSecretKey',
        'pathaoClientId',
        'pathaoClientSecret',
        'pathaoUsername',
        'pathaoPassword',
        'redexAccessToken',
    ])
        ->and($settings->credentialsFor(Courier::Steadfast))->toBe(['steadfastApiKey', 'steadfastSecretKey'])
        ->and($settings->credentialsFor(Courier::Pathao))->toBe(['pathaoClientId', 'pathaoClientSecret', 'pathaoUsername', 'pathaoPassword'])
        ->and($settings->credentialsFor(Courier::RedEx))->toBe(['redexAccessToken']);
});

it('spells each driver\'s credentials exactly as the settings group does', function (Courier $courier) {
    expect($courier->driver()->credentialProperties())
        ->toBe(app(CourierSettings::class)->credentialsFor($courier))
        ->each->toBeIn(CourierSettings::secrets());
})->with('couriers');

it('holds exactly the defaults each driver sends, so no default is one nothing reads', function (Courier $courier) {
    $settings = app(CourierSettings::class);

    $declared = array_map(fn (ParcelField $field): string => $field->settingsProperty, $courier->driver()->parcelFields());

    $held = array_values(array_filter(
        array_map(fn (string $suffix): string => CourierSettings::property($courier, $suffix), CourierSettings::DEFAULTS),
        fn (string $property): bool => property_exists($settings, $property),
    ));

    expect($held)->toEqualCanonicalizing($declared)
        ->each->not->toBeIn(CourierSettings::secrets())
        ->and(CourierSettings::hasSettings($courier))->toBeTrue();
})->with('couriers');

it('keeps no default for a parcel setting its driver never sends', function () {
    $settings = app(CourierSettings::class);

    foreach (['steadfastPickupStore', 'steadfastPickupStores', 'steadfastParcelWeight', 'steadfastDeclaredValue', 'pathaoDeclaredValue', 'redexDeliveryType', 'redexItemType'] as $property) {
        expect(property_exists($settings, $property))->toBeFalse("[{$property}] is still declared.");
    }

    expect(config('giopages.couriers.steadfast'))->toBe(['delivery_type' => '0', 'item_type' => ''])
        ->and(config('giopages.couriers.pathao'))->not->toHaveKey('declared_value')
        ->and(config('giopages.couriers.redex'))->not->toHaveKeys(['delivery_type', 'item_type']);
});

it('refuses a Courier that is switched off, then one still missing a credential, in one wording', function (Courier $courier) {
    $label = $courier->getLabel();
    $credentials = app(CourierSettings::class)->credentialsFor($courier);

    expect($courier->refusal())->toBe("{$label} is switched off in Settings.");

    /** On, and every credential but the last one typed: still not offered, and the reason has moved on. */
    storeCourierSettings([
        CourierSettings::property($courier, 'Enabled') => true,
        ...array_fill_keys(array_slice($credentials, 0, -1), 'supplied'),
    ]);

    expect($courier->refusal())->toBe("{$label} has blank credentials in Settings.")
        ->and($courier->isUsable())->toBeFalse()
        ->and(app(CourierSettings::class)->usableCouriers())->not->toContain($courier);

    storeCourierSettings([array_slice($credentials, -1)[0] => 'supplied']);

    expect($courier->refusal())->toBeNull()
        ->and($courier->isUsable())->toBeTrue()
        ->and(app(CourierSettings::class)->usableCouriers())->toContain($courier);
})->with('couriers');

it('asks the driver for the refusal, and the driver asks the one place the rule lives', function () {
    storeCourierSettings(everyCourierSupplied());

    expect(app(CourierSettings::class)->usableCouriers())->toBe([Courier::Steadfast, Courier::Pathao, Courier::RedEx]);

    storeCourierSettings(['pathaoPassword' => '']);

    $settings = app(CourierSettings::class);

    expect(Courier::Pathao->driver()->refusal())
        ->toBe($settings->refusal(Courier::Pathao))
        ->toBe(Courier::Pathao->refusal())
        ->toBe('Pathao has blank credentials in Settings.')
        ->and($settings->enabledCouriers())->toBe([Courier::Steadfast, Courier::Pathao, Courier::RedEx])
        ->and(app(CourierSettings::class)->usableCouriers())->toBe([Courier::Steadfast, Courier::RedEx]);
});

it('takes the parcel defaults from config, and lets a saved row override one without touching the others', function () {
    config(['giopages.couriers.redex.parcel_weight' => 2.5]);
    config(['giopages.couriers.pathao.delivery_type' => '12']);

    app()->forgetInstance(CourierSettings::class);

    $settings = app(CourierSettings::class);

    expect($settings->redexParcelWeight)->toBe(2.5)
        ->and($settings->pathaoDeliveryType)->toBe('12')
        ->and($settings->pathaoItemType)->toBe('2')
        ->and($settings->steadfastDeliveryType)->toBe('0')
        ->and($settings->redexDeclaredValue)->toBe(1000.0)
        ->and($settings->redexPickupStore)->toBe('');

    storeCourierSettings(['redexPickupStore' => 'RDX-STORE-7', 'redexParcelWeight' => 1.25]);

    expect(app(CourierSettings::class)->redexPickupStore)->toBe('RDX-STORE-7')
        ->and(app(CourierSettings::class)->redexParcelWeight)->toBe(1.25)
        /** Untouched by the save, and so still the config value rather than a blank. */
        ->and(app(CourierSettings::class)->redexDeclaredValue)->toBe(1000.0);
});

it('reads the single store an installation already had as its one registered store, and asks nobody about it', function () {
    storeCourierSettings(['pathaoPickupStore' => '114983']);

    $settings = app(CourierSettings::class);

    /** Nothing migrated and nobody retyped anything: the one store a Merchant had is the one they have. */
    expect($settings->pathaoPickupStores)->toBe([])
        ->and($settings->pickupStoresFor(Courier::Pathao))->toBe(['114983' => '114983'])
        ->and($settings->pathaoPickupStore)->toBe('114983');

    $store = ParcelField::pickupStore(Courier::Pathao, $settings);

    expect($store->isWorthAsking())->toBeFalse()
        ->and($store->default($settings))->toBe('114983');
});

it('registers a store under the id its own Courier knows it by, and lets no Courier read another\'s', function () {
    storeCourierSettings([
        'pathaoPickupStores' => [
            ['id' => '114983', 'name' => 'Banani warehouse'],
            ['id' => '205417', 'name' => 'Uttara warehouse'],
        ],
        'pathaoPickupStore' => '205417',
        'redexPickupStores' => [['id' => '98765', 'name' => 'Banani warehouse']],
    ]);

    $settings = app(CourierSettings::class);

    /** The same warehouse is a different number at each Courier, which is why these are lists per Courier and not one list of warehouses. */
    expect($settings->pickupStoresFor(Courier::Pathao))->toBe(['114983' => 'Banani warehouse', '205417' => 'Uttara warehouse'])
        ->and($settings->pickupStoresFor(Courier::RedEx))->toBe(['98765' => 'Banani warehouse'])
        ->and($settings->pickupStoresFor(Courier::Steadfast))->toBe([])
        /** The Merchant's own, kept: registering a second store widens the choice, it does not move the default. */
        ->and($settings->pathaoPickupStore)->toBe('205417');

    /** Two stores is a question worth putting; one still is not, at the same Courier's neighbour on the same installation. */
    expect(ParcelField::pickupStore(Courier::Pathao, $settings)->isWorthAsking())->toBeTrue()
        ->and(ParcelField::pickupStore(Courier::Pathao, $settings)->default($settings))->toBe('205417')
        ->and(ParcelField::pickupStore(Courier::RedEx, $settings)->isWorthAsking())->toBeFalse();
});

it('points a Courier\'s default at a store it still has when the one it named is deregistered', function () {
    storeCourierSettings([
        'pathaoPickupStores' => [
            ['id' => '114983', 'name' => 'Banani warehouse'],
            ['id' => '205417', 'name' => 'Uttara warehouse'],
        ],
        'pathaoPickupStore' => '205417',
    ]);

    expect(app(CourierSettings::class)->pathaoPickupStore)->toBe('205417');

    /** Uttara shuts. A Merchant taking it off the list has not decided that the next parcel leaves from nowhere. */
    storeCourierSettings(['pathaoPickupStores' => [['id' => '114983', 'name' => 'Banani warehouse']]]);

    expect(app(CourierSettings::class)->pathaoPickupStore)->toBe('114983');

    /** And deregistering the lot leaves the last one standing, rather than a Courier nothing can be sent to. */
    storeCourierSettings(['pathaoPickupStores' => []]);

    expect(app(CourierSettings::class)->pathaoPickupStore)->toBe('114983')
        ->and(app(CourierSettings::class)->pickupStoresFor(Courier::Pathao))->toBe(['114983' => '114983']);
});

it('drops a row that is not a store, and names an unnamed one after the id its Courier knows it by', function () {
    storeCourierSettings([
        'redexPickupStores' => [
            ['id' => ' 98765 ', 'name' => '  '],
            ['id' => '', 'name' => 'A warehouse with no id RedEx could collect from'],
            ['id' => '98765', 'name' => 'The same store typed twice'],
            'not a store at all',
            ['id' => '43210', 'name' => 'Uttara warehouse'],
        ],
    ]);

    $settings = app(CourierSettings::class);

    expect($settings->redexPickupStores)->toBe([
        ['id' => '98765', 'name' => '98765'],
        ['id' => '43210', 'name' => 'Uttara warehouse'],
    ])
        ->and($settings->pickupStoresFor(Courier::RedEx))->toBe(['98765' => '98765', '43210' => 'Uttara warehouse'])
        /** The first registered, because the Merchant named none and a Courier that insists on a store must still be told one. */
        ->and($settings->redexPickupStore)->toBe('98765');
});

it('reads a stores row holding nothing of the shape it promises as nothing registered, rather than throwing', function () {
    DB::table('settings')->insert(['key' => CourierSettings::key(), 'value' => json_encode([
        'pathaoPickupStores' => 'a warehouse',
        'pathaoPickupStore' => '114983',
    ])]);

    $settings = app(CourierSettings::class);

    /** A row is the one input that must never throw: a TypeError from load() would take down the page that could repair it. */
    expect($settings->pathaoPickupStores)->toBe([])
        ->and($settings->pickupStoresFor(Courier::Pathao))->toBe(['114983' => '114983']);
});

it('declares what each Courier can be asked to do, so nothing branches on its name', function () {
    $steadfast = Courier::Steadfast->driver();
    $pathao = Courier::Pathao->driver();
    $redex = Courier::RedEx->driver();

    expect($steadfast->supportsBulk())->toBeTrue()
        ->and($steadfast->supportsWebhook())->toBeFalse()
        ->and($steadfast->supportsBalance())->toBeTrue()
        ->and($steadfast->needsAreaResolution())->toBeFalse()
        ->and($steadfast->batchLimit())->toBe(500)
        /** Nothing arrives on its own, so it is asked far more often than the other two. */
        ->and($steadfast->reAskIntervalSeconds())->toBe(1800);

    expect($pathao->supportsBulk())->toBeFalse()
        ->and($pathao->supportsWebhook())->toBeTrue()
        ->and($pathao->supportsBalance())->toBeFalse()
        ->and($pathao->needsAreaResolution())->toBeFalse()
        /** Pathao's bulk endpoint hands back no Consignment per parcel, so it is offered one at a time like RedEx. */
        ->and($pathao->batchLimit())->toBe(1);

    expect($redex->supportsBulk())->toBeFalse()
        ->and($redex->supportsWebhook())->toBeTrue()
        ->and($redex->supportsBalance())->toBeFalse()
        ->and($redex->needsAreaResolution())->toBeTrue()
        /** One parcel a call, declared rather than absent, so a batch is the same loop for every Courier. */
        ->and($redex->batchLimit())->toBe(1);

    expect($pathao->reAskIntervalSeconds())->toBeGreaterThan($steadfast->reAskIntervalSeconds())
        ->and($redex->reAskIntervalSeconds())->toBeGreaterThan($steadfast->reAskIntervalSeconds());
});

it('is the registry: every case resolves to a driver that names it back', function (Courier $courier) {
    $driver = $courier->driver();

    expect($driver)->toBeInstanceOf(CourierDriver::class)
        ->and($driver->courier())->toBe($courier)
        ->and(Courier::fromStored($courier->value))->toBe($courier)
        ->and($courier->getLabel())->not->toBe('');
})->with('couriers');

it('reads a name it does not know, and a name that is not a name at all, as nothing', function () {
    expect(Courier::fromStored('dhl'))->toBeNull()
        ->and(Courier::fromStored(null))->toBeNull()
        ->and(Courier::fromStored(['steadfast']))->toBeNull();
});

it('reads a row damaged by hand without throwing, so the page that could repair it still opens', function () {
    storeCourierSettings([]);

    DB::table('settings')->where('key', CourierSettings::key())->update(['value' => json_encode([
        'steadfastEnabled' => 'yes',
        'redexParcelWeight' => null,
        'pathaoDeliveryType' => 12,
        'aDoorThatIsNoLongerHere' => 'ignored',
    ])]);

    $settings = app(CourierSettings::class);

    expect($settings->steadfastEnabled)->toBeTrue()
        ->and($settings->redexParcelWeight)->toBe(0.0)
        ->and($settings->pathaoDeliveryType)->toBe('12')
        /** A key the class no longer has is dropped, and one the row never mentioned keeps its config default. */
        ->and($settings->redexDeclaredValue)->toBe(1000.0);
});

it('loads a row still holding defaults no driver sends, and ignores them', function () {
    storeCourierSettings([]);

    DB::table('settings')->where('key', CourierSettings::key())->update(['value' => json_encode([
        'steadfastPickupStore' => 'SF-1',
        'steadfastPickupStores' => [['id' => 'SF-1', 'name' => 'Banani']],
        'steadfastParcelWeight' => 2.0,
        'steadfastDeclaredValue' => 500.0,
        'pathaoDeclaredValue' => 500.0,
        'redexDeliveryType' => 'express',
        'redexItemType' => 'fragile',
        'pathaoItemType' => '1',
    ])]);

    $settings = app(CourierSettings::class);

    expect($settings->pathaoItemType)->toBe('1')
        ->and($settings->pickupStoresFor(Courier::Steadfast))->toBe([]);
});
