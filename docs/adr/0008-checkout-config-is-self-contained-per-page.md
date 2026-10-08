# A Checkout Config is self-contained, per Page

> **Superseded by [ADR-0028](0028-the-tier-is-chosen-by-the-whole-carts-kilograms-from-one-store-wide-table.md) and [ADR-0029](0029-the-cart-lives-in-the-browser-and-capture-checks-a-quote.md).** Prices and Delivery Bands are store-wide, and the Checkout is no longer configured per Page.

Every Page carries a complete Checkout Config of its own: fields, Delivery Options, linked Products,
appearance. It is seeded when the Page is created, so a Page publishes without anyone opening the
Checkout tab, and it can be copied wholesale from another Page. Nothing is inherited at render time.
Two Pages that look identical hold two copies, and editing one changes one.

The duplication is the point. These are landing pages behind paid traffic, each mid-campaign and
each with its own conversion history. Under any form of sharing, one edit reaches every Page using
the shared thing at once — which is the same failure the current single global delivery charge
already has, and the one this whole change exists to end. A Merchant with fifty live campaigns needs
the blast radius of an edit to be the Page they are looking at.

Invalidation is the second reason, and it is not negotiable in the same way. The cache drivers in
use have no tags, so every invalidation must name the URLs it clears — the cache of ADR-0004, whose
files are only ever removed by something that names them. Saving a Page names one file
and can forget one file; saving a shared preset would have to name every Page using it, and getting
that wrong cold-starts the wrong set of Pages — either stale HTML on a campaign or a full render
storm across dozens at once.

## Consequences

- There is no bulk edit. A charge that changes across fifty Pages is fifty edits, and the honest
  answer to a Merchant who wants it is a copy from one Page onto others, which is a copy at that
  moment and not a link.
- Copy-from-another-Page is what makes the decision survivable, so it is part of the feature rather
  than an enhancement: without it, running many similar Pages means configuring each from scratch.
- Every read has one shape. The Config column is added, backfilled and then made non-nullable, so
  nothing anywhere branches on whether a Page has been configured yet.
- The seeded default has to reproduce the current form exactly — the same fields, on and required,
  and a single Delivery Option carrying the current global charge, which is today's behaviour of a
  charge added with no picker. A Merchant who never opens the tab must see no change at all.
- The Config is not expressed as shortcode attributes. A field list, an options array, a token map
  and a layout do not fit in a bracketed attribute string, and splitting configuration between the
  Page body and the Page record means two places to look when a Checkout renders wrong.

## The alternatives, and why not

Merchant-level defaults, with Pages overriding what they need. Attractive because it makes the
common change one edit. It also makes every accidental change one edit across every live campaign,
and it puts a resolution step between the Page and what it renders — so answering "why did this form
change?" means reading two records and knowing which wins.

Shared presets a Page points at. The same failure, made explicit and worse: a preset edit is
deliberate, so it will be used, and the Merchant doing it is unlikely to know which fifty campaigns
are attached. It is also the case invalidation handles least well, since the set of URLs to clear is
whatever currently points at the preset.
