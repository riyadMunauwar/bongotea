<?php

namespace App\Support\Tracking;

use App\Enums\TrackingMoment;
use App\Enums\TrackingProvider;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TrackingContext;
use App\Models\TrackingEvent;
use Illuminate\Support\Carbon;

/**
 * Where a Moment becomes rows.
 *
 * Nothing here calls an ad platform. A Moment writes one outbox row per
 * switched-on Provider per provider event and returns, which is the whole
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
    public function __construct(
        private readonly TrackingPlans $plans,
        private readonly VisitCounter $visits,
    ) {}

    /**
     * A Moment the browser is the only witness to: a storefront page was
     * loaded, a Product's page opened, a Product added to the Cart, or the
     * Checkout opened.
     *
     * The deduplication id is the browser's own, made up for that page load
     * and carried here by the beacon, because the copy the Pixel fired
     * carries it too and the Provider must count one event. It is written as
     * given: a repeat is not a defect to guard against but the outbox's
     * unique index doing its job, and two loads are two Visits under two
     * identifiers.
     *
     * No outbox row at all where the store reports nowhere.
     *
     * The Merchant's own Visit figure is taken first and separately, because
     * it owes nothing to any ad platform: a store reporting to no Provider
     * still counts its loads while counting is on, and a store whose `PageView`
     * is switched off still counts them too. That is what keeps the Moment
     * seam honest rather than decorative — if a Visit existed only to feed a
     * Provider's event, the first platform added would bend it.
     *
     * What was being bought comes in from the caller rather than from an
     * Order, because a visitor looking at a Product or filling a Cart has not
     * placed one yet. It is frozen onto the row for the reason the content
     * detail is: the sweep runs later, and a Product may be repriced or in the
     * bin by then. A page load is about no Products at all and carries none.
     */
    public function reportedByBrowser(
        TrackingMoment $moment,
        string $deduplicationId,
        TrackingContext $context,
        ?Selection $selection = null,
    ): void {
        if ($moment === TrackingMoment::Visit) {
            $this->visits->count($context->source_url);
        }

        $this->record(
            $this->plans->current(),
            $moment,
            deduplicationId: $deduplicationId,
            occurredAt: Carbon::now(),
            order: null,
            context: $context,
            selection: $selection,
        );
    }

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
        $plan = $this->plans->current();

        /** Nowhere to report to is nothing to report, and nothing worth keeping an IP address for. */
        if ($plan->reportsNowhere()) {
            return;
        }

        /**
         * An Order whose Purchase is still to come is where the browser's
         * word has to outlive the browser: the Verification that says Paid
         * runs from the sweep or from a gateway's Notification, with nobody
         * there to ask. It cannot be read back off the outbox either, because
         * this Moment writes no row for such an Order and so leaves none to
         * copy from.
         *
         * Nothing is kept for an Order reporting here and now: the row below
         * carries the same things, and a second copy would be a second thing
         * to prune.
         */
        if (self::purchaseMomentFor($order) !== TrackingMoment::Capture) {
            $context->keepFor($order);

            return;
        }

        $this->record(
            $plan,
            TrackingMoment::Capture,
            deduplicationId: $order->order_number,
            occurredAt: $order->created_at ?? Carbon::now(),
            order: $order,
            context: $context,
        );
    }

    /**
     * The Paid Moment: a Verification marked one of an Order's Payments Paid.
     *
     * Money moved for this Order, which is a `Purchase` — and the outbox's
     * unique index is what makes it exactly one. No rule about how the Order
     * is paid is re-asked here: the Verification reached from the Processing
     * Page, from a gateway's Notification and from the sweep each writes, the
     * first lands and the rest are no-ops, and an Order that already reported
     * at Capture finds its own row in the way.
     *
     * What the browser told us is read back from the Tracking Context kept at
     * Capture, because by now there is no browser to ask — and nothing at all
     * where the Order reported at Capture and nothing was kept.
     */
    public function paid(Payment $payment): void
    {
        $order = $payment->order;

        if ($order === null) {
            return;
        }

        $this->record(
            $this->plans->current(),
            TrackingMoment::Paid,
            deduplicationId: $order->order_number,
            occurredAt: $payment->verified_at ?? Carbon::now(),
            order: $order,
            context: TrackingContext::forOrder($order),
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
     * One row per switched-on Provider per event it produces for this
     * Moment — less the events the store does not report, which the Plan
     * takes off the list.
     *
     * The deduplication id is what the browser copy of the same event will
     * carry, so Meta counts one and not two; the Order's number for Capture
     * and Paid, because both browser and server can reach it without
     * coordinating.
     */
    private function record(
        TrackingPlan $plan,
        TrackingMoment $moment,
        string $deduplicationId,
        Carbon $occurredAt,
        ?Order $order,
        TrackingContext $context,
        ?Selection $selection = null,
    ): void {
        foreach ($plan->providers() as $provider) {
            foreach ($plan->eventsFor($provider, $moment) as $event) {
                $this->write($provider, $moment, $event, $deduplicationId, $occurredAt, $order, $context, $plan->sendsContentDetail(), $selection);
            }
        }
    }

    private function write(
        TrackingProvider $provider,
        TrackingMoment $moment,
        string $event,
        string $deduplicationId,
        Carbon $occurredAt,
        ?Order $order,
        TrackingContext $context,
        bool $contentDetail,
        ?Selection $selection,
    ): void {
        TrackingEvent::query()->firstOrCreate(
            [
                'provider' => $provider,
                'deduplication_id' => $deduplicationId,
                'event' => $event,
            ],
            [
                'moment' => $moment,
                'occurred_at' => $occurredAt,
                'order_id' => $order?->getKey(),
                'source_url' => $context->source_url,
                'ip_address' => $context->ip_address,
                'user_agent' => $context->user_agent,
                'click_ids' => filled($context->click_ids) ? $context->click_ids : null,

                /**
                 * Frozen here rather than read back at delivery: the sweep
                 * runs minutes or hours later, hands a driver the row and the
                 * Order it points at, and the setting that decided this may
                 * have changed by then.
                 */
                'content_detail' => $contentDetail,

                /**
                 * Frozen for the same reason, and null wherever an Order
                 * holds the answer: a driver reads it off the Order there,
                 * and a second copy would be a second thing to keep true.
                 */
                'selection' => $order === null ? $selection : null,
            ],
        );
    }
}
