<?php

use App\Support\Reporting\BucketSize;
use App\Support\Reporting\ReportingBucket;
use App\Support\Reporting\ReportingPreset;
use App\Support\Reporting\ReportingWindow;
use App\Support\Settings\GeneralSettings;
use Carbon\CarbonImmutable;

/**
 * A "now" as the Merchant sees it: a Wednesday morning in Dhaka, which is
 * still Tuesday evening in UTC. Every window below is cut from this instant.
 */
function dhakaMorning(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-09-09 10:00:00', 'Asia/Dhaka');
}

it('defaults to the last seven days, today and the six before it, cut at Dhaka midnight', function () {
    $window = ReportingWindow::default(dhakaMorning());

    expect($window->preset)->toBe(ReportingPreset::Last7Days)
        ->and($window->timezone)->toBe('Asia/Dhaka')
        ->and($window->start->toIso8601String())->toBe('2026-09-02T18:00:00+00:00')
        ->and($window->end->toIso8601String())->toBe('2026-09-09T18:00:00+00:00')
        ->and($window->start->timezoneName)->toBe('UTC')
        ->and($window->end->timezoneName)->toBe('UTC');
});

it('builds every preset from a now in the merchant timezone', function (ReportingPreset $preset, string $start, string $end) {
    $window = ReportingWindow::of($preset, dhakaMorning());

    expect($window->preset)->toBe($preset)
        ->and($window->start->toIso8601String())->toBe($start)
        ->and($window->end->toIso8601String())->toBe($end);
})->with([
    'today' => [ReportingPreset::Today, '2026-09-08T18:00:00+00:00', '2026-09-09T18:00:00+00:00'],
    'yesterday' => [ReportingPreset::Yesterday, '2026-09-07T18:00:00+00:00', '2026-09-08T18:00:00+00:00'],
    'last 7 days' => [ReportingPreset::Last7Days, '2026-09-02T18:00:00+00:00', '2026-09-09T18:00:00+00:00'],
    'last 30 days' => [ReportingPreset::Last30Days, '2026-08-10T18:00:00+00:00', '2026-09-09T18:00:00+00:00'],
    'this month, to date' => [ReportingPreset::ThisMonth, '2026-08-31T18:00:00+00:00', '2026-09-09T18:00:00+00:00'],
]);

it('takes two inclusive dates as a custom window', function () {
    $window = ReportingWindow::between('2026-09-01', '2026-09-03');

    expect($window->preset)->toBe(ReportingPreset::Custom)
        ->and($window->start->toIso8601String())->toBe('2026-08-31T18:00:00+00:00')
        ->and($window->end->toIso8601String())->toBe('2026-09-03T18:00:00+00:00');
});

it('refuses a custom window whose dates are the wrong way round', function () {
    ReportingWindow::between('2026-09-03', '2026-09-01');
})->throws(InvalidArgumentException::class);

it('refuses to build the custom preset without dates', function () {
    ReportingWindow::of(ReportingPreset::Custom, dhakaMorning());
})->throws(InvalidArgumentException::class);

it('decides which day it is by the merchant timezone, so 00:30 in Dhaka is already tomorrow to UTC', function () {
    /** Half past midnight on the 10th in Dhaka; still half past six in the evening of the 9th in UTC. */
    $now = CarbonImmutable::parse('2026-09-09 18:30:00', 'UTC');

    $dhaka = ReportingWindow::of(ReportingPreset::Today, $now);

    expect($dhaka->start->toIso8601String())->toBe('2026-09-09T18:00:00+00:00')
        ->and($dhaka->end->toIso8601String())->toBe('2026-09-10T18:00:00+00:00');

    $general = app(GeneralSettings::class);
    $general->timezone = 'UTC';
    $general->save();

    $utc = ReportingWindow::of(ReportingPreset::Today, $now);

    expect($utc->timezone)->toBe('UTC')
        ->and($utc->start->toIso8601String())->toBe('2026-09-09T00:00:00+00:00')
        ->and($utc->end->toIso8601String())->toBe('2026-09-10T00:00:00+00:00');
});

it('compares against the equal-length span that ends where it starts', function () {
    $window = ReportingWindow::of(ReportingPreset::Last7Days, dhakaMorning());
    $comparison = $window->comparison();

    expect($comparison->end)->toEqual($window->start)
        ->and($comparison->start->toIso8601String())->toBe('2026-08-26T18:00:00+00:00')
        ->and($comparison->preset)->toBe($window->preset)
        ->and($comparison->timezone)->toBe($window->timezone);

    $today = ReportingWindow::of(ReportingPreset::Today, dhakaMorning())->comparison();

    expect($today->start->toIso8601String())->toBe('2026-09-07T18:00:00+00:00')
        ->and($today->end->toIso8601String())->toBe('2026-09-08T18:00:00+00:00');
});

it('buckets a one-day window by the hour and anything longer by the day, empty buckets included', function () {
    $today = ReportingWindow::of(ReportingPreset::Today, dhakaMorning());

    expect($today->bucketSize())->toBe(BucketSize::Hour)
        ->and($today->buckets())->toHaveCount(24)
        ->and($today->buckets()[0]->start)->toEqual($today->start)
        ->and($today->buckets()[0]->end->toIso8601String())->toBe('2026-09-08T19:00:00+00:00')
        ->and($today->buckets()[23]->end)->toEqual($today->end)
        ->and($today->buckets()[6]->start->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-09-09 06:00');

    $week = ReportingWindow::of(ReportingPreset::Last7Days, dhakaMorning());
    $days = array_map(fn (ReportingBucket $bucket): string => $bucket->start->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'), $week->buckets());

    expect($week->bucketSize())->toBe(BucketSize::Day)
        ->and($days)->toBe([
            '2026-09-03 00:00', '2026-09-04 00:00', '2026-09-05 00:00', '2026-09-06 00:00',
            '2026-09-07 00:00', '2026-09-08 00:00', '2026-09-09 00:00',
        ])
        ->and($week->buckets()[6]->end)->toEqual($week->end);

    /** A custom window of a single date is one calendar day too. */
    expect(ReportingWindow::between('2026-09-01', '2026-09-01')->bucketSize())->toBe(BucketSize::Hour)
        ->and(ReportingWindow::between('2026-09-01', '2026-09-02')->bucketSize())->toBe(BucketSize::Day);
});
