<?php

namespace App\Support\Tracking;

use App\Enums\TrackingMoment;
use App\Models\Destination;
use App\Models\Order;
use App\Models\Page;
use App\Models\TrackingEvent;
use Illuminate\Support\Carbon;

/**
 * Where a Moment becomes rows.
 *
 * Nothing here calls an ad platform. A Moment writes one outbox row per
 * applicable Destination per provider event and returns, which is the whole
 * of how a traffic spike costs one small write per visit instead of one
 * outbound HTTP call, and how somebody else's outage is never ours.
 *
 * Writing is idempotent by the outbox's unique index rather than by a check
 * here: a Verification is reached from the Processing Page, a Notification
 * and the sweep, and the second and third writes must be no-ops without the
 * three call sites having to coordinate.
 */
class MomentRecorder
{
    public function __construct(private readonly TrackingDestinations $destinations) {}

    /**
     * The Capture Moment: a visitor submitted the Checkout and an Order came
     * into existence.
     *
     * A Cash on Delivery Order reports its `Purchase` here, because there is
     * no later moment — the courier collects at the door and nothing else
     * about the Order will ever be confirmed. An Order that chose an online
     * method reports nothing yet: it may be abandoned at the gateway, and a
     * Purchase reported for money that never moved is reported revenue the
     * Merchant does not have. Its Moment is Paid, recorded by the
     * Verification that says so.
     */
    public function captured(Order $order, TrackingContext $context): void
    {
        if (self::purchaseMomentFor($order) !== TrackingMoment::Capture) {
            return;
        }

        $this->record(
            TrackingMoment::Capture,
            deduplicationId: $order->order_number,
            occurredAt: $order->created_at ?? Carbon::now(),
            page: $order->page,
            order: $order,
            context: $context,
        );
    }

    /**
     * Which Moment an Order's Purchase is reported at: Capture for Cash on
     * Delivery, Paid for an online method.
     *
     * The one wording of that rule, which `captured()` and the browser's own
     * copy of the Purchase both ask rather than restate. Static because it is
     * a rule and not a collaboration: nothing about it is configured, and a
     * caller reading it is not thereby recording anything.
     */
    public static function purchaseMomentFor(Order $order): TrackingMoment
    {
        return $order->payment_method->isOnline() ? TrackingMoment::Paid : TrackingMoment::Capture;
    }

    /**
     * One row per applicable Destination per event that Destination's
     * Provider produces for this Moment.
     *
     * The deduplication id is what the browser copy of the same event will
     * carry, so Meta counts one and not two; the Order's number for Capture
     * and Paid, because both browser and server can reach it without
     * coordinating.
     */
    private function record(
        TrackingMoment $moment,
        string $deduplicationId,
        Carbon $occurredAt,
        ?Page $page,
        ?Order $order,
        TrackingContext $context,
    ): void {
        foreach ($this->destinations->forPage($page) as $destination) {
            foreach ($destination->driver()->eventsFor($moment) as $event) {
                $this->write($destination, $moment, $event, $deduplicationId, $occurredAt, $page, $order, $context);
            }
        }
    }

    private function write(
        Destination $destination,
        TrackingMoment $moment,
        string $event,
        string $deduplicationId,
        Carbon $occurredAt,
        ?Page $page,
        ?Order $order,
        TrackingContext $context,
    ): void {
        TrackingEvent::query()->firstOrCreate(
            [
                'destination_id' => $destination->getKey(),
                'deduplication_id' => $deduplicationId,
                'event' => $event,
            ],
            [
                'moment' => $moment,
                'occurred_at' => $occurredAt,
                'page_id' => $page?->getKey(),

                /** The attribution that survives the Page being renamed or deleted, as an Order keeps its own. */
                'page_slug' => $page?->slug ?? $order?->page_slug,

                'order_id' => $order?->getKey(),
                'source_url' => $context->sourceUrl,
                'ip_address' => $context->ipAddress,
                'user_agent' => $context->userAgent,
                'click_ids' => $context->clickIds === [] ? null : $context->clickIds,
            ],
        );
    }
}
