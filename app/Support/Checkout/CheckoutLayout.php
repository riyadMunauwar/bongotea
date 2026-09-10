<?php

namespace App\Support\Checkout;

/**
 * How a Checkout is arranged.
 *
 * A layout decides what the island draws and where, and nothing about what it
 * asks for or what it charges: the questions, the delivery arrangements, the
 * payment methods and the token set are the same objects whichever of these is
 * chosen, and every one of them is rendered by all ten, in the same document
 * order, capturing the same complete Order (ADR-0015).
 *
 * Ten of them, in the design's order (1a–1j). There is one stylesheet, and a
 * layout adds a class to the root and a small block of its own rules to the
 * end of it, rather than a sheet of its own. These styles ride inside every
 * cached page, so a layout that shipped its own copy would charge every
 * visitor for an arrangement their Page is not using, whereas a block emitted
 * only where it applies charges nobody (ADR-0009).
 *
 * A layout may own a slot of its own — a numbered headline, a step label — and
 * only a Page arranged in it is asked what the slot says. What a layout draws
 * and does is said here as predicates the view and the Checkout tab both read,
 * every one an exhaustive match over all ten, so a layout added or changed
 * has to answer each question before anything draws it.
 *
 * Stacked is drawn as designed and is what the base sheet draws; the other
 * nine are drawn as designed by a block of their own. Two of them also behave:
 * Stepper shows one panel at a time and Accordion opens one at a time, from
 * the island's one inline script and never from the markup (ADR-0015).
 */
enum CheckoutLayout: string
{
    case Stacked = 'stacked';
    case Split = 'split';
    case SplitReversed = 'split_reversed';
    case Stepper = 'stepper';
    case Accordion = 'accordion';
    case BentoGrid = 'bento_grid';
    case CompactEmbed = 'compact_embed';
    case Chips = 'chips';
    case Invoice = 'invoice';
    case DarkPanel = 'dark_panel';

    /**
     * The names the Checkout stored its first layouts under, and the one of
     * these ten each is read as.
     *
     * Read here and nowhere else. The retired `form_only` is not among them
     * because it was never an arrangement — it was the Product List switch
     * wearing a layout's name — and `CheckoutConfig::fromArray()` is the one
     * reader that remembers what it meant; here it falls to Stacked like any
     * unknown name.
     *
     * @var array<string, self>
     */
    private const RETIRED_NAMES = [
        'compact' => self::CompactEmbed,
        'summary_first' => self::SplitReversed,
    ];

    /**
     * What a stored Config's layout is, whatever it happens to hold.
     *
     * Total, like everything a Config is read from: a name nothing renders and
     * a Config written before a Page could choose one both mean the arrangement
     * the Checkout has always had. A landing page answering paid traffic with a
     * 500 costs more than one drawn in the layout it started in.
     *
     * A Page saved under one of the first layouts keeps working: the old name
     * is read as the one of these ten nearest to what it drew, so nothing a
     * Merchant published comes up empty on upgrade.
     */
    public static function fromStored(mixed $stored): self
    {
        if (! is_string($stored)) {
            return self::Stacked;
        }

        return self::tryFrom($stored) ?? self::RETIRED_NAMES[$stored] ?? self::Stacked;
    }

    /**
     * The words this layout owns of its own, in the order the Customer meets
     * them on the form. Empty for a layout that draws none.
     *
     * @return list<WordSlot>
     */
    public function wordSlots(): array
    {
        return match ($this) {
            self::Stacked => [
                WordSlot::StackedProductsHeadline,
                WordSlot::StackedDetailsHeadline,
                WordSlot::StackedPaymentHeadline,
            ],
            self::Split => [
                WordSlot::SplitOrderHeadline,
                WordSlot::SplitDetailsHeadline,
                WordSlot::SplitDeliveryHeadline,
                WordSlot::SplitPaymentHeadline,
                WordSlot::SplitHint,
            ],
            self::SplitReversed => [
                WordSlot::SplitReversedBundleEyebrow,
                WordSlot::SplitReversedFormHeadline,
                WordSlot::SplitReversedFootnote,
            ],
            self::DarkPanel => [
                WordSlot::DarkPanelSummaryEyebrow,
                WordSlot::DarkPanelFormHeadline,
            ],
            self::BentoGrid => [
                WordSlot::BentoGridProductsEyebrow,
                WordSlot::BentoGridDetailsEyebrow,
                WordSlot::BentoGridPaymentEyebrow,
            ],
            self::CompactEmbed => [
                WordSlot::CompactEmbedTitle,
                WordSlot::CompactEmbedItemCount,
            ],
            self::Chips => [
                WordSlot::ChipsSelectEyebrow,
                WordSlot::ChipsEmptyLine,
                WordSlot::ChipsDeliveryEyebrow,
                WordSlot::ChipsPaymentEyebrow,
            ],
            self::Invoice => [
                WordSlot::InvoiceTitle,
                WordSlot::InvoiceItemHeading,
                WordSlot::InvoiceQuantityHeading,
                WordSlot::InvoiceAmountHeading,
            ],
            self::Stepper => [
                WordSlot::StepperProductsHeadline,
                WordSlot::StepperDetailsHeadline,
                WordSlot::StepperPaymentHeadline,
                WordSlot::StepperProductsStep,
                WordSlot::StepperDetailsStep,
                WordSlot::StepperPaymentStep,
                WordSlot::StepperNext,
                WordSlot::StepperBack,
            ],
            self::Accordion => [
                WordSlot::AccordionProductsHeadline,
                WordSlot::AccordionDetailsHeadline,
                WordSlot::AccordionPaymentHeadline,
            ],
        };
    }

    /**
     * Whether this layout draws one of its own slots — which is also whether
     * a Page arranged in it is asked what the slot says.
     */
    public function draws(WordSlot $slot): bool
    {
        return in_array($slot, $this->wordSlots(), true);
    }

