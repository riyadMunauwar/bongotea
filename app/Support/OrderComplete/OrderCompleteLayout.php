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
 * bytes: the head, the slot a layout has of its own where it has one, the
 * summary, the Deliver-to block, the note and the buttons are written in
 * that order in every arrangement, and a Customer listening to the page
 * rather than looking at it reads them so.
 */
enum OrderCompleteLayout: string
{
    case CenteredClassic = 'centered_classic';
    case Split = 'split';
    case Timeline = 'timeline';
    case Receipt = 'receipt';
    case DarkHero = 'dark_hero';
    case Minimal = 'minimal';
    case CardsGrid = 'cards_grid';
    case Editorial = 'editorial';
    case AccentBand = 'accent_band';
    case Compact = 'compact';

    /**
     * What the receipt arrangement adds to the base sheet.
     *
     * A narrow card with square corners, the way a till prints one: the
     * badge goes, the headline and message stay in the page's own face, and
     * everything that is a figure — the order number, the summary, the
     * Deliver-to block — is set in monospace. The number becomes a ruled
     * line between two dashed rules, in capitals; the summary's rows are tight, the total
     * ruled off in the text colour rather than the border colour with its
     * label in capitals; the Deliver-to block opens with a dashed rule of
     * its own. The buttons sit in a row, the first taking the room and the
     * Print button — the slot this layout has of its own — beside it, a
     * real button in the secondary button's clothes. When the page is
     * printed the buttons and the grey ground go, leaving the receipt.
     */
    private const RECEIPT_RULES = <<<'CSS'
        .gio-order-complete-receipt .gio-order-complete-card { max-width: 30rem; padding: 2rem; border-radius: 0; gap: 1.25rem; }
        .gio-order-complete-receipt .gio-order-complete-head { gap: 0; }
        .gio-order-complete-receipt .gio-order-complete-badge { display: none; }
        .gio-order-complete-receipt .gio-order-complete-headline { font-size: 1.5em; }
        .gio-order-complete-receipt .gio-order-complete-number { box-sizing: border-box; width: 100%; margin: 1.25rem 0 0; padding: .75rem 0; border: 0; border-top: var(--gio-border-width) dashed var(--gio-border); border-bottom: var(--gio-border-width) dashed var(--gio-border); border-radius: 0; font-size: .8125em; text-transform: uppercase; }
        .gio-order-complete-receipt .gio-order-complete-summary, .gio-order-complete-receipt .gio-order-complete-deliver-to { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .8125em; }
        .gio-order-complete-receipt .gio-order-complete-summary { gap: .5rem; padding: 0; border-top: 0; }
        .gio-order-complete-receipt .gio-order-complete-total { margin-top: .25rem; padding-top: .625rem; border-top: var(--gio-border-width) solid var(--gio-text); font-size: 1.25em; text-transform: uppercase; }
        .gio-order-complete-receipt .gio-order-complete-deliver-to { padding-top: .75rem; border-top: var(--gio-border-width) dashed var(--gio-border); }
        .gio-order-complete-receipt .gio-order-complete-eyebrow { font-family: inherit; font-size: inherit; font-weight: 400; letter-spacing: 0; }
        .gio-order-complete-receipt .gio-order-complete-actions { flex-direction: row; flex-wrap: wrap; }
        .gio-order-complete-receipt .gio-order-complete-button { flex: 1 1 10rem; }
        .gio-order-complete-receipt .gio-order-complete-print { flex: 0 0 auto; font-family: inherit; line-height: inherit; cursor: pointer; }
        @media (max-width: 30rem) {
        .gio-order-complete-receipt .gio-order-complete-card { padding: 1.5rem 1.125rem; }
        }
        @media print {
        .gio-order-complete-receipt { padding: 0; background: #fff; }
        .gio-order-complete-receipt .gio-order-complete-card { max-width: none; border: 0; }
        .gio-order-complete-receipt .gio-order-complete-actions { display: none; }
        }
        CSS;

    /**
     * What the dark hero arrangement adds to the base sheet.
     *
     * The head becomes a band the full width of a wide card in a navy of
     * its own — the one colour on the page that is not the Page's, kept
     * because a confirmation band is meant to read as the same thing on
     * every Page arranged this way, and the accent still reaches the
     * buttons beneath it. The badge goes: the band is the tick. The status
     * line is the eyebrow, the headline is set large, the message is set in
     * a paler white at a measure, and the order number sits under them in a
     * translucent box. Below the band, in white, the summary and the
     * Deliver-to block are two columns on a wide screen, each with its
     * eyebrow — the summary's written by a rule — and the note and the
     * buttons under them. A stranger's page has no Deliver-to block, and
     * its summary is widened to both columns by a `:has()` rule; on a phone
     * the columns stack.
     */
    private const DARK_HERO_RULES = <<<'CSS'
        .gio-order-complete-dark-hero .gio-order-complete-card { max-width: 57.5rem; padding: 0 0 2rem; overflow: hidden; align-items: stretch; gap: 1.5rem; text-align: left; }
        .gio-order-complete-dark-hero .gio-order-complete-head { gap: 1rem; padding: 3.5rem 2.5rem; background: #0f1d4f; color: #fff; text-align: center; }
        .gio-order-complete-dark-hero .gio-order-complete-badge { display: none; }
        .gio-order-complete-dark-hero .gio-order-complete-words { max-width: 36rem; }
        .gio-order-complete-dark-hero .gio-order-complete-status { margin-bottom: .875rem; color: #9fb0e8; letter-spacing: .1em; }
        .gio-order-complete-dark-hero .gio-order-complete-headline { font-size: 2.25em; line-height: 1.15; }
        .gio-order-complete-dark-hero .gio-order-complete-message { font-size: 1em; color: #c5d0f2; opacity: 1; }
        .gio-order-complete-dark-hero .gio-order-complete-number { border: 0; background: rgba(255, 255, 255, .14); color: #fff; }
        .gio-order-complete-dark-hero .gio-order-complete-summary, .gio-order-complete-dark-hero .gio-order-complete-deliver-to, .gio-order-complete-dark-hero .gio-order-complete-note, .gio-order-complete-dark-hero .gio-order-complete-actions { box-sizing: border-box; width: auto; margin: 0 2.5rem; }
        .gio-order-complete-dark-hero .gio-order-complete-summary { padding: 0; border-top: 0; }
        .gio-order-complete-dark-hero .gio-order-complete-summary::before { content: "Items"; display: block; margin: 0 0 .125rem; font-size: .6875rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; opacity: .7; }
        .gio-order-complete-dark-hero .gio-order-complete-actions { flex-direction: row; flex-wrap: wrap; }
        .gio-order-complete-dark-hero .gio-order-complete-button { flex: 1 1 10rem; }
        @media (min-width: 40rem) {
        .gio-order-complete-dark-hero .gio-order-complete-card { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); column-gap: 2rem; row-gap: 1.5rem; }
        .gio-order-complete-dark-hero .gio-order-complete-head { grid-column: 1 / -1; grid-row: 1; }
        .gio-order-complete-dark-hero .gio-order-complete-summary { grid-column: 1; grid-row: 2; margin-right: 0; }
        .gio-order-complete-dark-hero .gio-order-complete-summary:not(:has(+ .gio-order-complete-deliver-to)) { grid-column: 1 / -1; margin-right: 2.5rem; }
        .gio-order-complete-dark-hero .gio-order-complete-deliver-to { grid-column: 2; grid-row: 2; margin-left: 0; }
        .gio-order-complete-dark-hero .gio-order-complete-note { grid-column: 1 / -1; grid-row: 3; }
        .gio-order-complete-dark-hero .gio-order-complete-actions { grid-column: 1 / -1; grid-row: 4; }
        .gio-order-complete-dark-hero .gio-order-complete-button { flex: 0 0 auto; }
        }
        @media (max-width: 30rem) {
        .gio-order-complete-dark-hero .gio-order-complete-head { padding: 2.5rem 1.25rem; }
        .gio-order-complete-dark-hero .gio-order-complete-headline { font-size: 1.75em; }
        .gio-order-complete-dark-hero .gio-order-complete-summary, .gio-order-complete-dark-hero .gio-order-complete-deliver-to, .gio-order-complete-dark-hero .gio-order-complete-note, .gio-order-complete-dark-hero .gio-order-complete-actions { margin: 0 1.25rem; }
        }
        CSS;

    /**
     * What the minimal arrangement adds to the base sheet.
     *
     * The card gives up its box and becomes the page: no border, and the
     * page painted in the card's colour rather than the grey ground, centred
     * on a narrow measure. The badge goes and the status line is the tick,
     * in the accent colour; the words box is dissolved with `display:
     * contents` so that the order number can be moved up under the status
     * line with `order`, where it is set very large in monospace with no
     * box round it and the word "Order" hidden — the number is the page,
     * and with the word it would not fit on one line.
     * The headline is small beneath it and the message is the one
     * paragraph. The summary is a quiet list without its rule, the
     * Deliver-to block is centred, and the buttons sit in a row with the
     * second as an underlined link. On a phone the number comes down to a
     * size that fits the width and breaks if it must.
     */
    private const MINIMAL_RULES = <<<'CSS'
        .gio-order-complete.gio-order-complete-minimal { background: var(--gio-background); }
        .gio-order-complete-minimal .gio-order-complete-card { max-width: 30rem; padding: 3rem 1rem; border: 0; gap: 1.25rem; }
        .gio-order-complete-minimal .gio-order-complete-head { gap: 1.25rem; }
        .gio-order-complete-minimal .gio-order-complete-badge { display: none; }
        .gio-order-complete-minimal .gio-order-complete-words { display: contents; }
        .gio-order-complete-minimal .gio-order-complete-status { order: -2; margin: 0; color: var(--gio-accent); letter-spacing: .1em; }
        .gio-order-complete-minimal .gio-order-complete-status::before { content: "✓ "; }
        .gio-order-complete-minimal .gio-order-complete-number { order: -1; max-width: 100%; margin: 0; padding: 0; border: 0; font-size: 4.5em; font-weight: 700; line-height: 1; letter-spacing: -.02em; overflow-wrap: anywhere; }
        .gio-order-complete-minimal .gio-order-complete-number-word { display: none; }
        .gio-order-complete-minimal .gio-order-complete-headline { margin: 0; font-size: 1.25em; }
        .gio-order-complete-minimal .gio-order-complete-message { max-width: 30rem; margin: 0; font-size: 1em; }
        .gio-order-complete-minimal .gio-order-complete-summary { max-width: 24rem; margin: 0 auto; padding: 0; border-top: 0; }
        .gio-order-complete-minimal .gio-order-complete-deliver-to { max-width: 24rem; margin: 0 auto; text-align: center; }
        .gio-order-complete-minimal .gio-order-complete-actions { width: auto; flex-direction: row; flex-wrap: wrap; justify-content: center; align-items: center; }
        .gio-order-complete-minimal .gio-order-complete-button { padding: .875rem 2.25rem; }
        .gio-order-complete-minimal .gio-order-complete-button-secondary { border: 0; padding: .875rem .5rem; text-decoration: underline; }
        @media (max-width: 30rem) {
        .gio-order-complete-minimal .gio-order-complete-card { padding: 2rem .5rem; }
        .gio-order-complete-minimal .gio-order-complete-number { font-size: 2.75em; }
        }
        CSS;

    /**
     * What the compact arrangement adds to the base sheet.
     *
     * The narrow card, centred on the full page at every width, with
     * everything ranged left and set a size down: the head is a small grid
     * with a smaller tick on the left spanning both rows, the headline
     * beside it and the order number as a grey line under the headline,
     * losing its box, and the message under all three — the words box is
     * dissolved with `display: contents` so the three can be placed apart;
     * the summary is boxed with tight rows, the total set
     * a little larger; the Deliver-to block is small; the buttons stack,
     * the second as a centred underlined link. There is no breakpoint: a
     * card this narrow is the same card on a phone.
     */
    private const COMPACT_RULES = <<<'CSS'
        .gio-order-complete-compact .gio-order-complete-card { max-width: 25rem; padding: 1.5rem; align-items: stretch; gap: .875rem; text-align: left; }
        .gio-order-complete-compact .gio-order-complete-head { display: grid; grid-template-columns: auto minmax(0, 1fr); column-gap: .75rem; row-gap: .125rem; align-items: center; }
        .gio-order-complete-compact .gio-order-complete-badge { grid-column: 1; grid-row: 1 / span 2; width: 2.5rem; height: 2.5rem; }
        .gio-order-complete-compact .gio-order-complete-badge svg { width: 1.25rem; height: 1.25rem; }
        .gio-order-complete-compact .gio-order-complete-words { display: contents; }
        .gio-order-complete-compact .gio-order-complete-headline { grid-column: 2; grid-row: 1; margin: 0; font-size: 1.125em; }
        .gio-order-complete-compact .gio-order-complete-message { grid-column: 1 / -1; grid-row: 3; margin-top: .625rem; font-size: .875em; }
        .gio-order-complete-compact .gio-order-complete-number { grid-column: 2; grid-row: 2; justify-self: start; margin: 0; padding: 0; border: 0; font-family: inherit; font-size: .8125em; font-weight: 400; opacity: .72; }
        .gio-order-complete-compact .gio-order-complete-summary { gap: .375rem; padding: .75rem .875rem; border: var(--gio-border-width) solid var(--gio-border); border-radius: var(--gio-radius); font-size: .8125em; }
        .gio-order-complete-compact .gio-order-complete-total { margin-top: .125rem; padding-top: .5rem; font-size: 1.125em; }
        .gio-order-complete-compact .gio-order-complete-deliver-to { font-size: .8125em; }
        .gio-order-complete-compact .gio-order-complete-note { font-size: .8125em; }
        .gio-order-complete-compact .gio-order-complete-actions { gap: .375rem; }
        .gio-order-complete-compact .gio-order-complete-button-secondary { border: 0; padding: .5rem; text-decoration: underline; font-size: .8125em; }
        CSS;

    /**
     * What the timeline arrangement adds to the base sheet.
     *
     * A wide card whose head is a small grid: a smaller tick on the left
     * spanning both rows, the headline and message beside it, and under
     * them one grey line holding the order number and the capture time —
     * the number losing its box, the time written inside it by the view,
     * and the dot between them drawn by a rule. The status line is not
     * drawn here.
     *
     * The tracker is the slot this layout has of its own: four steps in a
     * row, each a numbered disc over a label over a timing, joined by a rule
     * drawn as each step's `::before` across the gap to its left — the first
     * step has none. The first disc is filled in the accent colour with a
     * tick, the second outlined in it, the rest outlined in the border
     * colour and muted, because the Order is placed and the call is next.
     *
     * The summary and the Deliver-to block are two boxed panels side by side
     * on a wide screen, each with its eyebrow — the summary's is written by
     * a rule, since the base has no "Items" heading — and the buttons sit
     * side by side under them. A stranger's page has no Deliver-to block,
     * and its summary is widened to both columns by a `:has()` rule rather
     * than left beside a blank; a browser without `:has()` shows the one
     * panel at half width, which still reads. On a phone the panels stack
     * and so does the tracker: each step becomes a row, disc then words,
     * with the rules between them gone.
     */
    private const TIMELINE_RULES = <<<'CSS'
        .gio-order-complete-timeline .gio-order-complete-card { max-width: 46.5rem; padding: 3rem 2rem; align-items: stretch; gap: 2rem; text-align: left; }
        .gio-order-complete-timeline .gio-order-complete-head { display: grid; grid-template-columns: auto minmax(0, 1fr); column-gap: 1rem; row-gap: .25rem; align-items: center; }
        .gio-order-complete-timeline .gio-order-complete-badge { grid-column: 1; grid-row: 1 / span 2; width: 3rem; height: 3rem; }
        .gio-order-complete-timeline .gio-order-complete-badge svg { width: 1.5rem; height: 1.5rem; }
        .gio-order-complete-timeline .gio-order-complete-words { grid-column: 2; grid-row: 1; }
        .gio-order-complete-timeline .gio-order-complete-headline { margin: 0 0 .25rem; font-size: 1.625em; }
        .gio-order-complete-timeline .gio-order-complete-number { grid-column: 2; grid-row: 2; justify-self: start; margin: 0; padding: 0; border: 0; font-family: inherit; font-size: .875em; font-weight: 400; opacity: .72; }
        .gio-order-complete-timeline .gio-order-complete-capture-time::before { content: " · "; }
        .gio-order-complete-timeline .gio-order-complete-steps { display: flex; align-items: flex-start; margin: 0; padding: 0; list-style: none; }
        .gio-order-complete-timeline .gio-order-complete-step { flex: 1 1 0; min-width: 0; position: relative; display: flex; flex-direction: column; align-items: center; gap: .5rem; text-align: center; font-size: .8125em; font-weight: 600; }
        .gio-order-complete-timeline .gio-order-complete-step::before { content: ""; position: absolute; top: .8125rem; right: 50%; left: -50%; height: 2px; background: var(--gio-border); }
        .gio-order-complete-timeline .gio-order-complete-step:first-child::before { display: none; }
        .gio-order-complete-timeline .gio-order-complete-step:nth-child(2)::before { background: var(--gio-accent); }
        .gio-order-complete-timeline .gio-order-complete-step-mark { box-sizing: border-box; position: relative; z-index: 1; width: 1.75rem; height: 1.75rem; border-radius: 50%; border: 2px solid var(--gio-border); background: var(--gio-background); color: var(--gio-text); display: grid; place-items: center; font-size: .75rem; font-weight: 700; }
        .gio-order-complete-timeline .gio-order-complete-step:first-child .gio-order-complete-step-mark { border-color: var(--gio-accent); background: var(--gio-accent); color: #fff; }
        .gio-order-complete-timeline .gio-order-complete-step-mark svg { width: .875rem; height: .875rem; }
        .gio-order-complete-timeline .gio-order-complete-step:nth-child(2) .gio-order-complete-step-mark { border-color: var(--gio-accent); color: var(--gio-accent); }
        .gio-order-complete-timeline .gio-order-complete-step:nth-child(n+3) { opacity: .72; }
        .gio-order-complete-timeline .gio-order-complete-step-words { min-width: 0; overflow-wrap: anywhere; }
        .gio-order-complete-timeline .gio-order-complete-step-label { margin: 0; }
        .gio-order-complete-timeline .gio-order-complete-step-timing { margin: 0; font-weight: 400; opacity: .72; }
        .gio-order-complete-timeline .gio-order-complete-summary, .gio-order-complete-timeline .gio-order-complete-deliver-to { box-sizing: border-box; padding: 1.125rem; border: var(--gio-border-width) solid var(--gio-border); border-radius: var(--gio-radius); }
        .gio-order-complete-timeline .gio-order-complete-summary::before { content: "Items"; display: block; margin: 0 0 .125rem; font-size: .6875rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; opacity: .7; }
        .gio-order-complete-timeline .gio-order-complete-actions { flex-direction: row; flex-wrap: wrap; }
        .gio-order-complete-timeline .gio-order-complete-button { flex: 1 1 10rem; }
        @media (min-width: 40rem) {
        .gio-order-complete-timeline .gio-order-complete-card { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); column-gap: 1rem; row-gap: 2rem; }
        .gio-order-complete-timeline .gio-order-complete-head { grid-column: 1 / -1; grid-row: 1; }
        .gio-order-complete-timeline .gio-order-complete-steps { grid-column: 1 / -1; grid-row: 2; }
        .gio-order-complete-timeline .gio-order-complete-summary { grid-column: 1; grid-row: 3; }
        .gio-order-complete-timeline .gio-order-complete-summary:not(:has(+ .gio-order-complete-deliver-to)) { grid-column: 1 / -1; }
        .gio-order-complete-timeline .gio-order-complete-deliver-to { grid-column: 2; grid-row: 3; }
        .gio-order-complete-timeline .gio-order-complete-note { grid-column: 1 / -1; grid-row: 4; margin-top: -1rem; }
        .gio-order-complete-timeline .gio-order-complete-actions { grid-column: 1 / -1; grid-row: 5; margin-top: -1rem; }
        .gio-order-complete-timeline .gio-order-complete-button { flex: 0 0 auto; }
        }
        @media (max-width: 30rem) {
        .gio-order-complete-timeline .gio-order-complete-card { padding: 1.5rem 1.125rem; gap: 1.5rem; }
        .gio-order-complete-timeline .gio-order-complete-steps { flex-direction: column; gap: .875rem; }
        .gio-order-complete-timeline .gio-order-complete-step { flex-direction: row; align-items: center; gap: .75rem; text-align: left; }
        .gio-order-complete-timeline .gio-order-complete-step::before { display: none; }
        .gio-order-complete-timeline .gio-order-complete-step-mark { flex: none; }
        }
        CSS;

    /**
     * What the cards grid arrangement adds to the base sheet.
     *
     * The card gives up its box and becomes the page: no border and no
     * background of its own, so the tiles and the summary sit as white
     * panels on the grey ground the way the design draws them. The head
     * loses the badge, and its words box is dissolved with `display:
     * contents` so that the order number and the status line can read as
     * one grey eyebrow — the number moved first with `order`, the status
     * line after it with a dot drawn by a rule — over a large headline. The
     * buttons sit on the right of the headline rather than at the foot: the
     * actions box is placed in the head's row of the card's grid, so the
     * two share a row and a second button, when there is one, sits beside
     * the first.
     *
     * The tiles are the slot this layout has of its own: three panels in a
     * row, each a numbered disc over a title over a line, the first disc
     * filled in the accent colour with a tick. The summary beneath is one
     * wide panel with the lines down the left and the total set large on
     * the right, which is a two-column grid with the total spanning every
     * row the lines could fill — the rows nothing is written into take no
     * height, and the grid has no row gap so that they take no space
     * either. The Deliver-to block is a panel of its own beneath. On a
     * phone everything stacks, the buttons under the headline at full width
     * and the total back under the lines.
     */
    private const CARDS_GRID_RULES = <<<'CSS'
        .gio-order-complete-cards-grid .gio-order-complete-card { max-width: 50rem; padding: 0; border: 0; background: transparent; align-items: stretch; gap: 1.5rem; text-align: left; }
        .gio-order-complete-cards-grid .gio-order-complete-head { flex-direction: row; flex-wrap: wrap; align-items: baseline; gap: .375rem; }
        .gio-order-complete-cards-grid .gio-order-complete-badge { display: none; }
        .gio-order-complete-cards-grid .gio-order-complete-words { display: contents; }
        .gio-order-complete-cards-grid .gio-order-complete-number { order: -1; margin: 0; padding: 0; border: 0; font-family: inherit; font-size: .75em; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; opacity: .72; }
        .gio-order-complete-cards-grid .gio-order-complete-status { margin: 0; opacity: .72; }
        .gio-order-complete-cards-grid .gio-order-complete-status::before { content: "· "; }
        .gio-order-complete-cards-grid .gio-order-complete-headline { order: 1; flex: 1 0 100%; margin: 0; font-size: 1.875em; line-height: 1.15; }
        .gio-order-complete-cards-grid .gio-order-complete-message { order: 2; flex: 1 0 100%; }
        .gio-order-complete-cards-grid .gio-order-complete-tiles { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .875rem; margin: 0; padding: 0; list-style: none; }
        .gio-order-complete-cards-grid .gio-order-complete-tile { box-sizing: border-box; min-width: 0; display: flex; flex-direction: column; gap: .625rem; padding: 1.25rem; background: var(--gio-background); border: var(--gio-border-width) solid var(--gio-border); border-radius: var(--gio-radius); overflow-wrap: anywhere; }
        .gio-order-complete-cards-grid .gio-order-complete-tile-mark { box-sizing: border-box; width: 2rem; height: 2rem; border-radius: 50%; border: var(--gio-border-width) solid var(--gio-border); display: grid; place-items: center; font-size: .8125rem; font-weight: 700; }
        .gio-order-complete-cards-grid .gio-order-complete-tile-mark svg { width: 1rem; height: 1rem; }
        .gio-order-complete-cards-grid .gio-order-complete-tile:first-child .gio-order-complete-tile-mark { border-color: var(--gio-accent); background: var(--gio-accent); color: #fff; }
        .gio-order-complete-cards-grid .gio-order-complete-tile-title { margin: 0; font-size: .9375em; font-weight: 700; }
        .gio-order-complete-cards-grid .gio-order-complete-tile-line { margin: 0; font-size: .8125em; opacity: .72; }
        .gio-order-complete-cards-grid .gio-order-complete-summary, .gio-order-complete-cards-grid .gio-order-complete-deliver-to { box-sizing: border-box; padding: 1.25rem; background: var(--gio-background); border: var(--gio-border-width) solid var(--gio-border); border-radius: var(--gio-radius); }
        .gio-order-complete-cards-grid .gio-order-complete-summary { display: grid; grid-template-columns: minmax(0, 1fr) auto; column-gap: 1.25rem; row-gap: 0; align-items: start; }
        .gio-order-complete-cards-grid .gio-order-complete-row { grid-column: 1; padding: .1875rem 0; }
        .gio-order-complete-cards-grid .gio-order-complete-total { grid-column: 2; grid-row: 1 / span 40; display: flex; flex-direction: column; align-items: flex-end; gap: 0; padding: 0; border: 0; text-align: right; }
        .gio-order-complete-cards-grid .gio-order-complete-total .gio-order-complete-row-label { font-size: .75rem; font-weight: 400; opacity: .72; }
        .gio-order-complete-cards-grid .gio-order-complete-total .gio-order-complete-row-amount { font-size: 1.625rem; font-weight: 700; }
        .gio-order-complete-cards-grid .gio-order-complete-actions { width: auto; flex-direction: row; flex-wrap: wrap; }
        .gio-order-complete-cards-grid .gio-order-complete-button { flex: 1 1 10rem; }
        @media (min-width: 40rem) {
        .gio-order-complete-cards-grid .gio-order-complete-card { display: grid; grid-template-columns: minmax(0, 1fr) auto; column-gap: 1rem; row-gap: 1.5rem; align-items: end; }
        .gio-order-complete-cards-grid .gio-order-complete-head { grid-column: 1; grid-row: 1; }
        .gio-order-complete-cards-grid .gio-order-complete-actions { grid-column: 2; grid-row: 1; }
        .gio-order-complete-cards-grid .gio-order-complete-button { flex: 0 0 auto; }
        .gio-order-complete-cards-grid .gio-order-complete-tiles { grid-column: 1 / -1; grid-row: 2; }
        .gio-order-complete-cards-grid .gio-order-complete-summary { grid-column: 1 / -1; grid-row: 3; }
        .gio-order-complete-cards-grid .gio-order-complete-deliver-to { grid-column: 1 / -1; grid-row: 4; }
        .gio-order-complete-cards-grid .gio-order-complete-note { grid-column: 1 / -1; grid-row: 5; }
        }
        @media (max-width: 40rem) {
        .gio-order-complete-cards-grid .gio-order-complete-tiles { grid-template-columns: minmax(0, 1fr); }
        }
        @media (max-width: 30rem) {
        .gio-order-complete-cards-grid .gio-order-complete-headline { font-size: 1.5em; }
        .gio-order-complete-cards-grid .gio-order-complete-actions { flex-direction: column; }
        .gio-order-complete-cards-grid .gio-order-complete-button { flex: 0 0 auto; }
        .gio-order-complete-cards-grid .gio-order-complete-summary { grid-template-columns: minmax(0, 1fr); }
        .gio-order-complete-cards-grid .gio-order-complete-total { grid-column: 1; grid-row: auto; flex-direction: row; justify-content: space-between; align-items: baseline; gap: .75rem; margin-top: .375rem; padding-top: .625rem; border-top: var(--gio-border-width) solid var(--gio-border); }
        }
        CSS;

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
            self::Timeline => 'gio-order-complete-timeline',
            self::Receipt => 'gio-order-complete-receipt',
            self::DarkHero => 'gio-order-complete-dark-hero',
            self::Minimal => 'gio-order-complete-minimal',
            self::CardsGrid => 'gio-order-complete-cards-grid',
            self::Editorial => 'gio-order-complete-editorial',
            self::AccentBand => 'gio-order-complete-accent-band',
            self::Compact => 'gio-order-complete-compact',
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
            self::Timeline => self::TIMELINE_RULES,
            self::Receipt => self::RECEIPT_RULES,
            self::DarkHero => self::DARK_HERO_RULES,
            self::Minimal => self::MINIMAL_RULES,
            self::CardsGrid => self::CARDS_GRID_RULES,
            self::Editorial => self::EDITORIAL_RULES,
            self::AccentBand => self::ACCENT_BAND_RULES,
            self::Compact => self::COMPACT_RULES,
        };
    }

    /**
     * Whether this layout draws the status line above the headline.
     *
     * Split, editorial and minimal draw one in place of the badge: a short
     * line in the accent colour that says the Order is in before the
     * headline does. Cards grid draws it beside the order number as the
     * eyebrow, and dark hero as the eyebrow of its band. Centered classic,
     * timeline, receipt, accent band and compact say it with the tick.
     */
    public function drawsStatusLine(): bool
    {
        return match ($this) {
            self::Split, self::DarkHero, self::Minimal, self::CardsGrid, self::Editorial => true,
            self::CenteredClassic, self::Timeline, self::Receipt, self::AccentBand, self::Compact => false,
        };
    }

    /** Whether this layout draws the four steps of what happens next. */
    public function drawsSteps(): bool
    {
        return match ($this) {
            self::Timeline => true,
            self::CenteredClassic, self::Split, self::Receipt, self::DarkHero, self::Minimal, self::CardsGrid, self::Editorial, self::AccentBand, self::Compact => false,
        };
    }

    /**
     * Whether this layout writes the Order's capture time beside the order
     * number.
     *
     * Timeline does, because a tracker whose first step is "Order placed"
     * ought to start from a real time; the others leave the number alone.
     */
    public function drawsCaptureTime(): bool
    {
        return match ($this) {
            self::Timeline => true,
            self::CenteredClassic, self::Split, self::Receipt, self::DarkHero, self::Minimal, self::CardsGrid, self::Editorial, self::AccentBand, self::Compact => false,
        };
    }

    /** Whether this layout draws the three tiles under the headline. */
    public function drawsTiles(): bool
    {
        return match ($this) {
            self::CardsGrid => true,
            self::CenteredClassic, self::Split, self::Timeline, self::Receipt, self::DarkHero, self::Minimal, self::Editorial, self::AccentBand, self::Compact => false,
        };
    }

    /**
     * Whether this layout draws a button that opens the browser's print
     * dialog.
     *
     * Receipt does, because a receipt is for keeping: the one line of script
     * on the page is that button's handler, and the document carries no
     * other.
     */
    public function drawsPrintButton(): bool
    {
        return match ($this) {
            self::Receipt => true,
            self::CenteredClassic, self::Split, self::Timeline, self::DarkHero, self::Minimal, self::CardsGrid, self::Editorial, self::AccentBand, self::Compact => false,
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
            self::Timeline => 'A tick, the headline and message, and the order number with the time it was placed, then a four-step tracker of what happens next in your own words, with the lines and total and where it is going as two boxes beneath, and the buttons last. On a phone the tracker stacks.',
            self::Receipt => 'A narrow till receipt: the headline and message, then the order number, the lines, the total and where it is going in typewriter figures between dashed rules, your note, and the first button with a Print button beside it that opens the browser\'s print dialog.',
            self::DarkHero => 'A navy band across the top, the same navy whatever your accent, holding your status line, a big headline, the message and the order number in white, then the lines and total beside where it is going on white, your note, and the buttons. On a phone the two stack.',
            self::Minimal => 'The order number set very large under your status line, a small headline and one paragraph, then the lines and total as a quiet list, where it is going, your note, and the buttons centred, the second as a link.',
            self::CardsGrid => 'The order number and your status line over a big headline, with the first button beside it, then three tiles in your own words, the lines with the total set large, and where it is going, each a white card on a grey page. On a phone the tiles stack.',
            self::Editorial => 'Ranged left with hairline rules: your status line with a tick, the headline, the message and the order number, then the lines, delivery, payment and the total as a labelled list with where it is going beneath, and the buttons last.',
            self::AccentBand => 'A strip in your accent colour across the top holding the tick, the headline, the message and the order number, with the lines and total as a ruled table beneath, then where it is going beside the buttons.',
            self::Compact => 'One narrow card centred on the page at every width: a small tick beside the headline and order number, the message, the lines and total in a box, where it is going, your note, and the buttons stacked, the second as a link.',
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
            self::Timeline->value => 'Timeline',
            self::Receipt->value => 'Receipt',
            self::DarkHero->value => 'Dark hero',
            self::Minimal->value => 'Minimal',
            self::CardsGrid->value => 'Cards grid',
            self::Editorial->value => 'Editorial',
            self::AccentBand->value => 'Accent band',
            self::Compact->value => 'Compact',
        ];
    }
}
