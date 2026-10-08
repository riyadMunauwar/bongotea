<?php

use App\Enums\OrderStatus;
use App\Models\Payment;
use App\Support\Reporting\ReportingPreset;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\ReportingWindow;
use App\Support\Reporting\StatusBreakdown;

/*
| Every window below is cut from reportingNow() — a Wednesday morning in
| Dhaka — so Last 7 days runs from Thursday the 3rd to Wednesday the 9th.
| The helpers are in tests/Pest.php, shared with the other module tests.
*/

function statusBreakdownOf(ReportingPreset $preset = ReportingPreset::Last7Days): StatusBreakdown
{
    return app(ReportingQuery::class)->statusBreakdown(ReportingWindow::of($preset, reportingNow()));
}

it('counts New, Confirmed and Cancelled over the Orders in the window, a Cancelled Order that was Paid still Cancelled', function () {
    orderCapturedAt('2026-09-05 12:00');
    orderCapturedAt('2026-09-05 13:00');
    orderCapturedAt('2026-09-06 12:00', status: OrderStatus::Confirmed);
    orderCapturedAt('2026-09-07 12:00', status: OrderStatus::Cancelled);
    Payment::factory()->for(orderCapturedAt('2026-09-07 13:00', status: OrderStatus::Cancelled))->paid()->create();
    orderCapturedAt('2026-09-08 12:00', status: OrderStatus::Confirmed)->delete();
    orderCapturedAt('2026-09-02 23:59');

    $breakdown = statusBreakdownOf();

    expect($breakdown->count(OrderStatus::New))->toBe(2)
        ->and($breakdown->count(OrderStatus::Confirmed))->toBe(1)
        ->and($breakdown->count(OrderStatus::Cancelled))->toBe(2)
        ->and($breakdown->orders())->toBe(5);
});

it('reads as zeros on a window with nothing captured', function () {
    orderCapturedAt('2026-09-02 12:00');

    $breakdown = statusBreakdownOf();

    expect($breakdown->orders())->toBe(0)
        ->and($breakdown->count(OrderStatus::New))->toBe(0)
        ->and($breakdown->count(OrderStatus::Confirmed))->toBe(0)
        ->and($breakdown->count(OrderStatus::Cancelled))->toBe(0);
});