    /**
     * The word this layout draws at one place on the form, or null where it
     * draws none there.
     *
     * How the view asks: it knows where it is — over the products, over the
     * questions, beneath the button — and draws whatever the layout puts
     * there by the slot's own kind, so a layout adding a word never adds a
     * branch to the template. A layout puts at most one word at a place.
     */
    public function slotAt(WordPlace $place): ?WordSlot
    {
        foreach ($this->wordSlots() as $slot) {
            if ($slot->place() === $place) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * Whether a Delivery Option's detail line ("1–2 days") is drawn under its
     * name here.
     *
     * The roomy arrangements draw a delivery choice as a boxed row with room
     * for a second line; the segmented ones draw it as a button a word wide,
     * where a second line has nowhere to go. The line is kept in the Config
     * either way, so a Page moved to a roomy layout draws it again.
     */
    public function drawsDeliveryDetail(): bool
    {
        return match ($this) {
            self::Stacked,
            self::Split,
            self::Stepper,
            self::Accordion,
            self::Invoice,
            self::DarkPanel => true,
            self::SplitReversed,
            self::BentoGrid,
            self::CompactEmbed,
            self::Chips => false,
        };
    }

    /**
     * Whether a Payment Method is named by its short label here rather than
     * its long one.
     *
     * The long label names a method in two scripts with room for a hint; the
     * short one is a word wide, for a segmented control, a two-by-two grid or
     * a centred box on a phone. Which a layout draws is the layout's to say —
     * a Merchant sets both once on the Words tab and never has to know which
     * fits.
     */
    public function drawsShortPaymentLabels(): bool
    {
        return match ($this) {
            self::BentoGrid,
            self::CompactEmbed,
            self::DarkPanel => true,
            self::Stacked,
            self::Split,
            self::SplitReversed,
            self::Stepper,
            self::Accordion,
            self::Chips,
            self::Invoice => false,
        };
    }

    /**
     * Whether two half-width questions sit side by side on a wide screen here.
     *
     * The narrow-column arrangements pair nothing: a Merchant choosing one has
     * already said what they want the form to do, and paired questions work
     * against it. The width itself is untouched — it is read again the moment
     * the Page is arranged any other way — so what this answers is what a
     * Merchant is looking at, not what their Config holds. The Checkout tab
     * says so where the widths are set.
     */
    public function pairsQuestions(): bool
    {
        return match ($this) {
            self::Stacked,
            self::Split,
            self::Stepper,
            self::Accordion,
            self::BentoGrid,
            self::Chips,
            self::Invoice,
            self::DarkPanel => true,
            self::SplitReversed,
            self::CompactEmbed => false,
        };
    }

    /**
     * Whether this layout does something as well as arranging: one panel at
     * a time behind Next and Back, or one section open at a time.
     *
     * Behaviour lives in the island's one inline script, keyed off the
     * layout's modifier class, and never reorders the document or changes what
     * is captured: without script every panel shows in document order and the
     * form submits the same complete Order (ADR-0015).
     */
    public function behaves(): bool
    {
        return match ($this) {
            self::Stepper,
            self::Accordion => true,
            self::Stacked,
            self::Split,
            self::SplitReversed,
            self::BentoGrid,
            self::CompactEmbed,
            self::Chips,
            self::Invoice,
            self::DarkPanel => false,
        };
    }

    /**
     * What the Order Button says here where the Merchant has said nothing.
     *
     * Several of the designs print the live total inside the button, and one
     * (Invoice) words the button differently altogether: a bill is agreed to
     * rather than ordered from, so its button asks the Customer to confirm.
     * The design's words for the layout are the default, and a Merchant's own
     * wording, once written, stands on every layout alike.
     */
    public function defaultButtonWording(): string
    {
        return match ($this) {
            self::Stacked,
            self::SplitReversed,
            self::Stepper,
            self::DarkPanel => OrderButton::DEFAULT_WORDING.' · '.OrderButton::TOTAL_PLACEHOLDER,
            self::Invoice => 'অর্ডার কনফার্ম করুন / Place order',
            self::Split,
            self::Accordion,
            self::BentoGrid,
            self::CompactEmbed,
            self::Chips => OrderButton::DEFAULT_WORDING,
        };
    }

    /**
     * The class this layout puts on the Checkout root, beside `gio-checkout`.
     *
     * Always emitted, in every layout, so a Merchant writing their own CSS has
     * a stable thing to hang it on whether or not the arrangement they chose
     * carries any rules of its own. It is a modifier on one base sheet and
     * never a second sheet: adding a layout is a rule, not a copy.
     */
    public function modifierClass(): string
    {
        return 'gio-checkout-'.str_replace('_', '-', $this->value);
    }

    /**
     * The rules this arrangement adds to the base sheet.
     *
     * Emitted only on a Page arranged in this layout, and never as a sheet of
     * its own: the base is common to every arrangement and this is a small
     * block appended to it (ADR-0009). Every rule in a block is a descendant
     * of the layout's own modifier class — two classes deep, never three —
     * so all of it is inert on a Page arranged any other way.
     *
     * One block per case, written by hand from the handoff README's figures,
     * so nothing here is generated from a Merchant's figures — the sheet is
     * still the same bytes for every Page arranged the same way, however many
     * questions it asks and however much of it is restyled.
     *
     * Stacked adds nothing: it is what the base sheet draws.
     *
     * The two behaving arrangements are the only blocks that write
     * `display: none`, and both write it once, on the `hidden` attribute
     * their own script sets — an author's `display` beats the browser's
     * `[hidden]`, so a layout that hides by script has to say what hidden
     * means. Nothing in the markup carries it, which is the half ADR-0015
     * fixes: without script every panel shows, in the order the document was
     * written. Stepper turns the card into the design's rail-over-panel by
     * unpainting the card and painting the form instead, so the rail the
     * script builds above the form sits on the section's own ground;
     * Accordion paints the card in the footer bar's ink and the panels in the
     * surface over it, so what is left below the last panel reads as the bar
     * the design draws — a pseudo-element could not, because how many panels
     * a Page draws is not known to the sheet.
     *
     * The two-column arrangements share one construction. The form is a grid
     * whose children are placed by row and column outright — the questions
     * wrapper on one side spanning every row, the products, the summary and
     * the button on the other, one row each — and the panel the design
     * draws behind one side is the form's own `::before`, a grid item with
     * no content stretched across that side's rows and columns. Nothing in
     * the document moves (ADR-0015): what a column holds is decided in the
     * sheet, and a visitor whose stylesheet never arrives reads the same
     * order every layout writes. The panel's padding is a track of the grid
     * rather than a margin on each item, so the phone rules can re-place
     * every item on one column without undoing any margin. One row is `1fr`
     * so the side that runs longer spills into it rather than spreading the
     * other side's items apart.
     *
     * The segmented arrangements share one construction too: a delivery or
     * payment fieldset becomes a flex row with a one-pixel gap over its own
     * border-coloured ground, each choice a button a word wide that fills
     * with the accent when its radio is checked, and the radio itself
     * clipped from sight the way a hidden label is — never removed, so the
     * keyboard still reaches it. Compact embed and Chips zero every margin
     * the base sets between the form's children and space them by a flex
     * gap instead, which is how a block spaces siblings without a sibling
     * rule — a third class the ceiling below refuses. Bento grid's cells are
     * the three sections themselves, bordered and filled at one rule, and
     * its bar is the form's `::after` in the design's ink.
     *
     * Invoice is the one arrangement drawn as a table. The form is a grid of
     * spacer tracks rather than a gap, so the receipt-style totals sit tight
     * beneath the item rows while the sections below keep the design's
     * roomier rhythm: the summary is placed at the second row and the
     * questions at the third, and nothing in the document moves (ADR-0015).
     * The products section and every product row are the same four-column
     * grid, so the headings line up with the rows beneath them; which cell
     * each heading takes is said by the first and the last of them, one
     * pseudo-class on the second class, and the empty first cell is where the
     * tick box sits, exactly as the design draws the header row. The
     * continuous rule under the headings is the list's own `border-top`,
     * because a border on three separate cells would break at the gaps
     * between them. On a phone the table drops to the design's two columns
     * and the headings are clipped from sight the way a hidden label is —
     * never removed, so a screen reader still hears them.
     *
     * Invoice is the one block that overrides two tokens the rest of the
     * sheet leaves alone, and both are the design's. The button takes the
     * text colour with the accent on hover rather than `--gio-button-*`,
     * because a bill is agreed to in one ink; and the four figures a receipt
     * is read down — the line totals, the summary, the quantity and a
     * delivery charge — are set in the design's monospace rather than in
     * `--gio-font`, because a column of amounts that do not line up is a
     * column nobody can check. Everything else on the page is still the
     * Merchant's typeface. Note the products section is a grid here, so its
     * template reaches the two sections inside the questions wrapper as
     * well: every child of those is spanned across it by name in this block,
     * and a word Invoice does not draw there would need spanning too.
     *
     * Two things the
     * design draws that the fixed document cannot: the mock's `#GP-0001`,
     * absent because no order number exists before Capture, and the total at
     * the button's far end, because the button carries one line of the
     * Merchant's words and not two slots — a Merchant who wants the figure
     * there writes `{total}` into the wording, as on every other layout. The
     * button is still `space-between` rather than centred, which with one
     * line in it is the design's left-aligned label.
     *
     * Every colour is a token or mixed from two, with two exceptions the
     * design fixes: Dark panel's navy set (`#0f1d4f` and its borders and
     * inks) and the two shades Bento grid's bar is written in (`#b9bec9` for
     * its quieter text, `#4f79e6` for the hover the button takes on it) are
     * the layout, not a Style Token, and are written here in the block that
     * is shipped only to a Page arranged in it. The bar itself is the text
     * token, as the design draws it in the ink.
     */
    public function styleRules(): string
    {
        return match ($this) {
            self::Stacked => '',
            self::Stepper => <<<'CSS'
        .gio-checkout-stepper .gio-checkout-card { max-width: 37.5rem; padding: 0; border: 0; background: transparent; }
        .gio-checkout-stepper .gio-checkout-steps { display: flex; align-items: center; gap: .5rem; margin: 0 0 1.5rem; }
        .gio-checkout-stepper .gio-checkout-step { display: flex; align-items: center; gap: .5rem; padding: 0; border: 0; background: none; color: var(--gio-muted); font-family: inherit; font-size: .8125rem; font-weight: 600; line-height: 1.3; cursor: pointer; }
        .gio-checkout-stepper .gio-checkout-step[aria-current="step"] { color: var(--gio-text); }
        .gio-checkout-stepper .gio-checkout-step-number { display: grid; place-items: center; flex: none; width: 1.625rem; height: 1.625rem; border: 1px solid var(--gio-border); border-radius: 50%; background: var(--gio-surface); font-size: .75rem; }
        .gio-checkout-stepper .gio-checkout-step-number[data-gio-done] { border-color: var(--gio-accent); background: var(--gio-accent); color: var(--gio-surface); }
        .gio-checkout-stepper .gio-checkout-step-line { flex: 1; height: 1px; background: var(--gio-border); }
        .gio-checkout-stepper .gio-checkout-step-line[data-gio-done] { background: var(--gio-accent); }
        .gio-checkout-stepper .gio-checkout-form { display: grid; gap: 1rem; align-content: start; min-height: 18.75rem; padding: 1.5rem; border: 1px solid var(--gio-border); background: var(--gio-surface); }
        .gio-checkout-stepper .gio-checkout-questions { display: contents; }
        .gio-checkout-stepper .gio-checkout-section { margin: 0; }
        .gio-checkout-stepper .gio-checkout-heading { margin: 0 0 .875rem; font-size: 1.0625rem; }
        .gio-checkout-stepper .gio-checkout-payment { grid-template-columns: repeat(auto-fit, minmax(8.75rem, 1fr)); gap: .625rem; }
        .gio-checkout-stepper .gio-checkout-payment-choice { padding: .75rem; font-size: .8125rem; }
        .gio-checkout-stepper .gio-checkout-summary { gap: .375rem; margin: 0; padding: .875rem; border: 1px solid var(--gio-border); background: color-mix(in srgb, var(--gio-text) 3%, var(--gio-surface)); }
        .gio-checkout-stepper .gio-checkout-row { font-size: .8125rem; }
        .gio-checkout-stepper .gio-checkout-total { margin: .25rem 0 0; padding: .5rem 0 0; font-size: 1.0625rem; }
        .gio-checkout-stepper .gio-checkout-nav { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; }
        .gio-checkout-stepper .gio-checkout-back { padding: .75rem 1.125rem; border: 1px solid var(--gio-border); background: transparent; color: color-mix(in srgb, var(--gio-text) 35%, var(--gio-muted)); font-family: inherit; font-size: .875rem; font-weight: 600; cursor: pointer; }
        .gio-checkout-stepper .gio-checkout-next { margin-left: auto; padding: .75rem 1.375rem; border: 0; background: var(--gio-accent); color: var(--gio-surface); font-family: inherit; font-size: .875rem; font-weight: 700; cursor: pointer; }
        .gio-checkout-stepper .gio-checkout-submit { margin: 0; }
        .gio-checkout-stepper .gio-checkout-note { margin: 0; }
        .gio-checkout-stepper [hidden] { display: none; }
        @media (max-width: 37.5rem) { .gio-checkout-stepper .gio-checkout-card { padding: 0; } .gio-checkout-stepper .gio-checkout-form { min-height: 0; padding: 1.25rem 1rem; } .gio-checkout-stepper .gio-checkout-step-label { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; } }

CSS,
            self::Accordion => <<<'CSS'
        .gio-checkout-accordion .gio-checkout-card { max-width: 38.75rem; padding: 0; background: color-mix(in srgb, var(--gio-text) 3%, var(--gio-surface)); }
        .gio-checkout-accordion .gio-checkout-form { display: grid; grid-template-columns: 1.125rem minmax(0, 1fr) .75rem auto 1.125rem; align-items: center; }
        .gio-checkout-accordion .gio-checkout-questions { display: contents; }
        .gio-checkout-accordion .gio-checkout-section { display: flex; flex-direction: column; gap: 1.125rem; grid-column: 1 / -1; margin: 0; padding: 0 1.125rem 1.125rem; border-bottom: 1px solid var(--gio-border); background: var(--gio-surface); }
        .gio-checkout-accordion .gio-checkout-section[data-gio-shut] { padding-bottom: 0; }
        .gio-checkout-accordion .gio-checkout-heading { margin: 0 -1.125rem; }
        .gio-checkout-accordion .gio-checkout-panel-head { display: flex; align-items: center; gap: .75rem; box-sizing: border-box; width: 100%; padding: 1rem 1.125rem; border: 0; background: transparent; color: inherit; font: inherit; text-align: left; cursor: pointer; }
        .gio-checkout-accordion .gio-checkout-panel-head[aria-expanded="true"] { background: color-mix(in srgb, var(--gio-text) 3%, var(--gio-surface)); }
        .gio-checkout-accordion .gio-checkout-panel-title { flex: 1; min-width: 0; font-size: .9375rem; }
        .gio-checkout-accordion .gio-checkout-panel-summary { font-size: .8125rem; font-weight: 500; text-align: right; color: var(--gio-muted); }
        .gio-checkout-accordion .gio-checkout-panel-chevron { flex: none; font-size: .75rem; font-weight: 400; color: var(--gio-muted); }
        .gio-checkout-accordion .gio-checkout-product { gap: .75rem; padding: .625rem .75rem; }
        .gio-checkout-accordion .gio-checkout-product-words { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0 .375rem; }
        .gio-checkout-accordion .gio-checkout-price { margin: 0; }
        .gio-checkout-accordion .gio-checkout-quantity-step { width: 1.75rem; height: 1.75rem; font-size: .9375rem; }
        .gio-checkout-accordion .gio-checkout-delivery { margin: 0; }
        .gio-checkout-accordion .gio-checkout-payment { grid-template-columns: repeat(auto-fit, minmax(8.75rem, 1fr)); gap: .625rem; }
        .gio-checkout-accordion .gio-checkout-payment-choice { padding: .75rem; font-size: .8125rem; }
        .gio-checkout-accordion .gio-checkout-summary { gap: .125rem; grid-column: 2; margin: 1rem 0; padding: 0; border-top: 0; }
        .gio-checkout-accordion .gio-checkout-row { font-size: .75rem; }
        .gio-checkout-accordion .gio-checkout-total { margin: 0; padding: 0; border-top: 0; font-size: 1.375rem; }
        .gio-checkout-accordion .gio-checkout-submit { grid-column: 4; width: auto; margin: 1rem 0; padding: .8125rem 1.75rem; }
        .gio-checkout-accordion .gio-checkout-note { grid-column: 2 / 5; margin: 0 0 1rem; text-align: left; }
        .gio-checkout-accordion [hidden] { display: none; }
        @media (max-width: 37.5rem) { .gio-checkout-accordion .gio-checkout-card { padding: 0; } .gio-checkout-accordion .gio-checkout-section { padding: 0 1rem 1rem; } .gio-checkout-accordion .gio-checkout-heading { margin: 0 -1rem; } .gio-checkout-accordion .gio-checkout-panel-summary { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; } .gio-checkout-accordion .gio-checkout-summary { grid-column: 2 / 5; } .gio-checkout-accordion .gio-checkout-submit { grid-column: 2 / 5; width: 100%; margin: 0 0 1rem; } }

CSS,
            self::Split => <<<'CSS'
        .gio-checkout-split .gio-checkout-card { max-width: 57.5rem; padding: 2rem; }
        .gio-checkout-split .gio-checkout-form { display: grid; grid-template-columns: minmax(0, 1fr) 1.75rem 1.25rem minmax(0, 17.5rem) 1.25rem; grid-template-rows: 1.25rem auto auto auto auto auto 1.25rem 1fr; align-items: start; }
        .gio-checkout-split .gio-checkout-form::before { content: ""; grid-column: 3 / 6; grid-row: 1 / 8; align-self: stretch; border: 1px solid var(--gio-border); background: color-mix(in srgb, var(--gio-text) 3%, var(--gio-surface)); }
        .gio-checkout-split .gio-checkout-questions { grid-column: 1; grid-row: 1 / -1; margin: 0; }
        .gio-checkout-split .gio-checkout-section { grid-column: 4; grid-row: 2; }
        .gio-checkout-split .gio-checkout-summary { grid-column: 4; grid-row: 3; margin: .875rem 0 0; padding: .75rem 0 0; gap: .375rem; }
        .gio-checkout-split .gio-checkout-row { font-size: .8125rem; }
        .gio-checkout-split .gio-checkout-total { margin: 0; padding: .375rem 0 0; border-top: 0; font-size: 1.0625rem; }
        .gio-checkout-split .gio-checkout-submit { grid-column: 4; grid-row: 4; margin: .875rem 0 0; }
        .gio-checkout-split .gio-checkout-note { grid-column: 4; grid-row: 5; }
        .gio-checkout-split .gio-checkout-footnote { grid-column: 4; grid-row: 6; font-size: .6875rem; }
        .gio-checkout-split .gio-checkout-heading { margin-bottom: .75rem; font-size: .9375rem; }
        .gio-checkout-split .gio-checkout-fields { gap: .75rem; }
        .gio-checkout-split .gio-checkout-delivery { margin: 0; }
        .gio-checkout-split .gio-checkout-delivery-choice { flex: 1 1 11.25rem; }
        .gio-checkout-split .gio-checkout-payment { grid-template-columns: repeat(auto-fit, minmax(9.375rem, 1fr)); gap: .625rem; }
        .gio-checkout-split .gio-checkout-payment-choice { padding: .75rem; font-size: .8125rem; }
        .gio-checkout-split .gio-checkout-product { padding: .625rem; gap: .625rem; }
        .gio-checkout-split .gio-checkout-product-head { gap: .625rem; }
        .gio-checkout-split .gio-checkout-image { width: 2.5rem; height: 2.5rem; }
        .gio-checkout-split .gio-checkout-name { font-size: .8125rem; }
        .gio-checkout-split .gio-checkout-quantity-step { width: 1.375rem; height: 1.375rem; font-size: .8125rem; }
        .gio-checkout-split .gio-checkout-line-total { width: auto; font-size: .8125rem; }
        @media (max-width: 37.5rem) { .gio-checkout-split .gio-checkout-card { padding: 1.25rem 1rem; } .gio-checkout-split .gio-checkout-form { grid-template-columns: 1.25rem minmax(0, 1fr) 1.25rem; grid-template-rows: auto 1.75rem 1.25rem auto auto auto auto auto 1.25rem; } .gio-checkout-split .gio-checkout-form::before { grid-column: 1 / -1; grid-row: 3 / 10; } .gio-checkout-split .gio-checkout-questions { grid-column: 1 / -1; grid-row: 1; } .gio-checkout-split .gio-checkout-section { grid-column: 2; grid-row: 4; } .gio-checkout-split .gio-checkout-summary { grid-column: 2; grid-row: 5; } .gio-checkout-split .gio-checkout-submit { grid-column: 2; grid-row: 6; } .gio-checkout-split .gio-checkout-note { grid-column: 2; grid-row: 7; } .gio-checkout-split .gio-checkout-footnote { grid-column: 2; grid-row: 8; } }

CSS,
            self::SplitReversed => <<<'CSS'
        .gio-checkout-split-reversed .gio-checkout-card { max-width: 57.5rem; padding: 0; }
        .gio-checkout-split-reversed .gio-checkout-form { display: grid; grid-template-columns: 2rem minmax(0, 1fr) 2rem 2rem minmax(0, 1fr) 2rem; grid-template-rows: 2rem auto 1fr auto auto auto 2rem; align-items: start; }
        .gio-checkout-split-reversed .gio-checkout-form::before { content: ""; grid-column: 1 / 4; grid-row: 1 / -1; align-self: stretch; border-right: 1px solid var(--gio-border); background: color-mix(in srgb, var(--gio-text) 3%, var(--gio-surface)); }
        .gio-checkout-split-reversed .gio-checkout-section { grid-column: 2; grid-row: 2; }
        .gio-checkout-split-reversed .gio-checkout-summary { grid-column: 2; grid-row: 3 / 7; align-self: end; margin: 0; padding: .75rem 0 0; border-top: 0; }
        .gio-checkout-split-reversed .gio-checkout-row { font-size: .8125rem; }
        .gio-checkout-split-reversed .gio-checkout-questions { grid-column: 5; grid-row: 2 / 4; margin: 0; }
        .gio-checkout-split-reversed .gio-checkout-submit { grid-column: 5; grid-row: 4; margin: .875rem 0 0; padding: 1rem 1.25rem; }
        .gio-checkout-split-reversed .gio-checkout-note { grid-column: 5; grid-row: 5; }
        .gio-checkout-split-reversed .gio-checkout-footnote { grid-column: 5; grid-row: 6; }
        .gio-checkout-split-reversed .gio-checkout-heading { margin: 0 0 .875rem; font-size: 1.125rem; }
        .gio-checkout-split-reversed .gio-checkout-input { padding: .75rem; }
        .gio-checkout-split-reversed .gio-checkout-quantity-input { padding: 0; }
        .gio-checkout-split-reversed .gio-checkout-products { gap: .75rem; }
        .gio-checkout-split-reversed .gio-checkout-product { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; gap: .625rem .875rem; padding: .875rem; }
        .gio-checkout-split-reversed .gio-checkout-product-head { grid-column: 1; grid-row: 1; gap: .875rem; align-items: flex-start; }
        .gio-checkout-split-reversed .gio-checkout-image { width: 4.5rem; height: 4.5rem; }
        .gio-checkout-split-reversed .gio-checkout-name { font-size: .9375rem; }
        .gio-checkout-split-reversed .gio-checkout-line-total { grid-column: 2; grid-row: 1; align-self: start; width: auto; padding: 0; font-size: .9375rem; font-weight: 700; text-align: right; }
        .gio-checkout-split-reversed .gio-checkout-quantity { grid-column: 1; grid-row: 2; }
        .gio-checkout-split-reversed .gio-checkout-pick { grid-column: 2; grid-row: 2; justify-self: end; width: 1.25rem; height: 1.25rem; }
        .gio-checkout-split-reversed .gio-checkout-quantity-step { width: 1.75rem; height: 1.75rem; font-size: .9375rem; }
        .gio-checkout-split-reversed .gio-checkout-delivery { display: flex; gap: 1px; margin: .875rem 0 0; border: 1px solid var(--gio-border); background: var(--gio-border); }
        .gio-checkout-split-reversed .gio-checkout-delivery-choice { position: relative; flex: 1 1 0; justify-content: center; gap: .375rem; padding: .6875rem .5rem; border: 0; font-size: .8125rem; font-weight: 600; transition: background .15s, color .15s; }
        .gio-checkout-split-reversed .gio-checkout-delivery-choice:has(:checked) { background: var(--gio-accent); color: var(--gio-surface); }
        .gio-checkout-split-reversed .gio-checkout-delivery-choice:has(:focus-visible) { outline: 2px solid var(--gio-accent); outline-offset: -2px; }
        .gio-checkout-split-reversed .gio-checkout-delivery-tick { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; }
        .gio-checkout-split-reversed .gio-checkout-delivery-label { flex: none; }
        .gio-checkout-split-reversed .gio-checkout-choice-amount { font-weight: 600; }
        .gio-checkout-split-reversed .gio-checkout-payment { gap: .375rem; }
        .gio-checkout-split-reversed .gio-checkout-payment-choice { padding: .375rem 0; border: 0; background: transparent; }
        @media (max-width: 37.5rem) { .gio-checkout-split-reversed .gio-checkout-card { padding: 0; } .gio-checkout-split-reversed .gio-checkout-form { grid-template-columns: 1rem minmax(0, 1fr) 1rem; grid-template-rows: 1.25rem auto auto 1.25rem 1.25rem auto auto auto auto 1.25rem; } .gio-checkout-split-reversed .gio-checkout-form::before { grid-column: 1 / -1; grid-row: 1 / 5; border-right: 0; border-bottom: 1px solid var(--gio-border); } .gio-checkout-split-reversed .gio-checkout-section { grid-column: 2; grid-row: 2; } .gio-checkout-split-reversed .gio-checkout-summary { grid-column: 2; grid-row: 3; } .gio-checkout-split-reversed .gio-checkout-questions { grid-column: 2; grid-row: 6; } .gio-checkout-split-reversed .gio-checkout-submit { grid-column: 2; grid-row: 7; } .gio-checkout-split-reversed .gio-checkout-note { grid-column: 2; grid-row: 8; } .gio-checkout-split-reversed .gio-checkout-footnote { grid-column: 2; grid-row: 9; } }

CSS,
            self::DarkPanel => <<<'CSS'
        .gio-checkout-dark-panel .gio-checkout-card { max-width: 57.5rem; padding: 0; }
        .gio-checkout-dark-panel .gio-checkout-form { display: grid; grid-template-columns: 2rem minmax(0, 17fr) 2rem 2rem minmax(0, 20fr) 2rem; grid-template-rows: 2rem auto 1fr auto auto 2rem; align-items: start; }
        .gio-checkout-dark-panel .gio-checkout-form::before { content: ""; grid-column: 1 / 4; grid-row: 1 / -1; align-self: stretch; background: #0f1d4f; }
        .gio-checkout-dark-panel .gio-checkout-section { grid-column: 2; grid-row: 2; }
        .gio-checkout-dark-panel .gio-checkout-eyebrow { color: #9fb0e8; }
        .gio-checkout-dark-panel .gio-checkout-products { gap: .875rem; }
        .gio-checkout-dark-panel .gio-checkout-product { gap: .75rem; border-color: #33468a; background: transparent; color: #fff; }
        .gio-checkout-dark-panel .gio-checkout-product:has(:checked) { border-color: #fff; background: rgba(255, 255, 255, .08); }
        .gio-checkout-dark-panel .gio-checkout-pick { accent-color: #fff; }
        .gio-checkout-dark-panel .gio-checkout-image { border-color: #33468a; background: #1b2b63; }
        .gio-checkout-dark-panel .gio-checkout-description { color: #9fb0e8; }
        .gio-checkout-dark-panel .gio-checkout-price { color: #9fb0e8; }
        .gio-checkout-dark-panel .gio-checkout-quantity-stepper { border-color: #33468a; background: transparent; }
        .gio-checkout-dark-panel .gio-checkout-quantity-step { width: 1.625rem; height: 1.625rem; color: #fff; }
        .gio-checkout-dark-panel .gio-checkout-summary { grid-column: 2; grid-row: 3 / 5; align-self: end; margin: 0; padding: .875rem 0 0; gap: .375rem; border-top-color: #33468a; }
        .gio-checkout-dark-panel .gio-checkout-row { font-size: .8125rem; color: #c5d0f2; }
        .gio-checkout-dark-panel .gio-checkout-total { margin: 0; padding: .375rem 0 0; border-top: 0; font-size: 1.375rem; color: #fff; }
        .gio-checkout-dark-panel .gio-checkout-questions { grid-column: 5; grid-row: 2 / 4; margin: 0; }
        .gio-checkout-dark-panel .gio-checkout-submit { grid-column: 5; grid-row: 4; align-self: end; margin: .875rem 0 0; padding: .9375rem 1.25rem; }
        .gio-checkout-dark-panel .gio-checkout-note { grid-column: 5; grid-row: 5; }
        .gio-checkout-dark-panel .gio-checkout-heading { margin: 0 0 .625rem; font-size: 1.125rem; }
        .gio-checkout-dark-panel .gio-checkout-fields { gap: .75rem; }
        .gio-checkout-dark-panel .gio-checkout-input { padding: .75rem; background: color-mix(in srgb, var(--gio-text) 3%, var(--gio-surface)); }
        .gio-checkout-dark-panel .gio-checkout-quantity-input { padding: 0; background: transparent; color: #fff; }
        .gio-checkout-dark-panel .gio-checkout-delivery-choice { flex: 1 1 10rem; }
        .gio-checkout-dark-panel .gio-checkout-payment { grid-template-columns: repeat(auto-fit, minmax(7.5rem, 1fr)); gap: .5rem; }
        .gio-checkout-dark-panel .gio-checkout-payment-choice { justify-content: center; gap: .5rem; padding: .6875rem .5rem; font-size: .8125rem; font-weight: 600; }
        .gio-checkout-dark-panel .gio-checkout-payment-label { flex: none; }
        @media (max-width: 37.5rem) { .gio-checkout-dark-panel .gio-checkout-card { padding: 0; } .gio-checkout-dark-panel .gio-checkout-form { grid-template-columns: 1rem minmax(0, 1fr) 1rem; grid-template-rows: 1.25rem auto auto 1.25rem 1.25rem auto auto auto 1.25rem; } .gio-checkout-dark-panel .gio-checkout-form::before { grid-column: 1 / -1; grid-row: 1 / 5; } .gio-checkout-dark-panel .gio-checkout-section { grid-column: 2; grid-row: 2; } .gio-checkout-dark-panel .gio-checkout-summary { grid-column: 2; grid-row: 3; } .gio-checkout-dark-panel .gio-checkout-questions { grid-column: 2; grid-row: 6; } .gio-checkout-dark-panel .gio-checkout-submit { grid-column: 2; grid-row: 7; } .gio-checkout-dark-panel .gio-checkout-note { grid-column: 2; grid-row: 8; } }

CSS,
            self::BentoGrid => <<<'CSS'
        .gio-checkout-bento-grid .gio-checkout-card { max-width: 57.5rem; padding: 0; border: 0; background: transparent; }
        .gio-checkout-bento-grid .gio-checkout-form { display: grid; grid-template-columns: 1.25rem minmax(0, 1fr) .875rem minmax(0, 1fr) 1.25rem; grid-template-rows: auto .875rem 1.125rem auto auto 1.125rem; align-items: start; }
        .gio-checkout-bento-grid .gio-checkout-form::after { content: ""; grid-column: 1 / -1; grid-row: 3 / 7; align-self: stretch; background: var(--gio-text); }
        .gio-checkout-bento-grid .gio-checkout-section { grid-column: 1 / 3; grid-row: 1; margin: 0; padding: 1.25rem; border: 1px solid var(--gio-border); background: var(--gio-surface); }
        .gio-checkout-bento-grid .gio-checkout-questions { display: flex; flex-direction: column; gap: .875rem; grid-column: 4 / 6; grid-row: 1; margin: 0; }
        .gio-checkout-bento-grid .gio-checkout-eyebrow { margin-bottom: .625rem; }
        .gio-checkout-bento-grid .gio-checkout-products { gap: .625rem; }
        .gio-checkout-bento-grid .gio-checkout-product { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; align-items: center; gap: .625rem; padding: .75rem; }
        .gio-checkout-bento-grid .gio-checkout-pick { grid-column: 1; grid-row: 1; }
        .gio-checkout-bento-grid .gio-checkout-product-head { grid-column: 2; grid-row: 1; gap: .625rem; }
        .gio-checkout-bento-grid .gio-checkout-image { width: 3.5rem; height: 3.5rem; }
        .gio-checkout-bento-grid .gio-checkout-line-total { grid-column: 3; grid-row: 1; width: auto; padding: 0; font-weight: 700; text-align: right; }
        .gio-checkout-bento-grid .gio-checkout-quantity { grid-column: 1 / -1; grid-row: 2; justify-content: space-between; }
        .gio-checkout-bento-grid .gio-checkout-quantity-label { position: static; width: auto; height: auto; margin: 0; overflow: visible; clip: auto; white-space: normal; font-size: .75rem; font-weight: 400; color: var(--gio-muted); }
        .gio-checkout-bento-grid .gio-checkout-quantity-step { width: 1.75rem; height: 1.75rem; font-size: .9375rem; }
        .gio-checkout-bento-grid .gio-checkout-fields { gap: .625rem; }
        .gio-checkout-bento-grid .gio-checkout-delivery { display: flex; gap: 1px; margin: .625rem 0 0; border: 1px solid var(--gio-border); background: var(--gio-border); }
        .gio-checkout-bento-grid .gio-checkout-delivery-choice { position: relative; flex: 1 1 0; justify-content: center; gap: .375rem; padding: .625rem .5rem; border: 0; font-size: .8125rem; font-weight: 600; transition: background .15s, color .15s; }
        .gio-checkout-bento-grid .gio-checkout-delivery-choice:has(:checked) { background: var(--gio-accent); color: var(--gio-surface); }
        .gio-checkout-bento-grid .gio-checkout-delivery-choice:has(:focus-visible) { outline: 2px solid var(--gio-accent); outline-offset: -2px; }
        .gio-checkout-bento-grid .gio-checkout-delivery-tick { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; }
        .gio-checkout-bento-grid .gio-checkout-delivery-label { flex: none; }
        .gio-checkout-bento-grid .gio-checkout-choice-amount { font-weight: 600; }
        .gio-checkout-bento-grid .gio-checkout-payment { grid-template-columns: 1fr 1fr; gap: .5rem; }
        .gio-checkout-bento-grid .gio-checkout-payment-choice { gap: .5rem; padding: .625rem; font-size: .8125rem; }
        .gio-checkout-bento-grid .gio-checkout-summary { display: flex; flex-wrap: wrap; align-items: baseline; gap: .25rem 1.5rem; grid-column: 2; grid-row: 4; align-self: center; margin: 0; padding: 0; border-top: 0; }
        .gio-checkout-bento-grid .gio-checkout-row { gap: .375rem; font-size: .8125rem; color: #b9bec9; }
        .gio-checkout-bento-grid .gio-checkout-total { margin: 0; padding: 0; border-top: 0; font-size: 1.125rem; color: var(--gio-surface); }
        .gio-checkout-bento-grid .gio-checkout-submit { grid-column: 4; grid-row: 4; justify-self: end; align-self: center; width: auto; margin: 0; padding: .8125rem 1.75rem; }
        .gio-checkout-bento-grid .gio-checkout-submit:hover { background: #4f79e6; }
        .gio-checkout-bento-grid .gio-checkout-note { grid-column: 2 / 5; grid-row: 5; margin: .625rem 0 0; text-align: left; color: #b9bec9; }
        @media (max-width: 37.5rem) { .gio-checkout-bento-grid .gio-checkout-form { grid-template-columns: 1rem minmax(0, 1fr) 1rem; grid-template-rows: auto .875rem auto .875rem 1.125rem auto auto auto 1.125rem; } .gio-checkout-bento-grid .gio-checkout-form::after { grid-row: 5 / 10; } .gio-checkout-bento-grid .gio-checkout-section { grid-column: 1 / -1; grid-row: 1; padding: 1rem; } .gio-checkout-bento-grid .gio-checkout-questions { grid-column: 1 / -1; grid-row: 3; } .gio-checkout-bento-grid .gio-checkout-summary { grid-column: 2; grid-row: 6; } .gio-checkout-bento-grid .gio-checkout-submit { grid-column: 2; grid-row: 7; justify-self: stretch; width: 100%; margin: .875rem 0 0; } .gio-checkout-bento-grid .gio-checkout-note { grid-column: 2; grid-row: 8; } }

CSS,
            self::CompactEmbed => <<<'CSS'
        .gio-checkout-compact-embed .gio-checkout-card { max-width: 25rem; padding: 1.25rem; }
        .gio-checkout-compact-embed .gio-checkout-form { display: flex; flex-direction: column; gap: .75rem; }
        .gio-checkout-compact-embed .gio-checkout-section { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: baseline; margin: 0; }
        .gio-checkout-compact-embed .gio-checkout-questions { display: flex; flex-direction: column; gap: .75rem; margin: 0; }
        .gio-checkout-compact-embed .gio-checkout-heading { grid-column: 1; margin: 0 0 .75rem; font-size: 1rem; }
        .gio-checkout-compact-embed .gio-checkout-count { grid-column: 2; margin: 0 0 .75rem; }
        .gio-checkout-compact-embed .gio-checkout-products { grid-column: 1 / -1; gap: 0; border: 1px solid var(--gio-border); }
        .gio-checkout-compact-embed .gio-checkout-error { grid-column: 1 / -1; }
        .gio-checkout-compact-embed .gio-checkout-product { gap: .625rem; padding: .625rem .75rem; border: 0; border-bottom: 1px solid var(--gio-border); }
        .gio-checkout-compact-embed .gio-checkout-pick { width: .9375rem; height: .9375rem; }
        .gio-checkout-compact-embed .gio-checkout-image { width: 1.75rem; height: 1.75rem; }
        .gio-checkout-compact-embed .gio-checkout-name { font-size: .8125rem; }
        .gio-checkout-compact-embed .gio-checkout-quantity-stepper { gap: .25rem; border: 0; background: transparent; }
        .gio-checkout-compact-embed .gio-checkout-quantity-step { width: 1.375rem; height: 1.375rem; border: 1px solid var(--gio-border); font-size: .8125rem; }
        .gio-checkout-compact-embed .gio-checkout-line-total { width: 3.25rem; padding: 0; font-size: .8125rem; }
        .gio-checkout-compact-embed .gio-checkout-fields { grid-column: 1 / -1; gap: .625rem; }
        .gio-checkout-compact-embed .gio-checkout-input { padding: .625rem .75rem; font-size: .8125rem; }
        .gio-checkout-compact-embed .gio-checkout-quantity-input { width: 1rem; padding: 0; font-size: .75rem; }
        .gio-checkout-compact-embed .gio-checkout-delivery { display: flex; gap: 1px; grid-column: 1 / -1; margin: .75rem 0 0; border: 1px solid var(--gio-border); background: var(--gio-border); }
        .gio-checkout-compact-embed .gio-checkout-delivery-choice { position: relative; flex: 1 1 0; justify-content: center; gap: .375rem; padding: .5625rem .375rem; border: 0; font-size: .75rem; font-weight: 600; transition: background .15s, color .15s; }
        .gio-checkout-compact-embed .gio-checkout-delivery-choice:has(:checked) { background: var(--gio-accent); color: var(--gio-surface); }
        .gio-checkout-compact-embed .gio-checkout-delivery-choice:has(:focus-visible) { outline: 2px solid var(--gio-accent); outline-offset: -2px; }
        .gio-checkout-compact-embed .gio-checkout-delivery-tick { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; }
        .gio-checkout-compact-embed .gio-checkout-delivery-label { flex: none; }
        .gio-checkout-compact-embed .gio-checkout-choice-amount { font-weight: 600; }
        .gio-checkout-compact-embed .gio-checkout-payment { display: flex; gap: 1px; grid-column: 1 / -1; border: 1px solid var(--gio-border); background: var(--gio-border); }
        .gio-checkout-compact-embed .gio-checkout-payment-choice { position: relative; flex: 1 1 0; justify-content: center; gap: .375rem; padding: .5625rem .25rem; border: 0; font-size: .75rem; font-weight: 600; transition: background .15s, color .15s; }
        .gio-checkout-compact-embed .gio-checkout-payment-choice:has(:checked) { background: var(--gio-accent); color: var(--gio-surface); }
        .gio-checkout-compact-embed .gio-checkout-payment-choice:has(:focus-visible) { outline: 2px solid var(--gio-accent); outline-offset: -2px; }
        .gio-checkout-compact-embed .gio-checkout-payment-tick { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; }
        .gio-checkout-compact-embed .gio-checkout-payment-label { flex: none; }
        .gio-checkout-compact-embed .gio-checkout-logo { height: 1rem; }
        .gio-checkout-compact-embed .gio-checkout-summary { display: flex; flex-wrap: wrap; align-items: baseline; gap: .25rem 1rem; margin: 0; padding: .25rem 0 0; border-top: 0; }
        .gio-checkout-compact-embed .gio-checkout-row { gap: .375rem; font-size: .8125rem; }
        .gio-checkout-compact-embed .gio-checkout-total { margin: 0 0 0 auto; padding: 0; border-top: 0; font-size: 1rem; }
        .gio-checkout-compact-embed .gio-checkout-submit { margin: 0; padding: .8125rem; font-size: .875rem; }
        .gio-checkout-compact-embed .gio-checkout-note { margin: 0; }
        @media (max-width: 37.5rem) { .gio-checkout-compact-embed .gio-checkout-card { padding: 1.25rem 1rem; } .gio-checkout-compact-embed .gio-checkout-line-total { flex: none; width: 3.25rem; padding: 0; font-size: .8125rem; text-align: right; } }

CSS,
            self::Chips => <<<'CSS'
        .gio-checkout-chips .gio-checkout-card { max-width: 40rem; padding: 2.25rem; }
        .gio-checkout-chips .gio-checkout-form { display: grid; grid-template-columns: minmax(0, 1fr) minmax(13.75rem, 1fr); grid-template-rows: auto auto auto auto; gap: 1.5rem; align-items: start; }
        .gio-checkout-chips .gio-checkout-form::before { content: ""; grid-column: 1 / -1; grid-row: 3; align-self: start; border-top: 1px solid var(--gio-border); }
        .gio-checkout-chips .gio-checkout-section { grid-column: 1 / -1; grid-row: 1; margin: 0; }
        .gio-checkout-chips .gio-checkout-questions { display: flex; flex-direction: column; gap: 1.5rem; grid-column: 1 / -1; grid-row: 2; margin: 0; padding: 1.5rem 0 0; border-top: 1px solid var(--gio-border); }
        .gio-checkout-chips .gio-checkout-eyebrow { margin-bottom: .625rem; }
        .gio-checkout-chips .gio-checkout-products { display: flex; flex-wrap: wrap; gap: .5rem; }
        .gio-checkout-chips .gio-checkout-product { gap: .5rem; padding: .5625rem .875rem; font-size: .8125rem; font-weight: 600; transition: background .15s, color .15s, border-color .15s; }
        .gio-checkout-chips .gio-checkout-product:has(:checked) { border-color: var(--gio-accent); background: var(--gio-accent); color: var(--gio-surface); }
        .gio-checkout-chips .gio-checkout-pick { width: .875rem; height: .875rem; }
        .gio-checkout-chips .gio-checkout-product-head { flex: none; gap: .5rem; }
        .gio-checkout-chips .gio-checkout-image { width: 1.5rem; height: 1.5rem; }
        .gio-checkout-chips .gio-checkout-product-words { display: flex; flex-wrap: wrap; align-items: baseline; gap: .125rem .5rem; }
        .gio-checkout-chips .gio-checkout-name { font-size: .8125rem; }
        .gio-checkout-chips .gio-checkout-description { margin: 0; font-size: .75rem; color: inherit; opacity: .7; }
        .gio-checkout-chips .gio-checkout-price { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; }
        .gio-checkout-chips .gio-checkout-line-total { width: auto; padding: 0; font-size: .8125rem; font-weight: 500; }
        .gio-checkout-chips .gio-checkout-quantity { gap: .375rem; }
        .gio-checkout-chips .gio-checkout-quantity-stepper { border-color: color-mix(in srgb, var(--gio-surface) 40%, var(--gio-accent)); background: transparent; }
        .gio-checkout-chips .gio-checkout-quantity-step { width: 1.5rem; height: 1.5rem; font-size: .875rem; }
        .gio-checkout-chips .gio-checkout-quantity-input { width: 1.375rem; background: transparent; color: inherit; }
        .gio-checkout-chips .gio-checkout-empty { margin: 1rem 0 0; padding: 1rem 0 0; border-top: 1px solid var(--gio-border); }
        .gio-checkout-chips .gio-checkout-fields { gap: .875rem 1.25rem; }
        .gio-checkout-chips .gio-checkout-delivery { display: flex; gap: 1px; margin: 0; border: 1px solid var(--gio-border); background: var(--gio-border); }
        .gio-checkout-chips .gio-checkout-delivery-choice { position: relative; flex: 1 1 0; justify-content: center; gap: .375rem; padding: .625rem .5rem; border: 0; font-size: .8125rem; font-weight: 600; transition: background .15s, color .15s; }
        .gio-checkout-chips .gio-checkout-delivery-choice:has(:checked) { background: var(--gio-accent); color: var(--gio-surface); }
        .gio-checkout-chips .gio-checkout-delivery-choice:has(:focus-visible) { outline: 2px solid var(--gio-accent); outline-offset: -2px; }
        .gio-checkout-chips .gio-checkout-delivery-tick { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; }
        .gio-checkout-chips .gio-checkout-delivery-label { flex: none; }
        .gio-checkout-chips .gio-checkout-choice-amount { font-weight: 600; }
        .gio-checkout-chips .gio-checkout-payment { gap: .375rem; }
        .gio-checkout-chips .gio-checkout-payment-choice { padding: .375rem 0; border: 0; background: transparent; }
        .gio-checkout-chips .gio-checkout-summary { grid-column: 1; grid-row: 3; gap: .125rem; margin: 0; padding: 1.25rem 0 0; border-top: 0; }
        .gio-checkout-chips .gio-checkout-row { font-size: .8125rem; }
        .gio-checkout-chips .gio-checkout-total { margin: 0; padding: 0; border-top: 0; font-size: 1.5rem; }
        .gio-checkout-chips .gio-checkout-submit { grid-column: 2; grid-row: 3; align-self: end; margin: 1.25rem 0 0; padding: .875rem 2rem; }
        .gio-checkout-chips .gio-checkout-note { grid-column: 1 / -1; grid-row: 4; margin: 0; }
        @media (max-width: 37.5rem) { .gio-checkout-chips .gio-checkout-card { padding: 1.25rem 1rem; } .gio-checkout-chips .gio-checkout-form { grid-template-columns: minmax(0, 1fr); grid-template-rows: auto auto auto auto auto; gap: 1.25rem; } .gio-checkout-chips .gio-checkout-form::before { grid-column: 1; } .gio-checkout-chips .gio-checkout-summary { grid-column: 1; grid-row: 3; } .gio-checkout-chips .gio-checkout-submit { grid-column: 1; grid-row: 4; margin: 0; } .gio-checkout-chips .gio-checkout-note { grid-column: 1; grid-row: 5; } }

CSS,
            self::Invoice => <<<'CSS'
        .gio-checkout-invoice .gio-checkout-card { max-width: 38.75rem; padding: 2.5rem 2rem; }
        .gio-checkout-invoice .gio-checkout-form { display: grid; grid-template-columns: minmax(0, 1fr); grid-template-rows: auto .75rem auto 1.75rem auto 1.75rem auto auto; }
        .gio-checkout-invoice .gio-checkout-section { display: grid; grid-template-columns: 1.5rem minmax(0, 1fr) 4.75rem 4.5rem; gap: 0 .75rem; align-items: center; grid-column: 1; grid-row: 1; margin: 0; }
        .gio-checkout-invoice .gio-checkout-heading { grid-column: 1 / -1; margin: 0 0 1.75rem; padding: 0 0 .625rem; border-bottom: 2px solid var(--gio-text); font-size: 1.25rem; }
        .gio-checkout-invoice .gio-checkout-column-heading { padding: 0 0 .5rem; }
        .gio-checkout-invoice .gio-checkout-column-heading:first-of-type { grid-column: 2; }
        .gio-checkout-invoice .gio-checkout-column-heading:last-of-type { text-align: right; }
        .gio-checkout-invoice .gio-checkout-products { grid-column: 1 / -1; gap: 0; border-top: 1px solid var(--gio-border); }
        .gio-checkout-invoice .gio-checkout-product { display: grid; grid-template-columns: 1.5rem minmax(0, 1fr) 4.75rem 4.5rem; gap: 0 .75rem; align-items: center; padding: .75rem 0; border: 0; border-bottom: 1px solid color-mix(in srgb, var(--gio-border) 55%, var(--gio-surface)); background: transparent; }
        .gio-checkout-invoice .gio-checkout-pick { grid-column: 1; }
        .gio-checkout-invoice .gio-checkout-product-head { grid-column: 2; gap: .625rem; }
        .gio-checkout-invoice .gio-checkout-image { width: 2rem; height: 2rem; }
        .gio-checkout-invoice .gio-checkout-product-words { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0 .375rem; }
        .gio-checkout-invoice .gio-checkout-name { flex: 1 0 100%; }
        .gio-checkout-invoice .gio-checkout-description { margin: 0; }
        .gio-checkout-invoice .gio-checkout-price { margin: 0; }
        .gio-checkout-invoice .gio-checkout-quantity { grid-column: 3; gap: .25rem; }
        .gio-checkout-invoice .gio-checkout-quantity-stepper { gap: .125rem; border: 0; background: transparent; }
        .gio-checkout-invoice .gio-checkout-quantity-step { width: 1.5rem; height: 1.5rem; border: 1px solid var(--gio-border); font-size: .8125rem; }
        .gio-checkout-invoice .gio-checkout-line-total { grid-column: 4; width: auto; padding: 0; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .875rem; font-weight: 600; text-align: right; }
        .gio-checkout-invoice .gio-checkout-summary { display: flex; flex-direction: column; align-items: flex-end; grid-column: 1; grid-row: 3; gap: .25rem; margin: 0; padding: 0; border-top: 0; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        .gio-checkout-invoice .gio-checkout-row { min-width: 11rem; gap: 1.5rem; font-size: .8125rem; }
        .gio-checkout-invoice .gio-checkout-total { margin: .25rem 0 0; padding: .375rem 0 0; border-top: 1px solid var(--gio-text); font-size: 1rem; }
        .gio-checkout-invoice .gio-checkout-questions { display: flex; flex-direction: column; gap: 1.75rem; grid-column: 1; grid-row: 5; margin: 0; }
        .gio-checkout-invoice .gio-checkout-fields { grid-column: 1 / -1; gap: 1.125rem 1.5rem; }
        .gio-checkout-invoice .gio-checkout-label { margin: 0 0 .25rem; font-size: .6875rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--gio-muted); }
        .gio-checkout-invoice .gio-checkout-input { padding: .5rem 0; border: 0; border-bottom: 1px solid color-mix(in srgb, var(--gio-muted) 45%, var(--gio-border)); border-radius: 0; background: transparent; font-size: .9375rem; }
        .gio-checkout-invoice .gio-checkout-input:focus { border-bottom-color: var(--gio-accent); box-shadow: none; }
        .gio-checkout-invoice .gio-checkout-quantity-input { width: 1.375rem; padding: 0; border: 0; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .8125rem; }
        .gio-checkout-invoice .gio-checkout-delivery { flex-direction: column; grid-column: 1 / -1; gap: .5rem; margin: 1.5rem 0 0; }
        .gio-checkout-invoice .gio-checkout-delivery-choice { flex: none; padding: 0; border: 0; background: transparent; }
        .gio-checkout-invoice .gio-checkout-payment { grid-column: 1 / -1; gap: .5rem; }
        .gio-checkout-invoice .gio-checkout-payment-choice { padding: 0; border: 0; background: transparent; }
        .gio-checkout-invoice .gio-checkout-choice-amount { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .8125rem; font-weight: 400; }
        .gio-checkout-invoice .gio-checkout-error { grid-column: 1 / -1; }
        .gio-checkout-invoice .gio-checkout-submit { justify-content: space-between; grid-column: 1; grid-row: 7; margin: 0; padding: 1rem 1.375rem; background: var(--gio-text); color: var(--gio-surface); }
        .gio-checkout-invoice .gio-checkout-submit:hover { background: var(--gio-accent); }
        .gio-checkout-invoice .gio-checkout-note { grid-column: 1; grid-row: 8; }
        @media (max-width: 37.5rem) { .gio-checkout-invoice .gio-checkout-card { padding: 1.25rem 1rem; } .gio-checkout-invoice .gio-checkout-section { grid-template-columns: 1.5rem minmax(0, 1fr); } .gio-checkout-invoice .gio-checkout-column-heading { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; } .gio-checkout-invoice .gio-checkout-product { grid-template-columns: 1.5rem minmax(0, 1fr); gap: .375rem .75rem; } .gio-checkout-invoice .gio-checkout-quantity { grid-column: 2; } .gio-checkout-invoice .gio-checkout-line-total { grid-column: 2; font-size: .8125rem; text-align: left; } }

CSS,
        };
    }

    /**
     * What the layout looks like, in one line, for the card a Merchant picks
     * it from.
     */
    public function description(): string
    {
        return match ($this) {
            self::Stacked => 'One column in a white card: numbered sections for the products, the details and the payment, then the totals and a button carrying the total.',
            self::Split => 'The form on the left and a grey summary panel on the right that stays on screen as the customer works down the questions.',
            self::SplitReversed => 'Large product cards with a running subtotal on the left, a compact form with the total inside the button on the right.',
            self::Stepper => 'Three steps — products, details, payment — one panel at a time behind Next and Back.',
            self::Accordion => 'Three sections that open one at a time, each closed header showing what was chosen in it, with a total bar at the foot.',
            self::BentoGrid => 'Four bordered cells with numbered eyebrows and a full-width dark bar carrying the total and the button.',
            self::CompactEmbed => 'A narrow card with a title and an item count, for a page that has already done the selling.',
            self::Chips => 'Products as pills to tap on and off, a list of what is picked, and a two-column delivery and payment block.',
            self::Invoice => 'A bill to agree to: a titled table of items, underlined inputs, monospace totals and a black Place order button.',
            self::DarkPanel => 'A navy summary panel beside a white form, for a page that wants the checkout to read as its own thing.',
        };
    }

    /**
     * Where the card's thumbnail is, under `public/`.
     *
     * A static picture shipped with the app, one per layout, so a Merchant
     * sees what they are choosing before they choose it. The picker's slot
     * still takes a null for an option with no picture — that is the
     * component's contract, not this enum's — but every arrangement here is
     * drawn now, so none of these is null.
     */
    public function thumbnail(): string
    {
        return match ($this) {
            self::Stacked => 'images/checkout-layouts/stacked.svg',
            self::Split => 'images/checkout-layouts/split.svg',
            self::SplitReversed => 'images/checkout-layouts/split-reversed.svg',
            self::DarkPanel => 'images/checkout-layouts/dark-panel.svg',
            self::BentoGrid => 'images/checkout-layouts/bento-grid.svg',
            self::CompactEmbed => 'images/checkout-layouts/compact-embed.svg',
            self::Chips => 'images/checkout-layouts/chips.svg',
            self::Invoice => 'images/checkout-layouts/invoice.svg',
            self::Stepper => 'images/checkout-layouts/stepper.svg',
            self::Accordion => 'images/checkout-layouts/accordion.svg',
        };
    }

    /**
     * What the Checkout tab calls this arrangement.
     */
    public function label(): string
    {
        return match ($this) {
            self::Stacked => 'Stacked',
            self::Split => 'Split',
            self::SplitReversed => 'Split reversed',
            self::Stepper => 'Stepper',
            self::Accordion => 'Accordion',
            self::BentoGrid => 'Bento grid',
            self::CompactEmbed => 'Compact embed',
            self::Chips => 'Chips',
            self::Invoice => 'Invoice',
            self::DarkPanel => 'Dark panel',
        };
    }

    /**
     * The ten, in the design's order, as the card picker lists them.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return self::byValue(fn (self $layout): string => $layout->label());
    }

    /**
     * The layouts that own words of their own, in the design's order — the
     * ones the Words tab has a section for.
     *
     * @return list<self>
     */
    public static function owningWords(): array
    {
        return array_values(array_filter(self::cases(), fn (self $layout): bool => $layout->wordSlots() !== []));
    }

    /**
     * @return array<string, string>
     */
    public static function descriptions(): array
    {
        return self::byValue(fn (self $layout): string => $layout->description());
    }

    /**
     * @return array<string, string>
     */
    public static function thumbnails(): array
    {
        return self::byValue(fn (self $layout): string => $layout->thumbnail());
    }

    /**
     * @template T
     *
     * @param  callable(self): T  $of
     * @return array<string, T>
     */
    private static function byValue(callable $of): array
    {
        $byValue = [];

        foreach (self::cases() as $layout) {
            $byValue[$layout->value] = $of($layout);
        }

        return $byValue;
    }
}
