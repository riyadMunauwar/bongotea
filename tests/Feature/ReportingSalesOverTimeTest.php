<?php

use App\Enums\OrderStatus;
use App\Support\Reporting\BucketSize;
use App\Support\Reporting\ReportingPreset;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\ReportingWindow;
use App\Support\Reporting\SalesOverTime;
use App\Support\Reporting\SalesOverTimePoint;
use Carbon\CarbonImmutable;

/*
| Every window below is cut from reportingNow() — a Wednesday morning in
| Dhaka — so Last 7 days runs from Thursday the 3rd to Wednesday the 9th.
| The helpers are in tests/Pest.php, shared with the other module tests.
*/

function salesOverTimeOf(ReportingPreset $preset = ReportingPreset::Last7Days): SalesOverTime
{
    return app(ReportingQuery::class)->salesOverTime(ReportingWindow::of($preset, reportingNow()));
}

/**
 * @return list<int>
 */
function ordersPerPoint(SalesOverTime $series): array
{
    return $series->series(fn (SalesOverTimePoint $point): int => $point->orders);
}

/**
 * @return list<int>
 */
function salesPerPoint(SalesOverTime $series): array
{
    return $series->series(fn (SalesOverTimePoint $point): int => $point->sales);
}

it('emits one point per day of a longer window, in order, an empty day as zeros rather than a gap', function () {
    orderCapturedAt('2026-09-03 00:00', 10000);
    orderCapturedAt('2026-09-06 12:00', 20000);
    orderCapturedAt('2026-09-06 13:00', 30000);
    orderCapturedAt('2026-09-09 23:59', 40000);

    $series = salesOverTimeOf();

    expect($series->size())->toBe(BucketSize::Day)
        ->and($series->points)->toHaveCount(7)
        ->and(ordersPerPoint($series))->toBe([1, 0, 0, 2, 0, 0, 1])
        ->and(salesPerPoint($series))->toBe([16000, 0, 0, 62000, 0, 0, 46000])
        ->and($series->points[0]->bucket->start)->toEqual(CarbonImmutable::parse('2026-09-03 00:00', 'Asia/Dhaka'))
        ->and($series->points[6]->bucket->end)->toEqual(CarbonImmutable::parse('2026-09-10 00:00', 'Asia/Dhaka'));
});

it('emits one point per hour of a one-day window, every hour present', function () {
    orderCapturedAt('2026-09-09 00:10', 10000);
    orderCapturedAt('2026-09-09 09:20', 20000);
    orderCapturedAt('2026-09-09 09:40', 30000);

    $series = salesOverTimeOf(ReportingPreset::Today);

    expect($series->size())->toBe(BucketSize::Hour)
        ->and($series->points)->toHaveCount(24)
        ->and($series->points[0]->orders)->toBe(1)
        ->and($series->points[9]->orders)->toBe(2)
        ->and($series->points[9]->sales)->toBe(26000 + 36000)
        ->and($series->points[10]->orders)->toBe(0)
        ->and($series->points[23]->bucket->end)->toEqual(CarbonImmutable::parse('2026-09-10 00:00', 'Asia/Dhaka'))
        ->and(array_sum(ordersPerPoint($series)))->toBe(3);
});

it('counts a Cancelled Order towards Orders but not Sales, and a soft-deleted one towards neither', function () {
    orderCapturedAt('2026-09-05 12:00', 50000);
    orderCapturedAt('2026-09-05 13:00', 80000, status: OrderStatus::Cancelled);
    orderCapturedAt('2026-09-05 14:00', 90000)->delete();

    $series = salesOverTimeOf();

    expect($series->points[2]->orders)->toBe(2)
        ->and($series->points[2]->sales)->toBe(56000);
});

it('is all zeros on a window with nothing captured, one point per bucket still', function () {
    $series = salesOverTimeOf();

    expect($series->points)->toHaveCount(7)
        ->and(ordersPerPoint($series))->toBe([0, 0, 0, 0, 0, 0, 0])
        ->and(salesPerPoint($series))->toBe([0, 0, 0, 0, 0, 0, 0]);
});
