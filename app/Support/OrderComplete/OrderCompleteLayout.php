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
 *
 * Every block is a set of descendants of its own modifier class, so all of it
 * is inert on a Page arranged any other way, and none of it reorders the
 * bytes: the head, the summary, the Deliver-to block, the note and the
 * buttons are written in that order in every arrangement, and a Customer
 * listening to the page rather than looking at it reads them so.
 */
enum OrderCompleteLayout: string
{
    case CenteredClassic = 'centered_classic';
    case Split = 'split';
    case Editorial = 'editorial';
    case AccentBand = 'accent_band';

    /**
     * What the split arrangement adds to the base sheet.
     *
     * At every width the card stops centring: the text ranges left, the badge
     * goes — the status line is the tick here — the headline is set large,
     * and the order number and the status line read as one two-line eyebrow
     * above it, the number moved first with `order` and losing the box the
     * base draws round it. Below the breakpoint the columns are shown at, the
     * card is what the base draws, ranged left.
     *
     * The two columns are something only a wide screen sees. The card becomes
     * a grid with a fixed panel on the right, and the panel's tint — the text
     * colour mixed faintly into the card's, so a dark card gets a dark panel
     * its text can still be read on — is painted on the card itself rather than on the summary and the Deliver-to block,
     * because those are two items with a row between them and a stranger's
     * page has only one of them: a gradient stopping where the second column
     * starts is the one way the panel is whole whatever is in it. Row gaps are
     * given up for the same reason and the left column spaces itself.
     *
     * The head spans the first row, the note the second and the buttons the
     * third; the summary spans the first two rows and the Deliver-to block
     * takes the third, so the buttons and the address share a row at the
     * foot. The middle row is flexible, which is where a summary longer than
     * the words beside it puts its extra height: below the note and above
     * the buttons, on the side of the card the eye has finished with.
     */
    private const SPLIT_RULES = <<<'CSS'
        .gio-order-complete-split .gio-order-complete-card { max-width: 57.5rem; align-items: stretch; text-align: left; }
        .gio-order-complete-split .gio-order-complete-head { align-items: flex-start; gap: .75rem; }
        .gio-order-complete-split .gio-order-complete-badge { display: none; }
        .gio-order-complete-split .gio-order-complete-status { color: var(--gio-accent); }
        .gio-order-complete-split .gio-order-complete-headline { font-size: 2.5em; line-height: 1.1; }
        .gio-order-complete-split .gio-order-complete-number { order: -1; margin: 0 0 -.375rem; padding: 0; border: 0; font-size: .75em; letter-spacing: .08em; text-transform: uppercase; opacity: .72; }
        .gio-order-complete-split .gio-order-complete-actions { flex-direction: row; flex-wrap: wrap; }
        .gio-order-complete-split .gio-order-complete-button { flex: 1 1 10rem; }
        @media (min-width: 56rem) {
        .gio-order-complete-split .gio-order-complete-card { display: grid; grid-template-columns: minmax(0, 1fr) 22rem; grid-template-rows: auto 1fr auto; gap: 0; padding: 0; background: linear-gradient(90deg, var(--gio-background) calc(100% - 22rem), color-mix(in srgb, var(--gio-text) 6%, var(--gio-background)) calc(100% - 22rem)); }
        .gio-order-complete-split .gio-order-complete-head { grid-column: 1; grid-row: 1; padding: 3rem 2.5rem 0; }
        .gio-order-complete-split .gio-order-complete-note { grid-column: 1; grid-row: 2; align-self: start; padding: 1.25rem 2.5rem 0; }
        .gio-order-complete-split .gio-order-complete-actions { grid-column: 1; grid-row: 3; align-self: start; width: auto; padding: 1.5rem 2.5rem 3rem; }
        .gio-order-complete-split .gio-order-complete-summary { grid-column: 2; grid-row: 1 / 3; width: auto; padding: 2.5rem 2rem 1.25rem; border-top: 0; border-left: var(--gio-border-width) solid var(--gio-border); }
        .gio-order-complete-split .gio-order-complete-deliver-to { grid-column: 2; grid-row: 3; width: auto; padding: 0 2rem 2.5rem; border-left: var(--gio-border-width) solid var(--gio-border); }
        }
        CSS;

