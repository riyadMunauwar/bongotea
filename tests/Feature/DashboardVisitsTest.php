<?php

use App\Filament\Widgets\MostVisitedTable;
use App\Filament\Widgets\VisitsOverTimeChart;
use App\Models\PlaceVisitCount;
use App\Models\Product;
use App\Models\User;
use App\Models\VisitCount;
use App\Support\Reporting\ReportingWindow;
use App\Support\Settings\TrackingSettings;
use Livewire\Livewire;

/**
 * The Visits widgets on the Dashboard: the store's Visits a day at a time,
 * and the ten places most visited.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->viewingReports()->create());
});

function countingVisitsOnTheDashboard(): void
{
    app(TrackingSettings::class)->with(['countsVisits' => true])->save();
}

describe('visits over time', function () {
    it('draws a point per day of the window', function () {
        countingVisitsOnTheDashboard();

        VisitCount::factory()->on(0, 12)->create();
        VisitCount::factory()->on(3, 7)->create();

        Livewire::test(VisitsOverTimeChart::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'last_7_days']])
            ->assertSee('Visits over time')
            ->assertSee(json_encode([0, 0, 0, 7, 0, 0, 12]));
    });

    /**
     * Visits are counted by the day, so a single day is one figure — the one
     * the Visits tile already gives — and the chart says so instead of
     * drawing a lone bar.
     */
    it('explains itself rather than drawing one bar in a one-day window', function () {
        countingVisitsOnTheDashboard();

        VisitCount::factory()->on(0, 12)->create();

        Livewire::test(VisitsOverTimeChart::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'today']])
            ->assertSee('Visits are counted by the day, so a single day is one figure: see the Visits tile above.');
    });

    it('says visits are not counted while counting is off', function () {
        Livewire::test(VisitsOverTimeChart::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'last_7_days']])
            ->assertSee('Not counted');
    });
});

describe('most visited', function () {
    it('lists the places most visited, with their share and change', function () {
        countingVisitsOnTheDashboard();

        $gold = Product::factory()->create(['name' => 'Gold Blend']);

        VisitCount::factory()->on(0, 40)->create();
        PlaceVisitCount::factory()->of($gold)->on(0, 10)->create();
        PlaceVisitCount::factory()->of($gold)->on(1, 5)->create();
        PlaceVisitCount::factory()->on(0, 4)->create();

        Livewire::test(MostVisitedTable::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'today']])
            ->assertSeeInOrder(['Gold Blend', 'Product', '10', '25.0%', '100% up', 'Search', '4', '10.0%']);
    });

    it('says visits are not counted while counting is off', function () {
        Livewire::test(MostVisitedTable::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'today']])
            ->assertSee('Visits are not counted');
    });
});
