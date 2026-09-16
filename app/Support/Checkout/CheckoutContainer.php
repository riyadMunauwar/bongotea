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
 * @implements Arrayable<string, bool>
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
        public readonly bool $enabled = true,
    ) {}

    /**
     * The Container a Page starts with, and the one every existing Page reads
     * where it stored nothing: the ground on, which is today's design.
     */
    public static function default(): self
    {
        return new self(enabled: true);
    }

    /**
     * Rebuild a Container from its stored shape.
     *
     * Absent is the ground on, which is what every Config written before this
     * concern existed holds and what those Pages have always drawn — so a
     * published Page reads back byte-identically with no data migration.
     * Anything that is not a boolean is dropped for that same default, like
     * every other unusable value a Config holds.
     *
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored): self
    {
        $enabled = $stored['enabled'] ?? true;

        return new self(enabled: is_bool($enabled) ? $enabled : true);
    }

    /**
     * @return array{enabled: bool}
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
        ];
    }
}