    /**
     * What the editorial arrangement adds to the base sheet.
     *
     * A wide card whose content ranges left and stops at a measure, with no
     * badge and no box round the order number: the status line is the tick
     * and the number is a line of its own beneath the message. The summary
     * and the Deliver-to block are read as one definition list, a label
     * column and a value column ruled off by hairlines — the value ranged
     * left beside its label rather than pushed to the far edge, the total in
     * larger type. The Deliver-to block joins the list by putting its eyebrow
     * in the label column and its lines in the other, which is why the
     * eyebrow spans as many rows as the block can hold: the rows nothing is
     * written into are empty and take no height. On a phone the label column
     * would squeeze a product name to a word a line, so the rows go back to
     * the base's label-and-amount and the Deliver-to block to one column,
     * hairlines kept. The buttons sit side by side, the second as a quiet
     * link.
     */
    private const EDITORIAL_RULES = <<<'CSS'
        .gio-order-complete-editorial .gio-order-complete-card { max-width: 57.5rem; padding: 3.5rem 3rem; align-items: flex-start; gap: 1.75rem; text-align: left; }
        .gio-order-complete-editorial .gio-order-complete-head, .gio-order-complete-editorial .gio-order-complete-summary, .gio-order-complete-editorial .gio-order-complete-deliver-to, .gio-order-complete-editorial .gio-order-complete-note, .gio-order-complete-editorial .gio-order-complete-actions { max-width: 37.5rem; }
        .gio-order-complete-editorial .gio-order-complete-head { align-items: flex-start; gap: .625rem; }
        .gio-order-complete-editorial .gio-order-complete-badge { display: none; }
        .gio-order-complete-editorial .gio-order-complete-status { color: var(--gio-accent); }
        .gio-order-complete-editorial .gio-order-complete-status::before { content: "✓ "; }
        .gio-order-complete-editorial .gio-order-complete-headline { font-size: 2.125em; line-height: 1.15; }
        .gio-order-complete-editorial .gio-order-complete-number { padding: 0; border: 0; font-family: inherit; font-size: .9375em; font-weight: 400; opacity: .72; }
        .gio-order-complete-editorial .gio-order-complete-summary { gap: 0; padding: 0; }
        .gio-order-complete-editorial .gio-order-complete-row { display: grid; grid-template-columns: 8.75rem minmax(0, 1fr); justify-content: start; padding: .75rem 0; border-bottom: var(--gio-border-width) solid var(--gio-border); }
        .gio-order-complete-editorial .gio-order-complete-row-amount { text-align: left; white-space: normal; }
        .gio-order-complete-editorial .gio-order-complete-total { padding: .75rem 0; border-top: 0; font-size: 1.125em; }
        .gio-order-complete-editorial .gio-order-complete-deliver-to { display: grid; grid-template-columns: 8.75rem minmax(0, 1fr); margin-top: -1.75rem; padding: .75rem 0; border-bottom: var(--gio-border-width) solid var(--gio-border); }
        .gio-order-complete-editorial .gio-order-complete-eyebrow { grid-column: 1; grid-row: 1 / span 5; margin: 0; font-size: 1em; font-weight: 400; letter-spacing: 0; text-transform: none; }
        .gio-order-complete-editorial .gio-order-complete-deliver-to-line, .gio-order-complete-editorial .gio-order-complete-deliver-to-note { grid-column: 2; }
        .gio-order-complete-editorial .gio-order-complete-actions { flex-direction: row; flex-wrap: wrap; align-items: center; }
        .gio-order-complete-editorial .gio-order-complete-button-secondary { border: 0; padding: .875rem .5rem; text-decoration: underline; }
        @media (max-width: 30rem) {
        .gio-order-complete-editorial .gio-order-complete-card { padding: 2rem 1.25rem; }
        .gio-order-complete-editorial .gio-order-complete-row { display: flex; justify-content: space-between; }
        .gio-order-complete-editorial .gio-order-complete-row-amount { text-align: right; }
        .gio-order-complete-editorial .gio-order-complete-deliver-to { grid-template-columns: minmax(0, 1fr); }
        .gio-order-complete-editorial .gio-order-complete-eyebrow { grid-row: auto; margin: 0 0 .25rem; font-size: .6875rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; }
        .gio-order-complete-editorial .gio-order-complete-deliver-to-line, .gio-order-complete-editorial .gio-order-complete-deliver-to-note { grid-column: 1; }
        }
        CSS;

