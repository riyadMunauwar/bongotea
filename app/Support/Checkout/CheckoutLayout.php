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
    case Split = 'split';
    case Compact = 'compact';
    case SummaryFirst = 'summary_first';
    case FormOnly = 'form_only';

    /**
     * What the split arrangement adds to the base sheet.
     *
     * All of it is inside one media query, and that is the whole of the
     * fallback. Below the breakpoint nothing here applies, so a Customer on a
     * phone is served the island the base sheet already draws — the products,
     * the questions, the summary, the button, in the order the bytes are
     * written in. Two columns are something only a wide screen ever sees,
     * which is what keeps this an arrangement rather than a sixth one to
     * maintain for the device most of this traffic arrives on.
     *
     * The width token sizes the form column and the container is worked out
     * from it, never the other way round. `--gio-max-width` means one thing in
     * every arrangement: how wide the part a Customer types into sits. Letting
     * it widen the container here and the columns fall where they may would
     * quietly turn it into something else — a Merchant who set a narrow form
     * and then chose split would get that figure honoured as two columns too
     * narrow to type in, and a setting that stops meaning what it says on one
     * arrangement is the kind of thing nobody reports, because nothing failed.
     * So the container is the form column plus the twenty rem around it — a
     * sixteen rem panel, the two rem between them, and the rem of padding on
     * each side — plus the border, both of which count against the figure
     * because the root is `border-box`. The token itself is left exactly as
     * the Merchant set it. It is not given a default of its own here: the
     * token set is sparse and filled at emission so that a Merchant's value is
     * the only value.
     *
     * The track then takes eighteen of those twenty back out, and reads the
     * Merchant's figure nowhere — which is what makes this hold for a width
     * typed as a percentage as well as for one typed as a length. A percentage
     * is measured against whatever box the declaration reading it sits on, and
     * the container and the track are two different boxes: `50%` written into
     * both would size the container against the element the shortcode sits in,
     * as it promises, and then the form column against the container, which is
     * half of a half and not what anybody asked for. Taken off the container
     * instead, the arithmetic cancels — the container's content box is the
     * Merchant's figure plus eighteen rem, so the form column comes out as the
     * figure itself, in whatever unit it was typed. The two constants are one
     * subtraction apart and the difference is the base sheet's own padding;
     * `LandingPageTest` reads all three rather than assuming them, because
     * they are declared in two different files.
     *
     * The panel is bounded below as well. A container squeezed into something
     * narrower than the arrangement needs would otherwise take the panel down
     * with the form column, so the second track carries a floor and the first
     * gives way to it — the arrangement survives being dropped into a column
     * narrower than the breakpoint suggests.
     *
     * Two rows are stated, and the second is why either of them is. The
     * questions span both, so that the first belongs to the products alone: a
     * row the questions were measured into would be as tall as the form is
     * long, and the summary — opening at the top of the row below it — would
     * be pushed to the foot of the page, where the two halves of the panel
     * stop being one column. A spanning item does not grow tracks it shares
     * with a flexible one, which is what keeps the first row the products' own
     * height while the second takes everything the questions have left. So the
     * summary opens directly beneath the picture of what it totals. What comes
     * after the questions falls into rows of its own, below both.
     *
     * That second row is also what the summary is pinned inside. A sticky box
     * is bounded by its own area and by nothing larger, so the pin lands on
     * the summary rather than on the panel above it — the products scroll past
     * and the figure the Customer is agreeing to hand over in cash stays on
     * screen for the whole length of the form. Pinning both is not available
     * without wrapping them in an element of their own, and that element would
     * have to reorder the bytes: the questions sit between them, and the order
     * they are written in is the order a Customer whose stylesheet never
     * arrives reads them in.
     *
     * Everything else keeps the column the form is in, said outright rather
     * than left to auto-placement, which would otherwise drop the whole-form
     * failure slot into the panel the first time a Customer saw one. The error
     * slots are `display: none` until the island writes to them, so they are
     * not items at all until they say something, and they take their place
     * above the button in the order the bytes put them. The button carries the
     * separation the summary used to be, exactly as it does where the summary
     * moves.
     */
    private const SPLIT_RULES = <<<'CSS'
        @media (min-width: 60rem) {
        .gio-checkout-split { max-width: calc(var(--gio-max-width) + 20rem + var(--gio-border-width) * 2); }
        .gio-checkout-split .gio-checkout-form { display: grid; grid-template-columns: minmax(0, calc(100% - 18rem)) minmax(12rem, 1fr); grid-template-rows: auto 1fr; column-gap: 2rem; }
        .gio-checkout-split .gio-checkout-fields { grid-column: 1; grid-row: 1 / 3; }
        .gio-checkout-split .gio-checkout-error { grid-column: 1; }
        .gio-checkout-split .gio-checkout-submit { grid-column: 1; margin: 1rem 0 0; }
        .gio-checkout-split .gio-checkout-note { grid-column: 1; }
        .gio-checkout-split .gio-checkout-products { grid-column: 2; grid-row: 1; }
        .gio-checkout-split .gio-checkout-summary { grid-column: 2; grid-row: 2; align-self: start; position: sticky; top: 1rem; margin: 0; }
        }
        CSS;

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
     * What the summary-first arrangement adds to the base sheet.
     *
     * The whole of it is one reordering: the form becomes a column a rule can
     * arrange, and the questions, the button and the line beneath the button
     * are pushed below the summary. Everything else keeps the place it has —
     * so a Page reads products, total, then form, which is the arrangement for
     * a Page whose own copy has already done the selling and where the form is
     * the only thing left.
     *
     * The questions move rather than the summary, and that is the decision
     * worth reading twice. Pulling the summary to the top would put a total
     * above the lines it totals, and the rule the base draws along the top of
     * it — the thing that sets the figure apart from what is above it — would
     * become a hairline across the top of the island separating nothing. So
     * the products keep the top, the summary keeps its place under them, and
     * what is below the summary is what moves.
     *
     * Three orders and no more, because everything left unnumbered stays where
     * the bytes put it, in the bytes' own order. The slot where a whole Order
     * is refused is one of those: it lands between the total and the questions
     * here rather than above the button, and that is where a message about the
     * whole form belongs. Nothing is lost by the move — that slot is written by
     * the island's script or not at all, and the scroll that writes it takes
     * the Customer to it wherever the arrangement put it.
     *
     * The delivery choice moves with the questions, because it is drawn as one
     * — a priced row a customer picks from, spaced like every other thing the
     * form asks. So a Page offering more than one arrangement does put its
     * total above a control that changes it. That is the arrangement working
     * as asked rather than a hole in it: the form opens on the first option
     * with its amount already in the figure above, so the total is truthful
     * before anything is touched, and the script retotals it on every touch
     * after.
     *
     * Two margins go with the reordering, and both are the arrangement paying
     * for what it moved. Flex items do not collapse their margins, so the
     * products' bottom margin would add to the summary's top one and open a gap
     * neither of them asks for — trimmed on the panel rather than on the
     * summary, which keeps the spacing the summary has everywhere else and
     * leaves the slot between them, where an order for nothing is refused,
     * clear of the rule the summary draws along its top. And with the summary
     * gone from between the last question and the button, the button has to
     * carry the separation the summary used to be.
     *
     * What this does not do is reorder the bytes. A Customer whose stylesheet
     * never arrives, and one listening to the page rather than looking at it,
     * reads the stacked order — which is a truthful order and the one every
     * other arrangement writes. Nothing in the summary can be focused, so
     * nothing is reachable in one order and read in another; a control there
     * would make this a different question.
     */
    private const SUMMARY_FIRST_RULES = <<<'CSS'
        .gio-checkout-summary-first .gio-checkout-form { display: flex; flex-direction: column; }
        .gio-checkout-summary-first .gio-checkout-products { margin: 0; }
        .gio-checkout-summary-first .gio-checkout-fields { order: 1; }
        .gio-checkout-summary-first .gio-checkout-submit { order: 2; margin: 1rem 0 0; }
        .gio-checkout-summary-first .gio-checkout-note { order: 3; }
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
     * about to buy. Summary-first draws them too, and draws them first: the
     * lines a total is a total of are the one thing that may not be below it.
     * Split is the arrangement chosen so that a Customer sees them beside the
     * form rather than above it, so it draws them by definition.
     */
    public function showsProducts(): bool
    {
        return match ($this) {
            self::Stacked, self::Split, self::Compact, self::SummaryFirst => true,
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
            self::Stacked, self::Split, self::SummaryFirst, self::FormOnly => true,
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
            self::Split => 'gio-checkout-split',
            self::Compact => 'gio-checkout-compact',
            self::SummaryFirst => 'gio-checkout-summary-first',
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
            self::Split => self::SPLIT_RULES,
            self::Compact => self::COMPACT_RULES,
            self::SummaryFirst => self::SUMMARY_FIRST_RULES,
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
            self::Split => 'Two columns on a wide screen: the form on the left, the products and the total beside it, with the total staying on screen as the customer works down the questions. The width you set is the form column, and the checkout is sized around it. On a phone it draws exactly as the stacked page does.',
            self::Compact => 'The same checkout in less height: every product one row with a small picture, its name and price beside it and the quantity on the right, descriptions left out, and every question filling its own row whatever width you gave it. The widths you set are kept for the day you move the page back.',
            self::SummaryFirst => 'The products and the total above the questions, for a page whose own copy has already sold the product: the customer meets the price first and the form is all that is left. The total is always in full and never behind a tap.',
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
            self::Split->value => 'Split',
            self::Compact->value => 'Compact',
            self::SummaryFirst->value => 'Summary first',
            self::FormOnly->value => 'Form only',
        ];
    }
}
