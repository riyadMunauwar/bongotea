<?php

use App\Enums\Courier;
use App\Support\Couriers\CourierDriver;
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

it('gives every Courier the same five defaults, so a fourth cannot arrive half-supplied', function (Courier $courier) {
    $settings = app(CourierSettings::class);

    expect(CourierSettings::DEFAULTS)->toHaveCount(5);

    foreach (CourierSettings::DEFAULTS as $suffix) {
        $property = CourierSettings::property($courier, $suffix);

        expect(property_exists($settings, $property))->toBeTrue("[{$property}] is not declared.")
            ->and(CourierSettings::isSecret($property))->toBeFalse("[{$property}] is a default, not a credential.");
    }

    expect(CourierSettings::hasSettings($courier))->toBeTrue();
})->with('couriers');

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
        ->and($settings->redexPickupStore)->toBe('')
        ->and($settings->redexItemType)->toBe('');

    storeCourierSettings(['redexPickupStore' => 'RDX-STORE-7', 'redexParcelWeight' => 1.25]);

    expect(app(CourierSettings::class)->redexPickupStore)->toBe('RDX-STORE-7')
        ->and(app(CourierSettings::class)->redexParcelWeight)->toBe(1.25)
        /** Untouched by the save, and so still the config value rather than a blank. */
        ->and(app(CourierSettings::class)->redexDeclaredValue)->toBe(1000.0);
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

    expect($pathao->supportsBulk())->toBeTrue()
        ->and($pathao->supportsWebhook())->toBeTrue()
        ->and($pathao->supportsBalance())->toBeFalse()
        ->and($pathao->needsAreaResolution())->toBeFalse()
        ->and($pathao->batchLimit())->toBeGreaterThan(1);

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
