# The Container is its own concern, not a Style Token

> **Superseded by [ADR-0029](0029-the-cart-lives-in-the-browser-and-capture-checks-a-quote.md).** The Checkout is one fixed form; there is no per-Page Container.

The Checkout draws itself inside two boxes. The **ground** is the section the design paints behind
everything — `.gio-checkout`, the background token's fill and the design's outer padding. The
**card** is the one bordered panel the Customer reads inside it — `.gio-checkout-card`, the surface
fill, a one-pixel border, a radius and the inner padding, capped at a width and centred. A Merchant
who wraps `[checkout]` in their own styled section gets a box-in-box: their wrapper, then our grey
ground, then the card. Until now there was no way to turn any of it down.

The **Container** is a new per-Page concern that gives the Merchant control over those two boxes: a
new tab beside Look, a new typed value object on the Checkout Config stored under its own `container`
key, and — as the concern grows — controls for the ground's padding, the card's padding, its width
and its alignment. This ADR records the two decisions that shape it, both of which cut against a rule
already written down.

## The Container is not a Style Token, and the eleven stay eleven

`.ai/rules/checkout.md` says an appearance setting becomes a custom property in `StyleToken` — read
by a flat rule that was already there — rather than a rule of our own, and that there are exactly
eleven. The Container does not fit that mould, and forcing it to would break the token model rather
than honour it.

Turning the ground off is **structural**, not a colour a flat rule reads: the section is painted and
padded, or it is neutralised. Card alignment is a choice of margins, not a value a single declaration
reads. So the Container is a separate value object, refused and read as totally as every other Config
value, and the Look tab keeps its identity as the eleven Style Tokens and nothing else. The card's
surface, border and radius remain the three tokens they already are; the Container tab points the
Merchant at them for the flush recipe rather than duplicating them.

The ground's background colour is the one thing that is **not** duplicated: it stays the existing
Background Style Token. Recolouring or clearing the ground is a colour a flat rule reads, which is
exactly what a token is for — and it keeps a Container change flowing through the same cache
invalidation a Style Token save already triggers, so no new cache concern is introduced.

## Per-Page card width reverses the retired `max_width` token — deliberately

`.ai/rules/checkout.md` records that `max_width` was retired as a token: how wide the form sits is
the design's figure, written in the base sheet, and a layout wanting a different width states it in
its own block. The stated reason a width could not be a token was the breakpoint problem — a custom
property cannot be read in a media-query prelude, so a per-page breakpoint would force a generated
rule, the one thing the flat sheet exists not to write.

The Container's card width **is** a free per-Page value, written straight into the card's `max-width`
as the design's figure — the same literal emission the card's padding uses, not a `var()` and not a
generated selector. This reverses the retirement, and the reversal is sound because the two cases are
not the same: the value is one figure in the one `.gio-checkout-card` rule, while the breakpoint that
collapses paired questions onto one column stays a fixed figure in the sheet and reads no per-Page
value at all. So the concern that retired `max_width` — per-page breakpoints forcing generated rules
— does not apply, and card width can be a free per-Page value without reopening it. The default is
`35rem`, so a Config that says nothing about it emits `max-width: 35rem` byte for byte.

Card **alignment** rides beside it: left / centre / right, three fixed choices and no free value (the
trade `FieldWidth` already makes, since a typed offset would resolve against a parent whose width
nobody here knows), placing the card with an `auto` margin on the side with the slack. Centre is
`margin: 0 auto`, the pair the card has always carried. Both width and alignment apply whether the
ground is drawn or not, so a bare Checkout can still be narrowed and placed inside a Merchant's own
wrapper.

This ADR is the record the rule points at: `max_width` is no longer simply retired, it is a per-Page
Container value, and a future reader finding the old retirement note should find this alongside it.

## Consequences

- Every already-published Page renders byte-identically. Each Container field defaults to today's
  design figure — the ground on, the design's padding, the `35rem` width, centred — so a Config that
  says nothing about the Container reads back exactly the bytes it always did, across every layout.
- `container` is not in `PricingFingerprint`. It is configuration, not a figure the courier collects,
  so editing it must never reject an in-flight submission — the same reasoning as the layout, the
  copy and the Default Selection (ADR-0007, ADR-0010).
- The value object is read totally, like the rest of the Config: a stored Container value this
  installation cannot use is dropped for its default rather than throwing, so a damaged Config never
  takes a paid landing page down.
- The glossary gains **the ground**, **the card** and **Container**. The tab and its copy use those
  words and never "cart", "panel" or "wrapper".
