<?php

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Product;
use App\Support\Reporting\ReportingPreset;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\ReportingWindow;
use App\Support\Reporting\SalesByCategoryRow;

/**
 * Sales and Kilograms Sold read once per Category, each line under the
 * Category it was sold under. A Category's money is its lines alone, so the
 * Categories add up to Sales less the Delivery Charges.
 */
function salesByCategoryToday(): array
{
    return collect(app(ReportingQuery::class)->salesByCategory(ReportingWindow::of(ReportingPreset::Today))->rows)
        ->mapWithKeys(fn (SalesByCategoryRow $row): array => [$row->name() => $row])
        ->all();
}

it('reads a category\'s money off its lines alone and carries the delivery charges beside them', function () {
    $gold = Category::factory()->create(['name' => 'Gold']);
    $silver = Category::factory()->create(['name' => 'Silver']);
    $goldTea = Product::factory()->for($gold)->create();
    $silverTea = Product::factory()->for($silver)->create();

    $both = orderAsCaptured(lines: [['product' => $goldTea, 'kilograms' => 2], ['product' => $silverTea, 'kilograms' => 5]]);
    $goldOnly = orderAsCaptured(lines: [['product' => $goldTea, 'kilograms' => 1]]);
    orderAsCaptured(lines: [['product' => $goldTea, 'kilograms' => 40]])->update(['status' => OrderStatus::Cancelled]);

    $result = app(ReportingQuery::class)->salesByCategory(ReportingWindow::of(ReportingPreset::Today));
    $rows = salesByCategoryToday();

    expect(array_map(fn (SalesByCategoryRow $row): string => $row->name(), $result->rows))->toBe(['Silver', 'Gold'])
        ->and($rows['Gold']->orders)->toBe(2)
        ->and($rows['Gold']->kilograms)->toBe(3)
        ->and($rows['Gold']->sales + $rows['Silver']->sales + $result->deliveryCharge)
        ->toBe($both->fresh()->total + $goldOnly->fresh()->total)
        ->and($result->deliveryCharge)->toBe($both->delivery_charge + $goldOnly->delivery_charge)
        ->and($rows['Silver']->kilogramsShareOf($result->kilograms()))->toBe(5 / 8);
});

it('counts a sale under the category it was sold under, not the one its product sits in now', function () {
    $gold = Category::factory()->create(['name' => 'Gold']);
    $premium = Category::factory()->create(['name' => 'Premium']);
    $tea = Product::factory()->for($gold)->create();

    orderAsCaptured(lines: [['product' => $tea, 'kilograms' => 2]]);

    $tea->update(['category_id' => $premium->getKey()]);

    orderAsCaptured(lines: [['product' => $tea->fresh(), 'kilograms' => 3]]);

    $rows = salesByCategoryToday();

    expect($rows['Gold']->kilograms)->toBe(2)
        ->and($rows['Premium']->kilograms)->toBe(3);
});
