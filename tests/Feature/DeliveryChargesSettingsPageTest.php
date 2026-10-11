<?php

use App\Filament\Clusters\Settings\Pages\DeliveryCharges;
use App\Models\Setting;
use App\Models\User;
use App\Support\Pricing\PriceBook;
use App\Support\Settings\DeliveryChargeSettings;
use Filament\Support\Icons\Heroicon;
use Livewire\Livewire;

/**
 * The Delivery Bands a Merchant edits, and the rule they answer to: every
 * whole kilogram from one, once. Tiers belong to each Product (ADR-0031) and
 * are not set here. What a Cart then costs is the shared worked examples'
 * business (`PricingExamplesTest`), not this file's.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->managingSettings()->create());
});

it('sits right after Store in the Settings cluster, and is forbidden to a User who cannot manage settings', function () {
    expect(DeliveryCharges::getNavigationSort())->toBe(2);

    $this->get(DeliveryCharges::getUrl())->assertOk()->assertSeeLivewire(DeliveryCharges::class);

    $this->actingAs(User::factory()->create())
        ->get(DeliveryCharges::getUrl())
        ->assertForbidden();
});

it('is called Delivery Charges and drawn with the scale', function () {
    expect(DeliveryCharges::getNavigationLabel())->toBe('Delivery Charges')
        ->and(DeliveryCharges::getNavigationIcon())->toBe(Heroicon::OutlinedScale);

    $this->get(DeliveryCharges::getUrl())->assertSeeText('Delivery Charges')->assertSeeText('Delivery Bands');
});

it('still reads the Delivery Bands a Merchant saved under the old Pricing key, on the page and in every Cart', function () {
    Setting::query()->create(['key' => 'pricing', 'value' => ['deliveryBands' => [
        ['min' => 1, 'max' => 9, 'fee' => 9900],
        ['min' => 10, 'max' => null, 'fee' => 0],
    ]]]);

    expect(array_column(array_values(Livewire::test(DeliveryCharges::class)->get('data.deliveryBands')), 'fee'))->toEqual([99, 0])
        ->and(PriceBook::current()->deliveryBands[0]->fee)->toBe(9900);
});

it('opens on the mockup Delivery Bands, with fees in whole taka, and asks nothing about Tiers', function () {
    $component = Livewire::test(DeliveryCharges::class);

    expect(array_column(array_values($component->get('data.deliveryBands')), 'fee'))->toEqual([120, 150, 200, 250, 0])
        ->and($component->get('data.tiers'))->toBeNull();
});

it('saves new Delivery Bands, which is what every Cart is then charged by', function () {
    Livewire::test(DeliveryCharges::class)
        ->set('data.deliveryBands', [
            ['min' => '1', 'max' => '24', 'fee' => '180'],
            ['min' => '25', 'max' => '', 'fee' => '0'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetInstance(DeliveryChargeSettings::class);
    $book = PriceBook::current();

    expect($book->deliveryBands[0]->fee)->toBe(18000)
        ->and($book->deliveryBands[1]->isFree())->toBeTrue();
});

it('refuses a gap, an overlap, a closed last band and an open one in the middle', function (array $bands) {
    Livewire::test(DeliveryCharges::class)
        ->set('data.deliveryBands', $bands)
        ->call('save')
        ->assertHasFormErrors(['deliveryBands']);

    expect(Setting::query()->find(DeliveryChargeSettings::key()))->toBeNull();
})->with([
    'a gap' => [[['min' => '1', 'max' => '5', 'fee' => '120'], ['min' => '7', 'max' => '', 'fee' => '0']]],
    'an overlap' => [[['min' => '1', 'max' => '5', 'fee' => '120'], ['min' => '5', 'max' => '', 'fee' => '0']]],
    'not starting at one' => [[['min' => '2', 'max' => '', 'fee' => '0']]],
    'a closed last band' => [[['min' => '1', 'max' => '5', 'fee' => '120'], ['min' => '6', 'max' => '10', 'fee' => '0']]],
    'open in the middle' => [[['min' => '1', 'max' => '', 'fee' => '120'], ['min' => '6', 'max' => '', 'fee' => '0']]],
    'nothing at all' => [[]],
]);

it('reads a stored table that leaves a weight uncharged as the defaults, and ignores the Tiers a row still carries', function () {
    Setting::query()->create(['key' => DeliveryChargeSettings::key(), 'value' => [
        'tiers' => [['key' => 'a', 'min' => 1, 'max' => 5], ['key' => 'b', 'min' => 6, 'max' => null]],
        'deliveryBands' => 'not a list',
    ]]);

    expect(app(DeliveryChargeSettings::class)->deliveryBands)->toHaveCount(5);
});
