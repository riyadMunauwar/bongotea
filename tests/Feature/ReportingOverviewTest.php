<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Page;
use App\Models\Payment;
use App\Support\Reporting\Overview;
use App\Support\Reporting\ReportingPreset;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\ReportingWindow;
use App\Support\Settings\GeneralSettings;
use Carbon\CarbonImmutable;

/**
 * The instant every window below is cut from: a Wednesday morning in Dhaka.
 * Last 7 days therefore runs from Thursday the 3rd to Wednesday the 9th, and
 * the comparison window from the 27th to the 2nd.
 */
function reportingNow(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-09-09 10:00:00', 'Asia/Dhaka');
}

/**
 * An Order captured at an instant, its subtotal given and its total derived
 * with the Delivery Charge on top, the way the observer keeps it. Status is
 * a factory state applied at creation rather than a later update, because
 * a save re-reads the subtotal from the lines an Order made this way does
 * not have.
 *
 * @param  array<string, mixed>  $attributes
 */
function orderCapturedAt(string $instant, int $subtotal = 50000, array $attributes = [], OrderStatus $status = OrderStatus::New): Order
{
    $factory = match ($status) {
        OrderStatus::New => Order::factory(),
        OrderStatus::Confirmed => Order::factory()->confirmed(),
        OrderStatus::Cancelled => Order::factory()->cancelled(),
    };

    return $factory->create(array_merge([
        'subtotal' => $subtotal,
        'delivery_charge' => 6000,
        'created_at' => CarbonImmutable::parse($instant, 'Asia/Dhaka')->utc(),
    ], $attributes));
}

function overviewOf(ReportingPreset $preset = ReportingPreset::Last7Days, ?Page $page = null): Overview
{
    return app(ReportingQuery::class)->overview(ReportingWindow::of($preset, reportingNow()), $page);
}

it('counts every Order in the window whatever its status, and sums Sales over the ones not Cancelled, Delivery Charge included', function () {
    orderCapturedAt('2026-09-05 12:00', 50000);
    orderCapturedAt('2026-09-06 12:00', 30000, status: OrderStatus::Confirmed);
    orderCapturedAt('2026-09-07 12:00', 80000, status: OrderStatus::Cancelled);

    $overview = overviewOf();

    expect($overview->figures->orders)->toBe(3)
        ->and($overview->figures->cancelled)->toBe(1)
        ->and($overview->figures->notCancelled())->toBe(2)
        ->and($overview->figures->sales)->toBe(56000 + 36000)
        ->and($overview->figures->averageOrderValue())->toBe(46000)
        ->and($overview->figures->cancellationRate())->toBe(1 / 3);
});

it('leaves a soft-deleted Order out of every figure', function () {
    orderCapturedAt('2026-09-05 12:00');
    orderCapturedAt('2026-09-06 12:00')->delete();

    $overview = overviewOf();

    expect($overview->figures->orders)->toBe(1)
        ->and($overview->figures->sales)->toBe(56000);
});

it('leaves an Order captured outside the window out', function () {
    orderCapturedAt('2026-09-02 23:59');
    orderCapturedAt('2026-09-03 00:00');
    orderCapturedAt('2026-09-09 23:59');
    orderCapturedAt('2026-09-10 00:00');

    expect(overviewOf()->figures->orders)->toBe(2);
});

it('reads Average Order Value and Cancellation Rate as zero on an empty window', function () {
    $overview = overviewOf();

    expect($overview->figures->orders)->toBe(0)
        ->and($overview->figures->sales)->toBe(0)
        ->and($overview->figures->averageOrderValue())->toBe(0)
        ->and($overview->figures->cancellationRate())->toBe(0.0)
        ->and($overview->comparison->averageOrderValue())->toBe(0);
});

it('reads Average Order Value as zero when every Order in the window is Cancelled', function () {
    orderCapturedAt('2026-09-05 12:00', status: OrderStatus::Cancelled);

    $overview = overviewOf();

    expect($overview->figures->orders)->toBe(1)
        ->and($overview->figures->averageOrderValue())->toBe(0)
        ->and($overview->figures->cancellationRate())->toBe(1.0);
});

