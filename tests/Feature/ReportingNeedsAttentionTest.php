<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentState;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Reporting\AttentionReason;
use App\Support\Reporting\NeedsAttention;
use App\Support\Reporting\NeedsAttentionRow;
use App\Support\Reporting\ReportingQuery;
use Carbon\CarbonImmutable;

/*
| Needs Attention is a to-do list, not a report: it takes no Reporting
| Window and reads the whole installation as it stands at one instant.
| Every list below is read at reportingNow(), a Wednesday morning in Dhaka,
| and the helpers are in tests/Pest.php, shared with the other module tests.
*/

function needsAttentionAt(?CarbonImmutable $now = null): NeedsAttention
{
    return app(ReportingQuery::class)->needsAttention($now ?? reportingNow());
}

/**
 * @return list<array{string, AttentionReason}>
 */
function attentionRows(NeedsAttention $list): array
{
    return array_map(
        fn (NeedsAttentionRow $row): array => [$row->order->order_number, $row->reason],
        $list->rows,
    );
}

function onlinePendingOrder(string $capturedAt): Order
{
    return orderCapturedAt($capturedAt, attributes: ['payment_method' => PaymentMethod::SslCommerz]);
}

it('lists a New Order older than a day and not one at twenty-three hours', function () {
    $stale = orderCapturedAt('2026-09-08 09:59');
    orderCapturedAt('2026-09-08 11:00');
    orderCapturedAt('2026-09-07 10:00', status: OrderStatus::Confirmed);
    orderCapturedAt('2026-09-07 10:00', status: OrderStatus::Cancelled);
    orderCapturedAt('2026-09-01 10:00')->delete();

    expect(attentionRows(needsAttentionAt()))->toBe([
        [$stale->order_number, AttentionReason::NewOrderUncalled],
    ]);
});

it('lists an Order with a Payment Pending for over an hour and not one at fifty-nine minutes', function () {
    $stale = onlinePendingOrder('2026-09-09 08:00');
    Payment::factory()->for($stale)->create(['created_at' => reportingNow()->subMinutes(61)->utc()]);

    $fresh = onlinePendingOrder('2026-09-09 09:00');
    Payment::factory()->for($fresh)->create(['created_at' => reportingNow()->subMinutes(59)->utc()]);

    $settled = onlinePendingOrder('2026-09-09 08:00');
    Payment::factory()->for($settled)->paid()->create(['created_at' => reportingNow()->subHours(3)->utc()]);

    $deleted = onlinePendingOrder('2026-09-09 08:00');
    Payment::factory()->for($deleted)->create(['created_at' => reportingNow()->subHours(3)->utc()]);
    $deleted->delete();

    $list = needsAttentionAt();

    expect(attentionRows($list))->toBe([
        [$stale->order_number, AttentionReason::PaymentPending],
    ])->and($list->rows[0]->since->equalTo(reportingNow()->subMinutes(61)))->toBeTrue();
});

it('lists an Order Paid twice, and not one Paid once', function () {
    $twice = onlinePendingOrder('2026-09-09 09:00');
    Payment::factory()->for($twice)->paid()->create();
    Payment::factory()->for($twice)->paid()->create();

    $once = onlinePendingOrder('2026-09-09 09:00');
    Payment::factory()->for($once)->paid()->create();
    Payment::factory()->for($once)->create(['state' => PaymentState::Failed]);

    expect(attentionRows(needsAttentionAt()))->toBe([
        [$twice->order_number, AttentionReason::PaidTwice],
    ]);
});

it('lists an Order Paid and Cancelled at once, and not a Cancelled Order that was never Paid', function () {
    $refundable = orderCapturedAt('2026-09-09 09:00', attributes: ['payment_method' => PaymentMethod::Bkash], status: OrderStatus::Cancelled);
    Payment::factory()->for($refundable)->paid()->create(['method' => PaymentMethod::Bkash]);

    $unpaid = orderCapturedAt('2026-09-09 09:00', attributes: ['payment_method' => PaymentMethod::Bkash], status: OrderStatus::Cancelled);
    Payment::factory()->for($unpaid)->create(['method' => PaymentMethod::Bkash, 'state' => PaymentState::Cancelled]);

    expect(attentionRows(needsAttentionAt()))->toBe([
        [$refundable->order_number, AttentionReason::PaidAndCancelled],
    ]);
});

it('ignores any window: an Order from months ago is still listed, in condition order and oldest first within each', function () {
    $older = orderCapturedAt('2026-03-01 10:00');
    $old = orderCapturedAt('2026-09-01 10:00');
    $refundable = orderCapturedAt('2026-02-01 10:00', attributes: ['payment_method' => PaymentMethod::Bkash], status: OrderStatus::Cancelled);
    Payment::factory()->for($refundable)->paid()->create(['method' => PaymentMethod::Bkash]);

    expect(attentionRows(needsAttentionAt()))->toBe([
        [$older->order_number, AttentionReason::NewOrderUncalled],
        [$old->order_number, AttentionReason::NewOrderUncalled],
        [$refundable->order_number, AttentionReason::PaidAndCancelled],
    ]);
});

it('is empty when there is nothing to act on', function () {
    orderCapturedAt('2026-09-09 09:00');

    expect(needsAttentionAt()->rows)->toBe([]);
});