    /**
     * What the accent band arrangement adds to the base sheet.
     *
     * The head becomes a strip the full width of the card in the accent
     * colour — the badge reversed out of it, the headline and message beside
     * the badge, the order number pushed to the far end in a translucent box
     * — and the card gives up its own padding so the strip can meet its
     * edges, each of the parts beneath carrying the inset instead. The
     * summary is drawn as a ruled table: a box round it, every row padded and
     * ruled off, the total in larger type. On a wide screen the Deliver-to
     * block and the buttons share the last row, the address on the left and
     * the buttons on the right; the note sits between the table and that row.
     * Rows are placed by number rather than by their order in the bytes, so a
     * stranger's page, which has no Deliver-to block, keeps the buttons where
     * they were.
     */
    private const ACCENT_BAND_RULES = <<<'CSS'
        .gio-order-complete-accent-band .gio-order-complete-card { max-width: 57.5rem; padding: 0 0 2rem; overflow: hidden; align-items: stretch; gap: 1.25rem; text-align: left; }
        .gio-order-complete-accent-band .gio-order-complete-head { flex-direction: row; flex-wrap: wrap; align-items: center; gap: .875rem; padding: 1.75rem 2.5rem; background: var(--gio-accent); color: #fff; }
        .gio-order-complete-accent-band .gio-order-complete-badge { width: 2.5rem; height: 2.5rem; background: #fff; color: var(--gio-accent); }
        .gio-order-complete-accent-band .gio-order-complete-badge svg { width: 1.25rem; height: 1.25rem; }
        .gio-order-complete-accent-band .gio-order-complete-words { flex: 1 1 14rem; min-width: 0; }
        .gio-order-complete-accent-band .gio-order-complete-headline { margin: 0; font-size: 1.375em; }
        .gio-order-complete-accent-band .gio-order-complete-message { opacity: .85; }
        .gio-order-complete-accent-band .gio-order-complete-number { padding: .5rem .875rem; border: 0; background: rgba(255, 255, 255, .14); color: #fff; }
        .gio-order-complete-accent-band .gio-order-complete-summary, .gio-order-complete-accent-band .gio-order-complete-deliver-to, .gio-order-complete-accent-band .gio-order-complete-note, .gio-order-complete-accent-band .gio-order-complete-actions { box-sizing: border-box; width: auto; margin: 0 2.5rem; }
        .gio-order-complete-accent-band .gio-order-complete-summary { gap: 0; padding: 0; border: var(--gio-border-width) solid var(--gio-border); }
        .gio-order-complete-accent-band .gio-order-complete-row { padding: .75rem 1rem; border-bottom: var(--gio-border-width) solid var(--gio-border); }
        .gio-order-complete-accent-band .gio-order-complete-total { padding: .875rem 1rem; border: 0; font-size: 1.0625em; }
        .gio-order-complete-accent-band .gio-order-complete-actions { flex-direction: row; flex-wrap: wrap; }
        .gio-order-complete-accent-band .gio-order-complete-button { flex: 1 1 10rem; }
        @media (min-width: 40rem) {
        .gio-order-complete-accent-band .gio-order-complete-card { display: grid; grid-template-columns: minmax(0, 1fr) auto; column-gap: 0; align-items: start; }
        .gio-order-complete-accent-band .gio-order-complete-head { grid-column: 1 / -1; grid-row: 1; }
        .gio-order-complete-accent-band .gio-order-complete-summary { grid-column: 1 / -1; grid-row: 2; }
        .gio-order-complete-accent-band .gio-order-complete-note { grid-column: 1 / -1; grid-row: 3; }
        .gio-order-complete-accent-band .gio-order-complete-deliver-to { grid-column: 1; grid-row: 4; align-self: center; }
        .gio-order-complete-accent-band .gio-order-complete-actions { grid-column: 2; grid-row: 4; }
        .gio-order-complete-accent-band .gio-order-complete-button { flex: 0 0 auto; }
        }
        @media (max-width: 30rem) {
        .gio-order-complete-accent-band .gio-order-complete-head { padding: 1.5rem 1.25rem; }
        .gio-order-complete-accent-band .gio-order-complete-summary, .gio-order-complete-accent-band .gio-order-complete-deliver-to, .gio-order-complete-accent-band .gio-order-complete-note, .gio-order-complete-accent-band .gio-order-complete-actions { margin: 0 1.25rem; }
        }
        CSS;

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
            self::Split => 'gio-order-complete-split',
            self::Editorial => 'gio-order-complete-editorial',
            self::AccentBand => 'gio-order-complete-accent-band',
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
            self::Split => self::SPLIT_RULES,
            self::Editorial => self::EDITORIAL_RULES,
            self::AccentBand => self::ACCENT_BAND_RULES,
        };
    }

    /**
     * Whether this layout draws the status line above the headline.
     *
     * Split and editorial draw one in place of the badge: a short line in
     * the accent colour that says the Order is in before the headline does.
     * Centered classic and accent band say it with the tick.
     */
    public function drawsStatusLine(): bool
    {
        return match ($this) {
            self::Split, self::Editorial => true,
            self::CenteredClassic, self::AccentBand => false,
        };
    }

    /** Whether this layout draws the four steps of what happens next. */
    public function drawsSteps(): bool
    {
        return match ($this) {
            self::CenteredClassic, self::Split, self::Editorial, self::AccentBand => false,
        };
    }

    /** Whether this layout draws the three tiles under the headline. */
    public function drawsTiles(): bool
    {
        return match ($this) {
            self::CenteredClassic, self::Split, self::Editorial, self::AccentBand => false,
        };
    }

    /** Whether this layout draws a button that opens the browser's print dialog. */
    public function drawsPrintButton(): bool
    {
        return match ($this) {
            self::CenteredClassic, self::Split, self::Editorial, self::AccentBand => false,
        };
    }

    /**
     * What the layout does, said in terms of what the Merchant will see.
     */
    public function helperText(): string
    {
        return match ($this) {
            self::CenteredClassic => 'One centred card: a tick in your accent colour, the headline and message, the order number, the lines and total, where it is going, your note, and the buttons.',
            self::Split => 'Two columns on a wide screen: your status line, a big headline, the message, the note and the buttons on the left, and a grey panel on the right with the lines, the total and where it is going. On a phone it stacks, ranged left.',
            self::Editorial => 'Ranged left with hairline rules: your status line with a tick, the headline, the message and the order number, then the lines, delivery, payment and the total as a labelled list with where it is going beneath, and the buttons last.',
            self::AccentBand => 'A strip in your accent colour across the top holding the tick, the headline, the message and the order number, with the lines and total as a ruled table beneath, then where it is going beside the buttons.',
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
            self::Split->value => 'Split',
            self::Editorial->value => 'Editorial',
            self::AccentBand->value => 'Accent band',
        ];
    }
}