it('counts Unpaid through the Order scope: an online method with no Paid Payment', function () {
    orderCapturedAt('2026-09-05 12:00', attributes: ['payment_method' => PaymentMethod::CashOnDelivery]);
    orderCapturedAt('2026-09-05 13:00', attributes: ['payment_method' => PaymentMethod::Bkash]);

    $pending = orderCapturedAt('2026-09-05 14:00', attributes: ['payment_method' => PaymentMethod::SslCommerz]);
    Payment::factory()->for($pending)->create();

    $paid = orderCapturedAt('2026-09-05 15:00', attributes: ['payment_method' => PaymentMethod::SslCommerz]);
    Payment::factory()->for($paid)->paid()->create();

    expect(overviewOf()->figures->unpaid)->toBe(2);
});

it('counts the New Orders awaiting a call', function () {
    orderCapturedAt('2026-09-05 12:00');
    orderCapturedAt('2026-09-05 13:00');
    orderCapturedAt('2026-09-05 14:00', status: OrderStatus::Confirmed);

    expect(overviewOf()->figures->new)->toBe(2);
});

it('carries the comparison window figures, counted the same way over the span before', function () {
    orderCapturedAt('2026-08-27 00:00', 10000);
    orderCapturedAt('2026-09-02 23:59', 20000, status: OrderStatus::Cancelled);
    orderCapturedAt('2026-08-26 23:59', 90000);
    orderCapturedAt('2026-09-05 12:00', 50000);

    $overview = overviewOf();

    expect($overview->figures->orders)->toBe(1)
        ->and($overview->comparison->orders)->toBe(2)
        ->and($overview->comparison->cancelled)->toBe(1)
        ->and($overview->comparison->sales)->toBe(16000);
});

it('scopes to a Page when given one, leaving other Pages\' Orders out', function () {
    $page = Page::factory()->create();
    orderCapturedAt('2026-09-05 12:00', attributes: ['page_id' => $page->id]);
    orderCapturedAt('2026-09-05 13:00', attributes: ['page_id' => $page->id]);
    orderCapturedAt('2026-09-05 14:00');

    expect(overviewOf(page: $page)->figures->orders)->toBe(2)
        ->and(overviewOf()->figures->orders)->toBe(3)
        ->and($page->orders()->count())->toBe(2);
});

it('cuts the day at the Merchant\'s midnight, so an Order at 23:30 Dhaka is yesterday and one at 00:30 is today', function () {
    /** Half past midnight on the 10th in Dhaka; still the evening of the 9th in UTC. */
    $now = CarbonImmutable::parse('2026-09-09 18:30:00', 'UTC');

    orderCapturedAt('2026-09-09 23:30');
    orderCapturedAt('2026-09-10 00:30');

    $query = app(ReportingQuery::class);

    $dhaka = $query->overview(ReportingWindow::of(ReportingPreset::Today, $now));

    expect($dhaka->figures->orders)->toBe(1)
        ->and($dhaka->comparison->orders)->toBe(1);

    $general = app(GeneralSettings::class);
    $general->timezone = 'UTC';
    $general->save();

    $utc = $query->overview(ReportingWindow::of(ReportingPreset::Today, $now));

    expect($utc->figures->orders)->toBe(2)
        ->and($utc->comparison->orders)->toBe(0);
});

it('slices a one-day window into hourly figures, every hour present', function () {
    orderCapturedAt('2026-09-09 00:10', 10000);
    orderCapturedAt('2026-09-09 09:20', 20000);
    orderCapturedAt('2026-09-09 09:40', 30000, status: OrderStatus::Cancelled);

    $overview = overviewOf(ReportingPreset::Today);

    expect($overview->buckets)->toHaveCount(24)
        ->and($overview->buckets[0]->orders)->toBe(1)
        ->and($overview->buckets[9]->orders)->toBe(2)
        ->and($overview->buckets[9]->sales)->toBe(26000)
        ->and($overview->buckets[9]->cancelled)->toBe(1)
        ->and($overview->buckets[10]->orders)->toBe(0)
        ->and(array_sum(array_map(fn ($bucket) => $bucket->orders, $overview->buckets)))->toBe($overview->figures->orders);
});

it('slices a longer window into daily figures, empty days as zeros', function () {
    orderCapturedAt('2026-09-03 00:00');
    orderCapturedAt('2026-09-09 23:59', attributes: ['payment_method' => PaymentMethod::Bkash]);

    $overview = overviewOf();

    expect($overview->buckets)->toHaveCount(7)
        ->and(array_map(fn ($bucket) => $bucket->orders, $overview->buckets))->toBe([1, 0, 0, 0, 0, 0, 1])
        ->and(array_map(fn ($bucket) => $bucket->unpaid, $overview->buckets))->toBe([0, 0, 0, 0, 0, 0, 1]);
});
