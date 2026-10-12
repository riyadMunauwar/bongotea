<?php

use App\Models\VisitCount;
use App\Support\Reporting\ReportingPreset;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\ReportingWindow;
use App\Support\Reporting\VisitsOverTimePoint;
use App\Support\Settings\TrackingSettings;

/**
 * The store's Visits day by day. Visits are counted by the day, so the
 * series is a day per point whatever the window, and a one-day window is a
 * single figure the Visits tile already gives rather than a line.
 */
it('draws one point per merchant day, an uncounted day as none', function () {
    app(TrackingSettings::class)->with(['countsVisits' => true])->save();

    VisitCount::factory()->on(0, 12)->create();
    VisitCount::factory()->on(2, 5)->create();
    VisitCount::factory()->on(9, 400)->create();

    $series = app(ReportingQuery::class)->visitsOverTime(ReportingWindow::of(ReportingPreset::Last7Days));

    expect($series->isCounted())->toBeTrue()
        ->and(array_map(fn (VisitsOverTimePoint $point): int => $point->visits, $series->points))
        ->toBe([0, 0, 0, 0, 5, 0, 12]);
});

it('has no series while visits are not counted', function () {
    VisitCount::factory()->on(0, 12)->create();

    $series = app(ReportingQuery::class)->visitsOverTime(ReportingWindow::of(ReportingPreset::Last7Days));

    expect($series->isCounted())->toBeFalse()
        ->and($series->points)->toBe([]);
});
