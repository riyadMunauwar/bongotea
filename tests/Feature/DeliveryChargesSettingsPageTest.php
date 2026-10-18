<?php

use App\Filament\Clusters\Settings\Pages\DeliveryCharges;
use App\Models\Setting;
use App\Models\User;
use App\Support\Pricing\PriceBook;
use App\Support\Pricing\RetailPriceBook;
use App\Support\Settings\DeliveryChargeSettings;
use Filament\Support\Icons\Heroicon;
use Livewire\Livewire;

/**
 * The Delivery Zones a Merchant edits (ADR-0039), as Bulk Delivery Charges
 * and Retail Delivery Charges, and the rules they answer to: one zone
 * everywhere else, no District twice, and each zone's ranges covering every
 * whole kilogram from one, once. Tiers belong to each Product (ADR-0031) and
 * are not set here. What a Cart then costs is the shared worked examples'
 * business (`PricingExamplesTest`), not this file's.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->managingSettings()->create());
});

/**
 * A zone as the form types it: whole taka, strings.
 *
 * @param  list<array{0: string, 1: string, 2: string}>  $bands
 * @param  list<string>  $districts
 */
function zoneRow(string $name, array $bands, array $districts = [], bool $everywhereElse = false, ?string $freeFrom = null): array
{
    return [
        'name' => $name,
        'everywhereElse' => $everywhereElse,
        'districts' => $districts,
        'bands' => array_map(fn (array $band): array => ['min' => $band[0], 'max' => $band[1], 'fee' => $band[2]], $bands),
        'freeDeliveryFrom' => $freeFrom,
    ];
}

it('sits right after Store in the Settings cluster, and is forbidden to a User who cannot manage settings', function () {
    expect(DeliveryCharges::getNavigationSort())->toBe(2);

    $this->get(DeliveryCharges::getUrl())->assertOk()->assertSeeLivewire(DeliveryCharges::class);

    $this->actingAs(User::factory()->create())
        ->get(DeliveryCharges::getUrl())
        ->assertForbidden();
});

it('is called Delivery Charges, drawn with the scale, and holds Bulk Delivery Charges and Retail Delivery Charges', function () {
    expect(DeliveryCharges::getNavigationLabel())->toBe('Delivery Charges')
        ->and(DeliveryCharges::getNavigationIcon())->toBe(Heroicon::OutlinedScale);

    $this->get(DeliveryCharges::getUrl())
        ->assertSeeText('Bulk Delivery Charges')
        ->assertSeeText('Retail Delivery Charges')
        ->assertDontSeeText('Delivery Bands');
});

it('opens a fresh store on Inside Dhaka and Outside Dhaka for each kind, with fees in whole taka', function () {
    $component = Livewire::test(DeliveryCharges::class);
    $retail = array_values($component->get('data.retailZones'));

    expect(array_column(array_values($component->get('data.bulkZones')), 'name'))->toBe(['Inside Dhaka', 'Outside Dhaka'])
        ->and(array_column($retail, 'name'))->toBe(['Inside Dhaka', 'Outside Dhaka'])
        ->and(array_column(array_values($retail[0]['bands']), 'fee'))->toEqual([80])
        ->and(array_column(array_values($retail[1]['bands']), 'fee'))->toEqual([130]);
});

