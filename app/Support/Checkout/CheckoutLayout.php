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
 * There is one stylesheet, and a layout adds a class to the root rather than a
 * sheet of its own. That is what keeps the styles the same bytes in both
 * layouts — they ride inside every cached page, so a layout that shipped its
 * own copy would charge every visitor for a layout their Page is not using.
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
     * Always emitted, in both layouts, so a Merchant writing their own CSS has
     * a stable thing to hang it on and so the sheet reads the same either way.
     * It is a modifier on one base sheet and never a second sheet: adding a
     * third layout is a rule, not a copy.
     */
    public function modifierClass(): string
    {
        return match ($this) {
            self::Stacked => 'gio-checkout-stacked',
            self::FormOnly => 'gio-checkout-form-only',
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
