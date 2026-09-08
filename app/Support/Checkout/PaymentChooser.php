<?php

namespace App\Support\Checkout;

use App\Enums\PaymentMethod;

/**
 * What the Checkout draws to ask how the Customer will pay, if it asks at all.
 *
 * Built from a Config's offered methods and its two switches, and handed to
 * the view whole, so the island reads one object rather than three settings
 * and the decision about whether a chooser is drawn is made in one place.
 *
 * Not drawn where the only method offered is Cash on Delivery and the Page
 * hides that choice, which is every Page as it stands today: a Page that has
 * always been cash-only draws the same form it always did, and the submission
 * carries no method. Everywhere else a radio group is drawn in the Page's
 * order with the first already picked.
 */
final class PaymentChooser
{
    /**
     * What the chooser adds to the base sheet, and only when it is drawn.
     *
     * An additive block on the model of a layout's (ADR-0009): a page that
     * draws no chooser ships no rule for one, so a Page with a default Config
     * emits the sheet it emitted before a method could be chosen, byte for
     * byte. Hand-written and picked by a switch, never built from a figure,
     * so it is not the generated rule the flat sheet exists to avoid.
     *
     * One rule, for the logo. Everything else the chooser draws — the row a
     * customer taps, the words beside the control, the fieldset around them —
     * is the markup a delivery choice or a radio question already draws, and
     * the base sheet already styles. The logo is sized in `em` against the
     * root's font size like every other size below it, so a form grown by the
     * font-size token grows its logos with it, and `flex: none` keeps a wide
     * mark from being squeezed by a long label.
     */
    private const STYLE_RULES = <<<'CSS'
        .gio-checkout-logo { height: 1.5em; width: auto; flex: none; }
        CSS;

    /**
     * @param  list<PaymentMethod>  $methods  In the order they are drawn; the first opens picked.
     */
    private function __construct(
        public readonly array $methods,

        /** Whether each row carries the method's logo beside its name. */
        public readonly bool $showsLogos,
    ) {}

    /**
     * The chooser a Page draws, or the one it does not.
     *
     * @param  list<PaymentMethod>  $offered  The Page's offered methods, already filtered and ordered.
     */
    public static function for(array $offered, bool $showsLogos, bool $hidesLoneCash): self
    {
        if ($hidesLoneCash && $offered === [PaymentMethod::CashOnDelivery]) {
            return new self([], $showsLogos);
        }

        return new self($offered, $showsLogos);
    }

    /**
     * Whether the island draws a chooser at all.
     */
    public function isDrawn(): bool
    {
        return $this->methods !== [];
    }

    /**
     * The rules the chooser adds to the sheet, or nothing where nothing in
     * it needs one.
     *
     * Only a drawn logo reads the rule, so a chooser listing methods by name
     * alone ships none of it — the block appears only where it applies, the
     * same trade a layout's makes.
     *
     * The block ends its own line, because the view writes it immediately
     * before the layout's block on the same line: that is what leaves a page
     * drawing no chooser with no blank line where the block would have been,
     * and a page drawing one with the layout's block starting on a line of
     * its own.
     */
    public function styleRules(): string
    {
        return $this->isDrawn() && $this->showsLogos ? self::STYLE_RULES."\n" : '';
    }
}
