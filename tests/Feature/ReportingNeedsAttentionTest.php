<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentState;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shipment;
use App\Support\Couriers\CourierSweep;
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

/**
 * An Order somebody confirmed, sent, and got back two days ago.
 *
 * Confirmed rather than New, and captured this morning, so that the only
 * thing it can be on the list for is the parcel: a New Order left long enough
 * to have been sent and returned would be a call nobody made as well.
 */
function orderSentAndReturned(OrderStatus $status = OrderStatus::Confirmed): Order
{
    $order = orderCapturedAt('2026-09-09 09:00', status: $status);

    Shipment::factory()->for($order)->returned()->create(['updated_at' => reportingNow()->subDays(2)->utc()]);

    return $order;
}

/**
 * An Order with one parcel out, handed to its Courier this long ago and not
 * heard about since.
 */
function orderWithParcelBookedSecondsAgo(int $seconds, bool $abandoned = false): Order
{
    $order = orderCapturedAt('2026-09-09 09:00', status: OrderStatus::Confirmed);

    /** In UTC, like every stored time: a wall clock written straight into the column would be six hours of Dhaka inside the horizon. */
    Shipment::factory()->for($order)->create([
        'booked_at' => reportingNow()->subSeconds($seconds)->utc(),
        'abandoned_at' => $abandoned ? reportingNow()->subDay()->utc() : null,
    ]);

    return $order;
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

/*
| The parcels. Both conditions are read off the Shipments, and neither
| re-states a rule that lives elsewhere: what counts as decided is the
| delivery queue's own scope, and what counts as stuck is the horizon the
| sweep measures and writes down.
*/

it('lists an Order whose parcel came back and nobody has decided about it', function () {
    $waiting = orderSentAndReturned();

    /** Sent again: a parcel is out for it, which is one of the two decisions. */
    $resent = orderSentAndReturned();
    Shipment::factory()->for($resent)->create();

    /** Sent again and it arrived: the other end of decided. */
    $arrived = orderSentAndReturned();
    Shipment::factory()->for($arrived)->delivered()->create();

    /** Cancelled by a User, which is the decision a courier never gets to make for them (ADR-0018). */
    $cancelled = orderSentAndReturned(OrderStatus::Cancelled);

    /** Gone for good: an Order that should never have existed is not a question anybody has to answer. */
    orderSentAndReturned()->delete();

    $list = needsAttentionAt();

    expect(attentionRows($list))->toBe([
        [$waiting->order_number, AttentionReason::ParcelReturned],
    ])
        /** Since the parcel came back, which is the last thing written to a row nothing polls any more. */
        ->and($list->rows[0]->since->equalTo(reportingNow()->subDays(2)))->toBeTrue()

        /** And the return moved nothing of the Order's own: both Orders read exactly as their Users left them. */
        ->and($waiting->refresh()->status)->toBe(OrderStatus::Confirmed)
        ->and($cancelled->refresh()->status)->toBe(OrderStatus::Cancelled);
});

it('lists a parcel the sweep has flagged as stuck, and not one still inside the horizon', function () {
    $this->travelTo(reportingNow());

    $silent = orderWithParcelBookedSecondsAgo(CourierSweep::STUCK_AFTER_SECONDS + 60);
    $fresh = orderWithParcelBookedSecondsAgo(CourierSweep::STUCK_AFTER_SECONDS - 60);

    /** A parcel a User has already written off is nobody's to chase, however long it was out. */
    $abandoned = orderWithParcelBookedSecondsAgo(CourierSweep::STUCK_AFTER_SECONDS * 2, abandoned: true);

    /** Nothing is stuck until the sweep says so: the horizon is the sweep's own and is never measured a second time here. */
    expect(needsAttentionAt()->rows)->toBe([]);

    app(CourierSweep::class)->run();

    $list = needsAttentionAt();

    expect(attentionRows($list))->toBe([
        [$silent->order_number, AttentionReason::ParcelStuck],
    ])
        /** Since the parcel was handed over, which is how long the Customer has been waiting — not since the flag went on it. */
        ->and($list->rows[0]->since->equalTo(reportingNow()->subSeconds(CourierSweep::STUCK_AFTER_SECONDS + 60)))->toBeTrue()
        ->and($fresh->shipments()->sole()->stuck_at)->toBeNull()
        ->and($abandoned->shipments()->sole()->stuck_at)->toBeNull();
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
