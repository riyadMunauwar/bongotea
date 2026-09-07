<?php

namespace App\Support\Checkout;

/**
 * How a Checkout is arranged.
 *
 * A layout decides what the island draws, and nothing about what it asks for or
 * what it charges: the questions, the delivery arrangements and the token set
 * are the same objects whichever of these is chosen, and every one of them is
 * rendered by both.
 *
 * There is one stylesheet, and a layout adds a class to the root and a small
 * block of its own rules to the end of it, rather than a sheet of its own.
 * These styles ride inside every cached page, so a layout that shipped its own
 * copy would charge every visitor for an arrangement their Page is not using,
 * whereas a block emitted only where it applies charges nobody (ADR-0009).
 */
enum CheckoutLayout: string
{
    case Stacked = 'stacked';
    case FormOnly = 'form_only';

    /**
     * What a stored Config's layout is, whatever it happens to hold.
     *
     * Total, like everything a Config is read from: a name nothing renders and
     * a Config written before a Page could choose both mean the arrangement the
     * Checkout has always had. A landing page answering paid traffic with a 500
     * costs more than one drawn in the layout it started in.
     */
    public static function fromStored(mixed $stored): self
    {
        return (is_string($stored) ? self::tryFrom($stored) : null) ?? self::Stacked;
    }

    /**
     * Whether this layout draws the Products the Page sells.
     *
     * False is the whole of what form-only is: a Page whose own markup sold the
     * product above the fold has no business selling it a second time in
     * smaller type. What it still does is capture the same Order — one of
     * everything the Page sells, at the Page's own prices, totalled in the
     * summary like anywhere else.
     */
    public function showsProducts(): bool
    {
        return $this === self::Stacked;
    }

    /**
     * The class this layout puts on the Checkout root, beside `gio-checkout`.
     *
     * Always emitted, in every layout, so a Merchant writing their own CSS has
     * a stable thing to hang it on whether or not the arrangement they chose
     * carries any rules of its own. It is a modifier on one base sheet and
     * never a second sheet: adding a third layout is a rule, not a copy.
     */
    public function modifierClass(): string
    {
        return match ($this) {
            self::Stacked => 'gio-checkout-stacked',
            self::FormOnly => 'gio-checkout-form-only',
        };
    }

    /**
     * The rules this arrangement adds to the base sheet.
     *
     * Emitted only on a Page arranged in this layout, and never as a sheet of
     * its own: the base is common to every arrangement and this is a small
     * block appended to it (ADR-0009). A copy per layout would charge every
     * visitor for the arrangements their Page is not drawing; a block that
     * appears only when it applies charges nobody.
     *
     * One block per case, written by hand, so nothing here is generated from a
     * Merchant's figures — the sheet is still the same bytes for every Page
     * arranged the same way, however many questions it asks and however much
     * of it is restyled. That property is now pinned per layout rather than
     * across all of them.
     *
     * Both of today's arrangements add nothing. Stacked is what the base sheet
     * already draws, and form-only differs by what the view draws rather than
     * by any rule — the product panel is omitted rather than hidden, which is
     * what leaves `.gio-checkout-form-only` a stable hook for a Merchant's own
     * CSS carrying no declarations of ours.
     */
    public function styleRules(): string
    {
        return match ($this) {
            self::Stacked => '',
            self::FormOnly => '',
        };
    }

    /**
     * What the layout does, said in terms of what the Merchant will see change.
     */
    public function helperText(): string
    {
        return match ($this) {
            self::Stacked => 'What the checkout has always drawn: the products, then the questions, then the total.',
            self::FormOnly => 'The questions and the total, with no product panel — for a page whose own markup already sold the product. The order is still for everything the page sells, so the customer is never asked to choose.',
        };
    }

    /**
     * What the Checkout tab calls each arrangement.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Stacked->value => 'Product and form',
            self::FormOnly->value => 'Form only',
        ];
    }
}
