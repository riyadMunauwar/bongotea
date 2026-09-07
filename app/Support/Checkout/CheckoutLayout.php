<?php

namespace App\Support\Checkout;

/**
 * How a Checkout is arranged.
 *
 * A layout decides what the island draws, and nothing about what it asks for or
 * what it charges: the questions, the delivery arrangements and the token set
 * are the same objects whichever of these is chosen, and every one of them is
 * rendered by all of them.
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
    case Compact = 'compact';
    case FormOnly = 'form_only';

    /**
     * What the compact arrangement adds to the base sheet.
     *
     * Every rule here is a descendant of the modifier class, so all of it is
     * inert on a Page arranged any other way — which is the whole of why it can
     * be appended to the one sheet rather than replacing it. Two classes rather
     * than one is what an additive block costs: the base's own `grid-column`
     * and its margins are one class each, and this has to beat them wherever it
     * differs. A Merchant's own stylesheet still wins by naming an ancestor of
     * their own, which is the control the flat sheet exists to protect.
     *
     * Read as three things a compact Page does.
     *
     * A Product becomes one row a thumb can read at a glance: the picture
     * small, the name and the price beside each other rather than stacked, the
     * description dropped, and the stepper pushed to the end of the row by the
     * head taking what is left. The row is laid out with flex rather than with
     * the grid the picker draws, so a Page that asks which products to buy and
     * one that does not compact the same way — and the sibling rule that
     * reveals the stepper on a tick reads the same in either, because they are
     * still siblings.
     *
     * The description is put out of the way here rather than left out of the
     * view, which is the opposite of what form-only does with the panel — and
     * the difference is what is being left out. Form-only drops a picture, a
     * price and a stepper per product, none of which a customer on that page
     * may see; compact drops a sentence, and to drop it in the view would be a
     * second thing the one loop has to know about a layout.
     *
     * It goes for everyone, screen readers included, which is what makes it a
     * different question from the stepper's label. A description is copy the
     * Merchant chose an arrangement without; the label is the name of a
     * control, and it stays exactly as the rest of the form draws it. A dense
     * row is not worth a box whose name a customer using speech cannot say and
     * a customer listening cannot hear.
     *
     * A question fills its row at every width. Compact pairs nothing: two short
     * questions side by side work against a tight rhythm, and the arrangement
     * is chosen by a Merchant who has already said what they want the form to
     * do. Both halves of the pairing have to be undone — the grid's second
     * column and the span a full-width question takes across it — because a
     * question still spanning two columns of a one-column grid would grow the
     * second column back. The stored width is left alone, so a Page moved off
     * compact asks its questions paired again.
     *
     * And the vertical rhythm tightens throughout: the island's own padding,
     * the gaps between Products, between questions, between the rows a customer
     * picks a delivery from and between the lines of the summary. What is left
     * alone is what a thumb has to hit — the padding inside those rows, the
     * boxes, and the button — because the arrangement is for a phone before it
     * is for anything else, and a target too small to tap is not compact.
     *
     * The row wraps, which is the whole of what happens when a quantity is
     * refused. That slot is inside the stepper's end of the row: while it is
     * hidden the end of the row measures the box and nothing else, and when a
     * message appears it is longer than the box by far, so the stepper takes a
     * line of its own beneath and the message reads across the row. Left
     * unwrapped it would be a column the width of a number reading downwards
     * one word at a time, or a name squeezed off the row by the complaint about
     * it.
     */
    private const COMPACT_RULES = <<<'CSS'
        .gio-checkout-compact { margin: 1rem auto; padding: .75rem; }
        .gio-checkout-compact .gio-checkout-products { margin: 0 0 .75rem; }
        .gio-checkout-compact .gio-checkout-product { display: flex; flex-wrap: wrap; gap: .625rem; align-items: center; }
        .gio-checkout-compact .gio-checkout-product + .gio-checkout-product { margin: .5rem 0 0; padding: .5rem 0 0; }
        .gio-checkout-compact .gio-checkout-product-head { flex: 1; min-width: 0; gap: .5rem; }
        .gio-checkout-compact .gio-checkout-product-head > * { min-width: 0; }
        .gio-checkout-compact .gio-checkout-image { width: 2.75rem; height: 2.75rem; }
        .gio-checkout-compact .gio-checkout-name, .gio-checkout-compact .gio-checkout-price { display: inline; margin: 0 .375rem 0 0; font-size: 1em; }
        .gio-checkout-compact .gio-checkout-description { display: none; }
        .gio-checkout-compact .gio-checkout-quantity { margin: 0; }
        .gio-checkout-compact .gio-checkout-quantity-input { width: calc(4.5rem * var(--gio-input-size)); }
        .gio-checkout-compact .gio-checkout-fields { grid-template-columns: 1fr; gap: .5rem; }
        .gio-checkout-compact .gio-checkout-field { grid-column: auto; }
        .gio-checkout-compact .gio-checkout-choice + .gio-checkout-choice { margin: .25rem 0 0; }
        .gio-checkout-compact .gio-checkout-summary { margin: .75rem 0; padding: .5rem 0 0; }
        .gio-checkout-compact .gio-checkout-row { margin: 0 0 .25rem; }
        .gio-checkout-compact .gio-checkout-note { margin: .5rem 0 0; }
        CSS;

    /**
     * What a stored Config's layout is, whatever it happens to hold.
     *
     * Total, like everything a Config is read from: a name nothing renders and
     * a Config written before a Page could choose one both mean the arrangement
     * the Checkout has always had. A landing page answering paid traffic with a
     * 500 costs more than one drawn in the layout it started in.
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
     *
     * Compact draws them like any arrangement that draws them. What it compacts
     * is how a row is laid out, never whether a customer can see what they are
     * about to buy.
     */
    public function showsProducts(): bool
    {
        return match ($this) {
            self::Stacked, self::Compact => true,
            self::FormOnly => false,
        };
    }

    /**
     * Whether two half-width questions sit side by side on a wide screen here.
     *
     * Compact is the one arrangement that pairs nothing, and this is how the
     * Checkout tab knows to say so where the widths are set. The width itself
     * is untouched — it is read again the moment the Page is arranged any other
     * way — so what this answers is what a Merchant is looking at, not what
     * their Config holds.
     */
    public function pairsQuestions(): bool
    {
        return match ($this) {
            self::Stacked, self::FormOnly => true,
            self::Compact => false,
        };
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
            self::Compact => 'gio-checkout-compact',
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
     * Stacked and form-only add nothing. Stacked is what the base sheet
     * already draws, and form-only differs by what the view draws rather than
     * by any rule — the product panel is omitted rather than hidden, which is
     * what leaves `.gio-checkout-form-only` a stable hook for a Merchant's own
     * CSS carrying no declarations of ours.
     */
    public function styleRules(): string
    {
        return match ($this) {
            self::Stacked => '',
            self::Compact => self::COMPACT_RULES,
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
            self::Compact => 'The same checkout in less height: every product one row with a small picture, its name and price beside it and the quantity on the right, descriptions left out, and every question filling its own row whatever width you gave it. The widths you set are kept for the day you move the page back.',
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
            self::Compact->value => 'Compact',
            self::FormOnly->value => 'Form only',
        ];
    }
}
