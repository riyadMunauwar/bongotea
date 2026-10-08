# A layout's rules ship only to the pages that draw it

> **Superseded by [ADR-0029](0029-the-cart-lives-in-the-browser-and-capture-checks-a-quote.md).** The Checkout is one fixed form; there are no Checkout Layouts.

The Checkout offers five arrangements — stacked, split, compact, summary-first and form-only. They
share one base stylesheet, as they always have. What is new is that a layout's own rules are emitted
only when a Page is arranged in it: a Page drawn stacked carries the base sheet and the stacked
rules, and nothing of the other four.

Until now the emitted styles were byte-identical whichever layout a Page used, and a test pinned
that. It held because form-only differs from stacked only by what the view draws —
`.gio-checkout-form-only` carried no declarations at all, so there was nothing to leave out. Split,
compact and summary-first are not like that. Each is a real arrangement with rules of its own, and
under the old scheme every Page would carry all five.

That is the outcome the old test's own comment warned about — "a third layout would charge them
twice" — and at five it is four times over. These styles ride inside every cached page, answering
paid mobile traffic. Measured before the change: the sheet is 4,878 bytes raw and 1,345 gzipped,
inside a cached page of 26,262 raw and 6,352 gzipped. Four unused arrangements is roughly five per
cent of every page, spent on nothing.

## What this does not relax

A layout still may not carry a sheet of its own. That is the rule this is most likely to be confused
with, and it stands: the base is shared and a layout adds to it. A copy per layout is what would
charge every visitor twice; a small additive block that appears only when it applies charges nobody.

Nor does it reopen generated rules. A layout is one of five fixed values picked from a list, so what
is emitted is chosen from five blocks written by hand — nothing is generated from a Merchant's
figures. The sheet is still the same bytes for every Page arranged the same way, however many
questions it asks and however much of it is restyled. That property is unchanged; it is now pinned
per layout rather than across all of them.

## Consequences

- The test asserting byte-identity across layouts is replaced by two: the base is common to all
  five, and a Page contains no other layout's rules.
- Switching a Page's layout changes its byte length. It already changed its markup, so nothing new
  follows from this.
- Adding a sixth layout is still a rule and not a copy, and now costs only the Pages that choose it.
