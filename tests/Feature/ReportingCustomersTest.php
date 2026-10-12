<?php

use App\Enums\OrderStatus;
use App\Filament\Widgets\OverviewStats;
use App\Models\Customer;
use App\Models\Order;
use App\Support\Reporting\Overview;
use App\Support\Reporting\ReportingPreset;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\ReportingWindow;
use Livewire\Livewire;

/*
| New and Returning Customers. Buying is an Order counted in Sales, so a
| Cancelled Order neither makes anybody returning nor counts them in the
| window. Windows are cut from reportingNow(): Last 7 days runs from the 3rd
| to the 9th of September, the comparison window from the 27th to the 2nd.
*/

function customersOverview(ReportingPreset $preset = ReportingPreset::Last7Days): Overview
{
    return app(ReportingQuery::class)->overview(ReportingWindow::of($preset, reportingNow()));
}

function boughtBy(Customer $customer, string $instant, OrderStatus $status = OrderStatus::New): void
{
    orderCapturedAt($instant, attributes: ['customer_id' => $customer->getKey(), 'customer_phone' => $customer->phone], status: $status);
}

it('splits the customers who bought in the window into new and returning', function () {
    $regular = Customer::factory()->create();
    $newcomer = Customer::factory()->create();
    $twiceThisWeek = Customer::factory()->create();

    boughtBy($regular, '2026-08-01 12:00');
    boughtBy($regular, '2026-09-05 12:00');
    boughtBy($newcomer, '2026-09-06 12:00');
    boughtBy($twiceThisWeek, '2026-09-04 12:00');
    boughtBy($twiceThisWeek, '2026-09-08 12:00');

    $figures = customersOverview()->figures;

    expect($figures->newCustomers)->toBe(2)
        ->and($figures->returningCustomers)->toBe(1);
});

/**
 * Rahim's first Order was Cancelled, so he never received any tea: his
 * first real purchase this week makes him new, not returning. A Customer
 * whose only Order in the window is Cancelled bought nothing in it.
 */
it('reads a cancelled order as no purchase, before the window and inside it', function () {
    $rahim = Customer::factory()->create();
    $cancelledOnly = Customer::factory()->create();

    boughtBy($rahim, '2026-03-01 12:00', OrderStatus::Cancelled);
    boughtBy($rahim, '2026-09-05 12:00');
    boughtBy($cancelledOnly, '2026-09-05 12:00', OrderStatus::Cancelled);

    $figures = customersOverview()->figures;

    expect($figures->newCustomers)->toBe(1)
        ->and($figures->returningCustomers)->toBe(0);
});

it('draws new and returning customers as overview tiles, the returning share leading its description', function () {
    $regular = Customer::factory()->create();

    Order::factory()->for($regular)->create(['created_at' => now()->subMonth()]);
    Order::factory()->for($regular)->create(['created_at' => now()]);
    Order::factory()->create(['created_at' => now()]);

    Livewire::test(OverviewStats::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'today']])
        ->assertSeeInOrder(['New Customers', '1', 'Returning Customers', '1', '50.0% of buyers']);
});

describe('top customers by their latest name', function () {
    /**
     * Latest by when the Order was captured, not by when it was typed in: an
     * Order taken by phone for yesterday is not the latest because it was
     * saved last.
     */
    it('names a customer by the order captured last, whatever its id', function () {
        $shop = Customer::factory()->create();

        orderCapturedAt('2026-09-08 12:00', attributes: ['customer_id' => $shop->getKey(), 'customer_name' => 'Rahim Store']);
        orderCapturedAt('2026-09-04 12:00', attributes: ['customer_id' => $shop->getKey(), 'customer_name' => 'Rahim']);

        $rows = app(ReportingQuery::class)->topCustomers(ReportingWindow::of(ReportingPreset::Last7Days, reportingNow()))->rows;

        expect($rows[0]->latestName)->toBe('Rahim Store');
    });
});

describe('top customers', function () {
    /**
     * Ranked by Kilograms Sold, since a wholesale Customer's size is their
     * weight, and named by the name on their latest Order in the window —
     * the Customer is only a phone number.
     */
    it('ranks the customers who bought in the window by kilograms, named by their latest order', function () {
        $shop = Customer::factory()->create();
        $household = Customer::factory()->create();

        weighing(orderCapturedAt('2026-08-20 12:00', attributes: ['customer_id' => $shop->getKey(), 'customer_name' => 'Rahim']), 1);
        weighing(orderCapturedAt('2026-09-04 12:00', attributes: ['customer_id' => $shop->getKey(), 'customer_name' => 'Rahim']), 10);
        weighing(orderCapturedAt('2026-09-08 12:00', attributes: ['customer_id' => $shop->getKey(), 'customer_name' => 'Rahim Store']), 15);
        $karims = weighing(orderCapturedAt('2026-09-06 12:00', attributes: ['customer_id' => $household->getKey(), 'customer_name' => 'Karim']), 2);
        weighing(orderCapturedAt('2026-09-07 12:00', attributes: ['customer_id' => $household->getKey()], status: OrderStatus::Cancelled), 50);

        $rows = app(ReportingQuery::class)->topCustomers(ReportingWindow::of(ReportingPreset::Last7Days, reportingNow()))->rows;

        expect($rows)->toHaveCount(2)
            ->and($rows[0]->customer->is($shop))->toBeTrue()
            ->and($rows[0]->latestName)->toBe('Rahim Store')
            ->and($rows[0]->orders)->toBe(2)
            ->and($rows[0]->kilograms)->toBe(25)
            ->and($rows[0]->isReturning)->toBeTrue()
            ->and($rows[1]->latestName)->toBe('Karim')
            ->and($rows[1]->orders)->toBe(1)
            ->and($rows[1]->sales)->toBe($karims->fresh()->total)
            ->and($rows[1]->isReturning)->toBeFalse();
    });
});

/**
 * A Customer who buys on two days of the window is counted once on the
 * tile, so the window's figure is worked out whole and is not the sum of
 * its buckets: each bucket applies the same rule from its own start.
 */
it('counts the window whole and each bucket from its own start', function () {
    $twiceThisWeek = Customer::factory()->create();
    $earlier = Customer::factory()->create();

    boughtBy($earlier, '2026-08-30 12:00');
    boughtBy($twiceThisWeek, '2026-09-04 12:00');
    boughtBy($twiceThisWeek, '2026-09-08 12:00');

    $overview = customersOverview();

    expect($overview->figures->newCustomers)->toBe(1)
        ->and($overview->figures->returningCustomers)->toBe(0)
        ->and($overview->series(fn ($figures): int => $figures->newCustomers))->toBe([0, 1, 0, 0, 0, 0, 0])
        ->and($overview->series(fn ($figures): int => $figures->returningCustomers))->toBe([0, 0, 0, 0, 0, 1, 0])
        ->and($overview->comparison->newCustomers)->toBe(1);
});
