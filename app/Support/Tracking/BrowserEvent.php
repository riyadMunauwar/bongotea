<?php

namespace App\Support\Tracking;

use App\Models\Order;

/**
 * One Provider event the browser is told to report, and what it reports it
 * about.
 *
 * The browser half of what `TrackingEvent` is for the server half: the event
 * under the Provider's own name, the deduplication id both copies carry so
 * the Provider counts one, and the Order the parameters are read from at the
 * moment the snippet is written.
 *
 * The deduplication id is carried rather than derived from the Order, for
 * the reason the outbox carries a column for it: what the two halves agree
 * on is a string, and an event that is about no Order at all still has to
 * agree on one.
 *
 * For a page load that string does not exist when the document is written.
 * Where one document is served to every visitor, an identifier baked in at
 * render time would be the same for all of them and the Provider would
 * count the whole campaign as one Visit. What is carried for those is
 * therefore JavaScript naming the value the browser makes up on load, not
 * the value — which is why every Provider asks `deduplicationIdSource()` for
 * it rather than quoting the string itself.
 */
final class BrowserEvent
{
    private function __construct(
        public readonly string $event,
        public readonly string $deduplicationId,
        public readonly ?Order $order,

        /**
         * Whether this event may name what was bought, as the outbox row
         * beside it carries the same answer in a column: the two copies of
         * one event may not disagree about what it was, a suppression least
         * of all.
         */
        public readonly bool $contentDetail,

        /** Whether the deduplication id above is JavaScript naming a value rather than the value. */
        private readonly bool $browserGenerated,

        /**
         * JavaScript naming the Selection this event reports, where the
         * browser is the only one that knows it yet — and null where the
         * document already knows what the event is about, or where it is
         * about nothing.
         *
         * Carried the way the deduplication id above is, and for the same
         * reason: one document served to everyone cannot carry what a
         * particular visitor has in their Cart.
         */
        public readonly ?string $selectionSource = null,
    ) {}

    /**
     * An event about an Order, deduplicated on the Order's number — the one
     * string the browser and the server can both reach without coordinating,
     * and the one the outbox row already carries.
     */
    public static function forOrder(string $event, Order $order, bool $contentDetail = true): self
    {
        return new self($event, $order->order_number, $order, $contentDetail, browserGenerated: false);
    }

    /**
     * An event about the load of a page, deduplicated on an identifier the
     * browser makes up for that load and posts to the beacon, so the copy
     * this fires and the copy the beacon writes are one event.
     *
     * It is about no Order, so it reports no money and no basket — the
     * server copy the beacon writes carries neither either, and the two
     * halves of one event may not disagree about what it was.
     *
     * @param  string  $identifier  JavaScript naming the identifier, not the identifier
     */
    public static function forLoad(string $event, string $identifier): self
    {
        return new self($event, $identifier, null, contentDetail: true, browserGenerated: true);
    }

    /**
     * An event about what a visitor currently has ticked: no Order yet, and
     * a basket the document cannot know, so both the deduplication id and the
     * parameters are JavaScript naming what the browser worked out.
     *
     * Deduplicated on the identifier the browser made up for the Moment — one
     * per opening for Product Viewed and Checkout Started, one per Product per
     * Cart for Added to Cart — and the copy the beacon writes carries the same
     * string.
     *
     * @param  string  $identifier  JavaScript naming the identifier, not the identifier
     * @param  string  $selection  JavaScript naming the Selection, not the Selection
     */
    public static function forSelection(string $event, string $identifier, string $selection, bool $contentDetail): self
    {
        return new self($event, $identifier, null, $contentDetail, browserGenerated: true, selectionSource: $selection);
    }

    /**
     * This event's deduplication id as JavaScript: the string itself where
     * the document already knows it, and the expression naming it where the
     * browser is the one that makes it up.
     *
     * Encoded under the flags Blade's `@json` uses, like everything else a
     * snippet writes, so an order number could not end the element it is
     * written into however it was produced.
     */
    public function deduplicationIdSource(): string
    {
        return $this->browserGenerated
            ? $this->deduplicationId
            : (string) json_encode($this->deduplicationId, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    }
}
