---
paths:
  - 'app/Support/OrderComplete/**'
---

# Support Order Complete

## The Order Complete Config mirrors the Checkout Config, and its words are filled in one place
`OrderCompleteConfig` is a second per-Page JSON column cast the way `CheckoutConfig` is: never null, never partial, `fromArray()` reads every key totally (missing, unknown layout, over-long or non-text values all become the default for that key) and the observer seeds `default()` on create. Every string is nullable and null means the built-in English words; the defaults, max lengths and refusals live on the value objects and the Order complete tab asks them through `Schemas\RefusedBy::rule()`, so the tab and the silent read cannot disagree.

Nothing draws a Config's raw strings. The view is handed `OrderCompleteWords` (`$config->wordsFor(orderNumber, total, pageUrl)`), which is the one place `{order_number}` and `{total}` are substituted (`fillLink()` encodes them for a link), defaults are filled, and "empty secondary label means no button" is decided. Steps and tiles are fixed-length lists read by position (`byPosition()`), padded rather than refused.

The page reads the Order's Page as it stands (`Order::orderCompleteConfig()`, via the `withTrashed()` relation, so a soft-deleted Page keeps its words like `retryPaymentMethods()`) and falls back to `default()` only when the Page is gone. It is coloured from `Order::pageStyleTokens()->completed()`, the same completion the Checkout does; it carries no Style Tokens of its own. The document may load nothing external — no link, script, img or `url()` — and `CheckoutCaptureTest` pins that; the base sheet lives inline in the view and a layout adds only a modifier class plus `styleRules()` (ADR-0009 convention).

## An Order Complete Layout rearranges by CSS only; the view's byte order is fixed
The view writes head (badge, words, order number), then the slot a layout may have of its own (Timeline's steps or Cards grid's tiles, each behind its predicate; Timeline's capture time is a `<time>` inside the number), then summary, Deliver-to, note, actions in that order for every layout, and a layout's `styleRules()` block is descendants of its own modifier class that re-place them (grid rows by number, `display: none` on the badge, a gradient on the card for Split's panel). Do not add a per-layout Blade branch for arrangement; the only view-level branches are the slot predicates (`drawsStatusLine()` etc.). Rows are placed by explicit number so a stranger's page, which has no Deliver-to block, keeps the buttons where they were. `CheckoutCaptureTest` pins that a page carries the base rules, its own block, and no other layout's block or modifier class.
