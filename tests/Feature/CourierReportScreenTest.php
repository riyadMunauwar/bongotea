<?php

use App\Enums\Courier;
use App\Enums\ShipmentStatus;
use App\Filament\Clusters\Reports\Pages\CourierReport;
use App\Filament\Clusters\Reports\ReportsCluster;
use App\Filament\Clusters\Reports\Widgets\CourierPerformanceTable;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Pages\PageResource;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Reporting\ReportingWindow;
use App\Support\Settings\GeneralSettings;
use Filament\Pages\Enums\SubNavigationPosition;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

/*
| The counting is proved against the query module in
| ReportingCourierPerformanceTest. What is asserted here is the screen a
| Merchant opens: that it is reachable at an address of its own inside the
| Reports cluster with the Reporting Window across the top, that the window it
| is set to is the window the figures obey, that an absent figure reads as a
| sentence rather than as a nought, that nothing on it is added up across
| couriers, and that none of it has leaked onto the Dashboard — which carries
| the courier to-do list and no windowed analytics at all.
|
| It carries the cluster's own assertions too, because the cluster holds one
| page today: where it sits, that it is ungated, and that it and not its pages
| is what the sidebar draws.
*/

/**
 * A parcel one Courier accepted so many days ago and, where it finished with
 * it, took so many days over.
 *
 * @param  array<string, mixed>  $attributes
 */
function parcelHandedOver(Courier $courier, int $daysAgo, array $attributes = [], ?int $tookDays = null): Shipment
{
    $bookedAt = now()->subDays($daysAgo);

    return Shipment::factory()->for(Order::factory())->create(array_merge([
        'courier' => $courier,
        'booked_at' => $bookedAt,
        'created_at' => $bookedAt,
        'updated_at' => $tookDays === null ? $bookedAt : $bookedAt->copy()->addDays($tookDays),
    ], $attributes));
}

/**
 * The rows the widget hands the table, read off the widget rather than
 * scraped out of the HTML, for one preset.
 *
 * @return list<array<string, mixed>>
 */
function courierReportRows(string $preset = 'last_7_days'): array
{
    return (fn (): array => $this->rows()->values()->all())->call(
        Livewire::test(CourierPerformanceTable::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => $preset]])->instance(),
    );
}

/**
 * A week of parcels across two couriers, with the third handed nothing.
 *
 * Steadfast took four and finished with all four — three arrived, in two,
 * four and three days, and one came back — and has named no charge for any of
 * them, which is what it does. Pathao took two, one of which arrived the next
 * day, and priced both. A fifth Steadfast parcel is nine days old and belongs
 * to no window this screen opens on.
 */
function aWeekOfParcels(): void
{
    parcelHandedOver(Courier::Steadfast, 5, ['status' => ShipmentStatus::Delivered, 'courier_charge' => null], tookDays: 2);
    parcelHandedOver(Courier::Steadfast, 5, ['status' => ShipmentStatus::Delivered, 'courier_charge' => null], tookDays: 4);
    parcelHandedOver(Courier::Steadfast, 4, ['status' => ShipmentStatus::Delivered, 'courier_charge' => null], tookDays: 3);
    parcelHandedOver(Courier::Steadfast, 3, ['status' => ShipmentStatus::Returned, 'courier_charge' => null], tookDays: 2);

    parcelHandedOver(Courier::Pathao, 2, ['status' => ShipmentStatus::Delivered, 'courier_charge' => 6000], tookDays: 1);
    parcelHandedOver(Courier::Pathao, 2, ['status' => ShipmentStatus::InTransit, 'courier_charge' => 7000]);

    parcelHandedOver(Courier::Steadfast, 9, ['status' => ShipmentStatus::Delivered, 'courier_charge' => null], tookDays: 1);
}

it('opens the Courier Report at an address of its own, with the Reporting Window above the figures', function () {
    expect(CourierReport::getUrl())->toEndWith('/admin/reports/courier-report');

    /** Nothing is posted to reach it, so the address is worth bookmarking. */
    $this->get(CourierReport::getUrl())
        ->assertOk()
        ->assertSeeLivewire(CourierPerformanceTable::class)
        ->assertSeeInOrder([
            'Reporting Window',
            'wire:name="'.CourierPerformanceTable::class.'"',
        ], escape: false);

    /** Lazy, so the shell renders before the figures are counted. */
    expect(CourierPerformanceTable::isLazy())->toBeTrue();
});

it('is the first page of the Reports cluster, which is what the sidebar draws', function () {
    expect(CourierReport::getCluster())->toBe(ReportsCluster::class)
        ->and(CourierReport::getNavigationSort())->toBe(0);

    /**
     * The cluster carries the glyph and the pages inside it carry none, so a
     * second report page costs no icon decision — and the collapsed rail
     * never draws the truck twice.
     */
    expect(CourierReport::getNavigationIcon())->toBeNull()
        ->and(ReportsCluster::getNavigationIcon())->not->toBeNull();
});

/**
 * Ungated on purpose: the Dashboard already draws Sales, Average Order Value
 * and courier money to every User, so a gate here would stop nobody reading
 * the same class of figure one click away. The permission is owed the day the
 * Dashboard's own figures are gated with it.
 */
it('opens for any User of the panel, holding no permission of its own', function () {
    expect(ReportsCluster::canAccess())->toBeTrue()
        ->and(CourierReport::canAccess())->toBeTrue();
});

/**
 * A report is a wide thing, and Filament's default would spend a second
 * vertical strip inside the page on wayfinding — beside the panel's own 14rem
 * sidebar. The position is declared on the cluster and read off the page, which
 * is what proves a report page added later inherits it without declaring it.
 */
