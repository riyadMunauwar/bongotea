<?php

namespace App\Support\Checkout;

use Illuminate\Contracts\Support\Arrayable;

/**
 * What a Merchant has said about the Checkout's Container: the two boxes the
 * island draws itself inside.
 *
 * There are two — the ground and the card. The ground is the section the design
 * paints behind everything (`.gio-checkout`: the background token's fill and the
 * design's outer padding); the card is the one bordered panel the Customer reads
 * inside it (`.gio-checkout-card`). A Merchant who wraps the shortcode in their
 * own styled section wants the ground gone, so the Checkout sits flush inside
 * their wrapper rather than in a second, unwanted band.
 *
 * This is the Container concern, and it is deliberately not a Style Token: a
 * token is a colour, a corner or a typeface read by a flat rule that was already
 * there, whereas turning the ground off is structural — the section is present
 * or it is neutralised. The eleven Style Tokens stay eleven; this is a separate
 * typed value object on the Checkout Config, stored under its own key (see
 * ADR-0027).
 *
 * Read totally, like everything a Checkout Config is read from: a value this
 * installation cannot use is dropped for its default and never throws, because a
 * landing page answering paid traffic with a 500 costs more than one drawn with
 * the ground it started with.
 *
 * @implements Arrayable<string, mixed>
 */
class CheckoutContainer implements Arrayable
{
    public function __construct(
        /**
         * Whether the Checkout draws its ground: the grey band behind the card
         * and the padding around it.
         *
         * On is the Checkout as it has always drawn, so a Merchant who never
         * opens the Container tab sees the form they already know. Off removes
         * the ground and nothing else — the card, and everything a Customer
         * reads inside it, stays exactly where it was — so the Checkout sits
         * flush inside a wrapper the Merchant drew themselves.
         */
        public readonly bool $enabled,

        /**
         * The padding around the card, inside the ground: the design's outer
         * space, emitted only while the ground is drawn. Off it is `0` whatever
         * this holds, because there is no band to pad.
         */
        public readonly CheckoutPadding $ground,

        /**
         * The padding inside the card, between its border and everything a
         * Customer reads. Dropped to `0` is half the flush recipe — the card
         * dissolved into the Merchant's own wrapper with the ground off, the
         * Surface token transparent and the Border token transparent.
         */
        public readonly CheckoutPadding $card,

        /**
         * How wide the card sits, written straight into its `max-width`: the
         * design's `35rem` unless the Merchant narrows or widens it. A per-Page
         * value emitted as a literal figure — the same way the card's padding is
         * — reverses the retired width token deliberately, sound because the
         * one figure in the one card rule is no media-query prelude (ADR-0027).
         *
         * Refused and read as totally as a padding, and against the same
         * allowlist, so a length this installation cannot use drops to the
         * design's figure rather than reaching a Customer as a broken sheet.
         * `100%` fills the parent, at which point alignment stops mattering.
         */
        public readonly string $width,

        /**
         * Where the card sits inside its parent — left, centred or right — by an
         * auto margin on the side with the slack. Applies whether the ground is
         * drawn or not, so a bare Checkout can still be narrowed and placed
         * inside a Merchant's own wrapper.
         */
        public readonly CheckoutAlignment $alignment,
    ) {}

    /**
     * The card's width on a Page that says nothing about it: the design's
     * figure, the handoff README's 560px in rem.
     */
    public const DEFAULT_WIDTH = '35rem';

    /**
     * The ground's padding on a Page that says nothing about it: the design's
     * outer space, wider top-and-bottom than side-to-side.
     */
    public static function defaultGround(): CheckoutPadding
    {
        return new CheckoutPadding('2.5rem', '1.5rem');
    }

    /**
     * The card's padding on a Page that says nothing about it: the design's
     * even inner space on every side.
     */
    public static function defaultCard(): CheckoutPadding
    {
        return new CheckoutPadding('1.75rem', '1.75rem');
    }

    /**
     * The Container a Page starts with, and the one every existing Page reads
     * where it stored nothing: the ground on and both boxes at the design's
     * padding, which is today's design.
     */
    public static function default(): self
    {
        return new self(
            enabled: true,
            ground: self::defaultGround(),
            card: self::defaultCard(),
            width: self::DEFAULT_WIDTH,
            alignment: CheckoutAlignment::Centre,
        );
    }

    /**
     * Rebuild a Container from its stored shape.
     *
     * Absent is the ground on and both paddings at the design's figures, which
     * is what every Config written before this concern existed holds and what
     * those Pages have always drawn — so a published Page reads back
     * byte-identically with no data migration. Anything that is not a boolean,
     * or a padding this installation cannot use, is dropped for that same
     * default, like every other unusable value a Config holds.
     *
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored): self
    {
        $enabled = $stored['enabled'] ?? true;

        return new self(
            enabled: is_bool($enabled) ? $enabled : true,
            ground: CheckoutPadding::fromArray((array) ($stored['ground'] ?? []), self::defaultGround()),
            card: CheckoutPadding::fromArray((array) ($stored['card'] ?? []), self::defaultCard()),
            /**
             * The same keep-or-drop a padding side gets, against the same
             * refusal, so the card's width and its padding cannot disagree about
             * what a length is: refused twice, dropped silently here and worded
             * on the tab, and absent or blank is the design's `35rem`.
             */
            width: CheckoutPadding::kept($stored['width'] ?? null, self::DEFAULT_WIDTH),
            alignment: CheckoutAlignment::fromStored($stored['alignment'] ?? null),
        );
    }

    /**
     * Whether the ground is drawn and still at the design's padding, so the
     * phone's tighter outer figure still narrows it. A Merchant whose ground
     * padding differs from the design's gets it at every width instead: there
     * is no phone control to have chosen a second figure with, and a value
     * equal to the design's figure is the design's figure — narrowed on the
     * phone like any other, and to no visible difference.
     */
    public function groundTightensOnPhone(): bool
    {
        return $this->enabled && $this->ground->equals(self::defaultGround());
    }

    /**
     * Whether the card is still at the design's padding, so the phone's tighter
     * inner figure still narrows it. A card padding that differs from the
     * design's — a `0` set for the flush recipe, say — is not re-padded on a
     * phone; one equal to the design's figure is that figure, narrowed like any
     * other.
     */
    public function cardTightensOnPhone(): bool
    {
        return $this->card->equals(self::defaultCard());
    }

    /**
     * @return array{enabled: bool, ground: array{vertical: string, horizontal: string}, card: array{vertical: string, horizontal: string}, width: string, alignment: string}
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'ground' => $this->ground->toArray(),
            'card' => $this->card->toArray(),
            'width' => $this->width,
            'alignment' => $this->alignment->value,
        ];
    }
}
