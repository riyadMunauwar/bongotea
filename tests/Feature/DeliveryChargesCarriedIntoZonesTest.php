<?php

use App\Models\Product;
use App\Support\Pricing\PriceBook;
use App\Support\Pricing\RetailPriceBook;
use App\Support\Settings\DeliveryChargeSettings;
use Illuminate\Support\Facades\DB;

/**
 * A store already charging when Delivery Zones arrived (ADR-0039) keeps
 * charging exactly what it did: its national bands and its flat retail charge
 * become one "everywhere else" zone each. A fresh installation opens on the
 * new defaults instead.
 */
function carryIntoZones(): void
{
    (require database_path('migrations/2026_10_18_100639_carry_delivery_charges_into_zones.php'))->up();
    app()->forgetInstance(DeliveryChargeSettings::class);
}

it('carries saved bands and the retail charge into one everywhere-else zone each', function () {
    DB::table('settings')->insert(['key' => 'pricing', 'value' => json_encode([
        'deliveryBands' => [['min' => 1, 'max' => 9, 'fee' => 9900], ['min' => 10, 'max' => null, 'fee' => 0]],
        'retailDeliveryCharge' => 7000,
        'retailFreeDeliveryFrom' => null,
    ]), 'created_at' => now(), 'updated_at' => now()]);

    carryIntoZones();

    $bulk = PriceBook::current()->deliveryZones;
    $retail = RetailPriceBook::current()->deliveryZones;

    expect($bulk->zones)->toHaveCount(1)
        ->and($bulk->for('Dhaka')->bandFor(9)->fee)->toBe(9900)
        ->and($bulk->for('Sylhet')->bandFor(10)->isFree())->toBeTrue()
        ->and($retail->zones)->toHaveCount(1)
        ->and($retail->for('Dhaka')->chargeFor(3))->toBe(7000)
        ->and($retail->for('Dhaka')->freeDeliveryFrom)->toBeNull();
});

it('carries the old defaults for a store that sold without ever saving its Delivery Charges', function () {
    Product::factory()->create();

    carryIntoZones();

    $retail = RetailPriceBook::current()->deliveryZones;

    expect(PriceBook::current()->deliveryZones->for('Dhaka')->bandFor(6)->fee)->toBe(15000)
        ->and($retail->zones)->toHaveCount(1)
        ->and($retail->for('Sylhet')->chargeFor(1))->toBe(6000)
        ->and($retail->for('Sylhet')->freeDeliveryFrom)->toBe(100000);
});

it('leaves a fresh installation on the new defaults', function () {
    carryIntoZones();

    expect(DB::table('settings')->where('key', 'pricing')->exists())->toBeFalse()
        ->and(RetailPriceBook::current()->deliveryZones->for('Dhaka')->name)->toBe('Inside Dhaka');
});