it('saves zones, which is what every Cart of that kind is then charged by', function () {
    Livewire::test(DeliveryCharges::class)
        ->set('data.bulkZones', [
            zoneRow('Dhaka city', [['1', '24', '180'], ['25', '', '0']], ['Dhaka']),
            zoneRow('Elsewhere', [['1', '', '300']], everywhereElse: true),
        ])
        ->set('data.retailZones', [
            zoneRow('Dhaka city', [['1', '2', '70'], ['3', '', '90']], ['Dhaka', 'Gazipur'], freeFrom: '1500'),
            zoneRow('Elsewhere', [['1', '', '130']], everywhereElse: true),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetInstance(DeliveryChargeSettings::class);
    $bulk = PriceBook::current()->deliveryZones;
    $retail = RetailPriceBook::current()->deliveryZones;

    expect($bulk->for('Dhaka')->bands[0]->fee)->toBe(18000)
        ->and($bulk->for('Sylhet')->name)->toBe('Elsewhere')
        ->and($retail->for('Gazipur')->bandFor(3)->fee)->toBe(9000)
        ->and($retail->for('Gazipur')->freeDeliveryFrom)->toBe(150000)
        ->and($retail->for('Sylhet')->freeDeliveryFrom)->toBeNull();
});

it('refuses zones that leave a District or a weight with no charge, or charge one twice', function (array $zones) {
    Livewire::test(DeliveryCharges::class)
        ->set('data.bulkZones', $zones)
        ->call('save')
        ->assertHasFormErrors(['bulkZones']);

    expect(Setting::query()->find(DeliveryChargeSettings::key()))->toBeNull();
})->with([
    'no zone everywhere else' => [[zoneRow('Dhaka', [['1', '', '80']], ['Dhaka'])]],
    'two zones everywhere else' => [[zoneRow('A', [['1', '', '80']], everywhereElse: true), zoneRow('B', [['1', '', '80']], everywhereElse: true)]],
    'one District in two zones' => [[zoneRow('A', [['1', '', '80']], ['Dhaka']), zoneRow('B', [['1', '', '80']], ['Dhaka', 'Gazipur']), zoneRow('C', [['1', '', '80']], everywhereElse: true)]],
    'two zones of one name' => [[zoneRow('Dhaka', [['1', '', '80']], ['Dhaka']), zoneRow('dhaka', [['1', '', '80']], everywhereElse: true)]],
    'a zone listing no District' => [[zoneRow('Dhaka', [['1', '', '80']]), zoneRow('Elsewhere', [['1', '', '80']], everywhereElse: true)]],
    'a gap in a zone\'s ranges' => [[zoneRow('Elsewhere', [['1', '5', '120'], ['7', '', '0']], everywhereElse: true)]],
    'an overlap in a zone\'s ranges' => [[zoneRow('Elsewhere', [['1', '5', '120'], ['5', '', '0']], everywhereElse: true)]],
    'a closed last range' => [[zoneRow('Elsewhere', [['1', '5', '120'], ['6', '10', '0']], everywhereElse: true)]],
    'nothing at all' => [[]],
]);

it('reads a stored list that breaks the rules as the list already in force, and ignores the Tiers a row still carries', function () {
    Setting::query()->create(['key' => DeliveryChargeSettings::key(), 'value' => [
        'tiers' => [['key' => 'a', 'min' => 1, 'max' => 5], ['key' => 'b', 'min' => 6, 'max' => null]],
        'bulkZones' => [['name' => 'Dhaka', 'districts' => ['Dhaka'], 'everywhereElse' => false, 'bands' => [['min' => 1, 'max' => null, 'fee' => 100]]]],
        'retailZones' => 'not a list',
    ]]);

    $settings = app(DeliveryChargeSettings::class);

    expect(array_column($settings->bulkZones, 'name'))->toBe(['Inside Dhaka', 'Outside Dhaka'])
        ->and(array_column($settings->retailZones, 'name'))->toBe(['Inside Dhaka', 'Outside Dhaka']);
});

it('reads an empty free-delivery threshold as that zone never being free', function () {
    Livewire::test(DeliveryCharges::class)
        ->set('data.retailZones', [zoneRow('Elsewhere', [['1', '', '60']], everywhereElse: true, freeFrom: '')])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetInstance(DeliveryChargeSettings::class);

    expect(RetailPriceBook::current()->deliveryZones->for('Dhaka')->freeDeliveryFrom)->toBeNull();
});

it('refuses a negative Delivery Charge', function () {
    Livewire::test(DeliveryCharges::class)
        ->set('data.retailZones', [zoneRow('Elsewhere', [['1', '', '-1']], everywhereElse: true)])
        ->call('save')
        ->assertHasErrors();

    expect(Setting::query()->find(DeliveryChargeSettings::key()))->toBeNull();
});