it('draws the cluster pages as tabs above the report, never as a second sidebar', function () {
    expect(ReportsCluster::getSubNavigationPosition())->toBe(SubNavigationPosition::Top)
        ->and(CourierReport::getSubNavigationPosition())->toBe(SubNavigationPosition::Top);

    $this->get(CourierReport::getUrl())
        ->assertOk()
        ->assertSee('fi-page-has-sub-navigation-top')
        ->assertDontSee('fi-page-has-sub-navigation-start');
});

it('sits between the work and the settings, in the gap the Settings cluster left', function () {
    expect(ReportsCluster::getNavigationSort())->toBe(50)
        ->and(SettingsCluster::getNavigationSort())->toBe(100)
        ->and(PageResource::getNavigationSort())->toBe(4);
});

it('opens on the last seven days and reveals the two pickers once Custom is chosen', function () {
    Livewire::test(CourierReport::class)
        ->assertSchemaStateSet([ReportingWindow::PRESET_FILTER => 'last_7_days'], 'filtersForm')
        ->assertSchemaComponentHidden(ReportingWindow::FROM_FILTER, 'filtersForm')
        ->assertSchemaComponentHidden(ReportingWindow::UNTIL_FILTER, 'filtersForm')
        /** Set one property at a time, the way the browser sets a live field. */
        ->set('filters.'.ReportingWindow::PRESET_FILTER, 'custom')
        ->assertSchemaComponentVisible(ReportingWindow::FROM_FILTER, 'filtersForm')
        ->assertSchemaComponentVisible(ReportingWindow::UNTIL_FILTER, 'filtersForm');
});

it('reports each Courier on its own, counting the window and nothing outside it', function () {
    aWeekOfParcels();

    $rows = collect(courierReportRows())->keyBy('courier');

    expect($rows['Steadfast'])->toMatchArray([
        'sent' => 4,
        'delivered' => 3,
        'returned' => 1,
        'delivery_rate' => 0.75,
        'return_rate' => 0.25,
        'average_days_to_delivery' => 3.0,
        'charges' => null,
    ]);

    expect($rows['Pathao'])->toMatchArray([
        'sent' => 2,
        'delivered' => 1,
        'returned' => 0,
        'delivery_rate' => 0.5,
        'return_rate' => 0.0,
        'average_days_to_delivery' => 1.0,
        'charges' => 13000,
    ]);

    expect($rows['RedEx'])->toMatchArray(['sent' => 0, 'delivered' => 0, 'returned' => 0]);
});

it('draws one row per Courier and never a row that is all of them', function () {
    aWeekOfParcels();

    expect(array_column(courierReportRows(), 'courier'))
        ->toBe(array_map(fn (Courier $courier): string => $courier->getLabel(), Courier::cases()));

    Livewire::test(CourierPerformanceTable::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'last_7_days']])
        ->assertSee('Courier performance')
        ->assertSee('Steadfast')
        ->assertSee('Pathao')
        ->assertSee('RedEx')
        /** No blended figure anywhere on it: one Courier's silence is never drawn as the trade's (ADR-0018). */
        ->assertDontSee('Total');
});

it('reads a figure a Courier has never reported as a sentence, never as a nought', function () {
    aWeekOfParcels();

    $screen = Livewire::test(CourierPerformanceTable::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'last_7_days']]);

    $screen->assertSee('75.0%')
        ->assertSee('25.0%')
        ->assertSee('50.0%')
        ->assertSee(app(GeneralSettings::class)->currencySymbol.'130.00')
        /** Steadfast has priced nothing and RedEx carried nothing; neither is free carriage. */
        ->assertSee('Not reported')
        /** RedEx was handed no parcel, which is not a Courier that fails to deliver. */
        ->assertSee('No parcels')
        ->assertSee('Nothing delivered');
});

it('narrows every figure when the window narrows, and keeps the choice for the next visit', function () {
    aWeekOfParcels();

    Livewire::test(CourierReport::class)
        ->set('filters.'.ReportingWindow::PRESET_FILTER, 'today');

    /** Kept in the session, so the days a Merchant was reading are the days the screen reopens on. */
    Livewire::test(CourierReport::class)
        ->assertSchemaStateSet([ReportingWindow::PRESET_FILTER => 'today'], 'filtersForm');

    /** Nothing was handed to anybody today, so every Courier reads as zeros. */
    foreach (courierReportRows('today') as $row) {
        expect($row['sent'])->toBe(0)
            ->and($row['delivery_rate'])->toBeNull()
            ->and($row['charges'])->toBeNull();
    }
});

it('reads a window with no parcels at all as zeros rather than as an error', function () {
    $this->get(CourierReport::getUrl())->assertOk();

    Livewire::test(CourierPerformanceTable::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'last_7_days']])
        ->assertOk()
        ->assertSee('Steadfast')
        ->assertSee('No parcels')
        ->assertSee('Nothing delivered')
        ->assertSee('Not reported');

    expect(courierReportRows())->toHaveCount(count(Courier::cases()));
});

it('keeps its windowed figures off the Dashboard, which carries the to-do list and no report of couriers', function () {
    aWeekOfParcels();

    $this->get(Dashboard::getUrl())
        ->assertOk()
        ->assertDontSeeLivewire(CourierPerformanceTable::class);

    /** And the screen that does draw it names it itself rather than finding it by discovery. */
    expect(app(CourierReport::class)->getWidgets())->toBe([CourierPerformanceTable::class]);
});
