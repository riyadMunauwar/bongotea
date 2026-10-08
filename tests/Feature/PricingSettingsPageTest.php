<?php

use App\Filament\Clusters\Settings\Pages\Pricing;
use App\Models\Setting;
use App\Models\User;
use App\Support\Pricing\PriceBook;
use App\Support\Pricing\Tier;
use App\Support\Settings\PricingSettings;
use Livewire\Livewire;

/**
 * The Tiers and Delivery Bands a Merchant edits, and the one rule both answer
 * to: every whole kilogram from one, once. What a Cart then costs is the
 * shared worked examples' business (`PricingExamplesTest`), not this file's.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->managingSettings()->create());
});

it('sits after Menus in the Settings cluster, and is forbidden to a User who cannot manage settings', function () {
    expect(Pricing::getNavigationSort())->toBe(4);

    $this->get(Pricing::getUrl())->assertOk()->assertSeeLivewire(Pricing::class);

    $this->actingAs(User::factory()->create())
        ->get(Pricing::getUrl())
        ->assertForbidden();
});

it('opens on the mockup tables, with fees in whole taka', function () {
    $component = Livewire::test(Pricing::class);

    expect(array_values($component->get('data.tiers')))->toHaveCount(6)
        ->and(array_values($component->get('data.tiers'))[5])->toMatchArray(['key' => 'tier-6', 'min' => 51, 'max' => null])
        ->and(array_column(array_values($component->get('data.deliveryBands')), 'fee'))->toEqual([120, 150, 200, 250, 0]);
});

it('saves new Tiers and Delivery Bands, which is what every Cart is then priced from', function () {
    Livewire::test(Pricing::class)
        ->set('data.tiers', [
            ['key' => 'tier-1', 'min' => '1', 'max' => '9'],
            ['key' => 'tier-2', 'min' => '10', 'max' => ''],
        ])
        ->set('data.deliveryBands', [
            ['min' => '1', 'max' => '24', 'fee' => '180'],
            ['min' => '25', 'max' => '', 'fee' => '0'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetInstance(PricingSettings::class);
    $book = PriceBook::current();

    expect(array_map(fn (Tier $tier): array => $tier->toArray(), $book->tiers))->toBe([
        ['key' => 'tier-1', 'min' => 1, 'max' => 9],
        ['key' => 'tier-2', 'min' => 10, 'max' => null],
    ])
        ->and($book->deliveryBands[0]->fee)->toBe(18000)
        ->and($book->deliveryBands[1]->isFree())->toBeTrue();
});

it('gives a Tier added on the page a key of its own', function () {
    Livewire::test(Pricing::class)
        ->set('data.tiers', [
            ['key' => 'tier-1', 'min' => '1', 'max' => '5'],
            ['min' => '6', 'max' => ''],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetInstance(PricingSettings::class);
    $keys = array_column(app(PricingSettings::class)->tiers, 'key');

    expect($keys[0])->toBe('tier-1')
        ->and($keys[1])->toBeString()->not->toBe('')->not->toBe('tier-1');
});

it('refuses a gap, an overlap, a closed last range and an open one in the middle', function (array $tiers) {
    Livewire::test(Pricing::class)
        ->set('data.tiers', $tiers)
        ->call('save')
        ->assertHasFormErrors(['tiers']);

    expect(Setting::query()->find(PricingSettings::key()))->toBeNull();
})->with([
    'a gap' => [[['key' => 'a', 'min' => '1', 'max' => '5'], ['key' => 'b', 'min' => '7', 'max' => '']]],
    'an overlap' => [[['key' => 'a', 'min' => '1', 'max' => '5'], ['key' => 'b', 'min' => '5', 'max' => '']]],
    'not starting at one' => [[['key' => 'a', 'min' => '2', 'max' => '']]],
    'a closed last range' => [[['key' => 'a', 'min' => '1', 'max' => '5'], ['key' => 'b', 'min' => '6', 'max' => '10']]],
    'open in the middle' => [[['key' => 'a', 'min' => '1', 'max' => ''], ['key' => 'b', 'min' => '6', 'max' => '']]],
    'nothing at all' => [[]],
]);

it('refuses Delivery Bands with a gap', function () {
    Livewire::test(Pricing::class)
        ->set('data.deliveryBands', [
            ['min' => '1', 'max' => '5', 'fee' => '120'],
            ['min' => '8', 'max' => '', 'fee' => '0'],
        ])
        ->call('save')
        ->assertHasFormErrors(['deliveryBands']);

    expect(Setting::query()->find(PricingSettings::key()))->toBeNull();
});

it('reads a stored table that leaves a weight unpriced as the defaults, rather than pricing nothing', function () {
    Setting::query()->create(['key' => PricingSettings::key(), 'value' => [
        'tiers' => [['key' => 'a', 'min' => 1, 'max' => 5], ['key' => 'b', 'min' => 9, 'max' => null]],
        'deliveryBands' => 'not a list',
    ]]);

    $settings = app(PricingSettings::class);

    expect(array_column($settings->tiers, 'key'))->toBe(['tier-1', 'tier-2', 'tier-3', 'tier-4', 'tier-5', 'tier-6'])
        ->and($settings->deliveryBands)->toHaveCount(5);
});
