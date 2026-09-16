<?php

namespace App\Support\Checkout;

/**
 * Where the card sits inside its parent: left, centred, or right.
 *
 * A choice of margins rather than a value a single declaration reads, which is
 * one of the two reasons the Container is its own concern and not a Style Token
 * (ADR-0027). The card is capped at a width narrower than the parent a Merchant
 * dropped `[checkout]` into, so there is room on one side or the other to place
 * it — an auto margin takes up the slack, and which side it takes up is this.
 *
 * Three fixed choices and no free value, the same trade `FieldWidth` makes: a
 * figure a Merchant typed would resolve against a container whose width nobody
 * here knows, and land differently on every Page it was copied to. Left, centre
 * and right are the whole of what an auto margin can express, so they are the
 * whole of the vocabulary.
 *
 * Centre is the design's own, and the default every existing Page reads: a
 * Config that says nothing about alignment draws the `margin: 0 auto` the card
 * has always carried, byte for byte.
 */
enum CheckoutAlignment: string
{
    case Left = 'left';

    /** The value stays the conventional CSS-side `center`; the name follows the design's own UK "centred". */
    case Centre = 'center';
    case Right = 'right';

    /**
     * The alignment a stored Config holds, whatever it happens to be.
     *
     * Total, like everything a Checkout Config is read from: a value nothing
     * knows, and a Config written before the Merchant could place the card,
     * both mean the centre the card has always been drawn at.
     */
    public static function fromStored(mixed $stored): self
    {
        return (is_string($stored) ? self::tryFrom($stored) : null) ?? self::Centre;
    }

    /**
     * The `margin` shorthand that places the card: no vertical margin either
     * way, and an auto margin on whichever side has the slack to give.
     *
     * Centre is `0 auto`, the pair the card has always carried, so a Page at
     * the default emits exactly the bytes it did before there was an alignment
     * to choose. Left keeps the card against the parent's start by handing the
     * slack to the right margin, and right does the mirror. At `100%` width
     * there is no slack, so all three place the card identically — as expected.
     */
    public function margins(): string
    {
        return match ($this) {
            self::Left => '0 auto 0 0',
            self::Centre => '0 auto',
            self::Right => '0 0 0 auto',
        };
    }

    /**
     * What the Container tab calls this alignment.
     *
     * An exhaustive match, so a case added here has to be given its word before
     * `labels()` can offer it — the same shape as `FieldWidth::label()`, rather
     * than a hardcoded list a new case would silently drop from the tab.
     */
    public function label(): string
    {
        return match ($this) {
            self::Left => 'Left',
            self::Centre => 'Centre',
            self::Right => 'Right',
        };
    }

    /**
     * Every alignment, in the order a Merchant is offered it: left to right, as
     * the card can be placed. Looped over `cases()` so a new case appears here
     * the moment it exists, and worded through `label()`.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = [];

        foreach (self::cases() as $alignment) {
            $labels[$alignment->value] = $alignment->label();
        }

        return $labels;
    }
}
