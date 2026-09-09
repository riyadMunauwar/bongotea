<?php

namespace App\Support\OrderComplete;

/**
 * How an Order Complete Page is arranged.
 *
 * A layout decides what the page draws, and nothing about what the Order
 * contains: the order number, the lines, the totals, how it is paid and where
 * it is delivered are the same facts whichever of these is chosen, and every
 * one of them is drawn by all of them. A layout may draw a slot of its own — a
 * row of steps, a set of tiles, a status line — and only a Page arranged in
 * that layout is asked what the slot says.
 *
 * One base sheet, and a layout adds a class to the root and a small block of
 * its own rules to the end of it, following the Checkout Layout convention
 * (ADR-0009). The page is uncached, so the byte-weight argument does not carry
 * here; the convention is kept so that adding a layout is a rule and not a copy.
 */
enum OrderCompleteLayout: string
{
    case CenteredClassic = 'centered_classic';

    /**
     * What a stored Config's layout is, whatever it happens to hold.
     *
     * Total, like everything a Config is read from: a name nothing draws and a
     * Config written before a Page could choose one both mean the arrangement
     * the page has always had. A Customer holding an old link is owed a page,
     * never a 500.
     */
    public static function fromStored(mixed $stored): self
    {
        return (is_string($stored) ? self::tryFrom($stored) : null) ?? self::CenteredClassic;
    }

    /**
     * The class this layout puts on the page root, beside `gio-order-complete`.
     *
     * Always emitted, so the arrangement is readable off the document whether
     * or not it carries rules of its own.
     */
    public function modifierClass(): string
    {
        return match ($this) {
            self::CenteredClassic => 'gio-order-complete-centered-classic',
        };
    }

    /**
     * The rules this arrangement adds to the base sheet, emitted only on a
     * page arranged in this layout and never as a sheet of its own.
     *
     * Centered classic adds nothing: it is what the base sheet already draws.
     */
    public function styleRules(): string
    {
        return match ($this) {
            self::CenteredClassic => '',
        };
    }

    /** Whether this layout draws the status line above the headline. */
    public function drawsStatusLine(): bool
    {
        return match ($this) {
            self::CenteredClassic => false,
        };
    }

    /** Whether this layout draws the four steps of what happens next. */
    public function drawsSteps(): bool
    {
        return match ($this) {
            self::CenteredClassic => false,
        };
    }

    /** Whether this layout draws the three tiles under the headline. */
    public function drawsTiles(): bool
    {
        return match ($this) {
            self::CenteredClassic => false,
        };
    }

    /** Whether this layout draws a button that opens the browser's print dialog. */
    public function drawsPrintButton(): bool
    {
        return match ($this) {
            self::CenteredClassic => false,
        };
    }

    /**
     * What the layout does, said in terms of what the Merchant will see.
     */
    public function helperText(): string
    {
        return match ($this) {
            self::CenteredClassic => 'One centred card: a tick in your accent colour, the headline and message, the order number, the lines and total, where it is going, your note, and the buttons.',
        };
    }

    /**
     * What the Order complete tab calls each arrangement.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::CenteredClassic->value => 'Centered classic',
        ];
    }
}
