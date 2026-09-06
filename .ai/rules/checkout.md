---
paths:
  - 'app/Support/Checkout/**'
  - 'resources/views/shortcodes/checkout.blade.php'
  - 'app/Filament/Resources/Pages/Schemas/PageForm.php'
---

# Checkout

## Styling is a token set, never a generated rule
The island's CSS is flat, single-class and prefixed so that a merchant's own stylesheet overrides it without raising specificity — that is a promise the product makes, not an accident of how the block was written. A generated `.gio-checkout-field-3 { … }` would be more specific than the hand-written rule beside it on the same page, so the style editor would start winning arguments it is supposed to lose.

So everything a merchant can restyle is a custom property in `StyleToken`, declared once on `.gio-checkout` and read by the rules that were already there. A field that differs redeclares a token on its own wrapper and the cascade confines it; nothing generates a selector, which is also why the emitted block is the same length on a checkout asking twenty questions as on one asking two. If you find yourself wanting a new rule to express an appearance setting, add a token instead — and if it cannot be a token, it is asking for CSS the merchant should write.

Four consequences worth knowing before editing the block.

**An inherited property has to be declared on the field wrapper as well as on the root.** `color`, `font-size` and `accent-color` inherit as *computed* values, so a wrapper redeclaring `--gio-text` cannot reach back and change what `.gio-checkout` already worked out — only a declaration on the wrapper itself reads what the wrapper carries. That repetition in `.gio-checkout-field` is load-bearing; delete it and per-field text colour and font size silently stop working, with nothing failing. A token whose property is *not* inherited (a border, a radius, a background) needs no such pairing, because the declaration that reads it already sits on a descendant.

**Sizes below the root are `em` against `--gio-font-size`**, so nothing between a size and the root may set a font size or it compounds — this is why the summary's size sits on `.gio-checkout-row` rather than on the container, and why the field wrapper's `font-size` is the root's own value rather than a second one.

**There is deliberately no separate padding control for inputs.** `--gio-input-size` is one scale driving padding and font size together, because a box sized up without its text is a box a customer cannot read back what they typed in. It covers the rows a customer picks from as well as the boxes they type in; they are all things a thumb has to hit.

**A field's overrides go in an inline `style` attribute, which does outrank a merchant's stylesheet — for those tokens only.** Accepted, because the alternative is the generated selector this whole rule exists to avoid, and it is narrow: the merchant still wins by setting the resolved property (`.gio-checkout-input { border-color: … }`), which is the control the promise above is actually about. Don't "fix" it by raising the checkout's own specificity to match.

## A fixed modifier class is not the generated rule the token set exists to avoid
The rule above says an appearance setting becomes a token rather than a rule. Two things are deliberately not tokens, and neither breaks it.

**A layout is a class on the root, and both layouts ship one stylesheet.** Form-only differs by what the view draws — the product panel is omitted, not hidden — so `.gio-checkout-form-only` currently carries no declarations at all: it is a stable hook for a merchant's own CSS, and the place a layout's rules would go if one ever needed any. Put them in the shared sheet when that day comes. The block rides inside every cached page, so a layout with its own copy would charge every visitor for an arrangement their page is not using, which is why `LandingPageTest` pins the emitted styles byte-identical between the two.

**A field's width is a flat class too, and its breakpoint cannot be a token.** `.gio-checkout-field-half` is in the base sheet whether or not any question uses it, so pairing every question costs the same bytes as pairing none, and it beats `.gio-checkout-field` by order rather than by specificity — the same trade the quantity stepper makes. The two-column grid exists only inside the media query, which is the whole of "the collapse on a phone cannot be overridden": there is nothing to override, because no per-page value reaches it. A custom property cannot be read in a media-query prelude, so a per-page breakpoint would have to be a generated rule — the thing the token set exists not to do.

Note the layout is read where the picker is decided, not only where the form is drawn. `CheckoutConfig::picksProducts()` gates the merchant's switch on the layout, and `Page::offersProductChoice()` and `StoreOrderRequest::wasAskedToChoose()` both go through it. A picker left standing on a form-only page compiles a rule demanding a choice the form never drew — every order on a live page refused for a box no customer can find. The switch itself is kept, so switching back restores the page the merchant configured.

## Validation is a bounded vocabulary, and never a rule a merchant wrote
There is no box anywhere — not on the Checkout tab, not in a stored Config — that takes a rule string or a regular expression of the merchant's. What a question will accept is a length, a bound, a switch, and a shape chosen from `FieldPattern`. That is the whole of it, and it is a deliberate refusal rather than a feature nobody got round to.

Two reasons, and both are about where the failure lands. A malformed rule does not surface in the admin as an error a merchant can act on; it surfaces as a conversion rate going to zero on a page that is spending money, discovered a day later. And an expression nobody reviewed runs on a public endpoint that has no session and a loose rate limit, against text a stranger posts — the merchant cannot test it against the traffic it will meet. So the vocabulary is fixed in front of a reviewer, every expression in it is a plain character class with no backtracking construct, and adding to it is a code change.

`FieldConstraints` resolves the merchant's figures **once**, in `effective()`, and `htmlFor()` and `rulesFor()` both read that. Do not add an attribute in one and a rule in the other: the property the whole thing exists for is that a customer is never blocked by their browser from typing something a capture would have taken, nor refused for a reason the form never showed. The same goes for a ceiling — `CheckoutFieldType::lengthCeiling()` is the one figure that becomes both `maxlength` and `max:`, which is why neither `controlHtml()` nor `answerRules()` states a length any more.

Two consequences worth knowing. A textarea has no HTML `pattern`, so a shape on one is checked at capture only; that asymmetry is allowed *because* it fails in the safe direction, and anything that made the browser stricter than the server would not be. And a built-in constrains nothing: its rules belong to the column waiting for the answer, a Config decides its wording and its two flags and no more (ADR-0006), so `CheckoutField::constraints()` returns `none()` unless the field is one the merchant authored.

Both halves of "refused twice" apply, and the silent half differs by which figure is wrong. A smallest above the largest is refused on the tab with a message and **dropped** on read — dropped rather than enforced, because the alternative is a form on a live page that cannot be submitted at all. A longest above the ceiling is refused on the tab and **capped** on read, because the ceiling is a real limit and the merchant's figure is only too generous.

## A stored Config is read totally, and a value is refused twice
Every `fromArray()` here drops what it cannot use rather than throwing: an unusable field, an option with no value, a colour that does not parse. A landing page answering paid traffic with a 500 costs more than one asking a merchant one question fewer, or drawn in the wrong blue, than they meant.

That means every refusal is made in two places — worded on the Checkout tab, where there is a merchant to tell, and silent in the Config, where there is not. Keep both. The tab is not the only route a Config gets written, and for style tokens the silent one is load-bearing: it is what stops a value in the database ending the declaration it is written into. Ask the domain object for the refusal (`StyleToken::refusal()`, `CustomCheckoutField::keyRefusal()`) so the two cannot disagree about what is allowed.

## The fingerprint covers what the courier collects, and adding to it is not free
`PricingFingerprint` is written into the rendered form and re-checked at Capture, and it holds the linked Product ids, their prices, and the Delivery Option amounts — nothing else. The asymmetry is the point (ADR-0007): collecting an amount the Customer never agreed to is a refused parcel and courier fees in both directions, while capturing against a form missing a colour change is nothing. Anything you put in it rejects the in-flight submissions of everyone mid-form on a Page that is spending money, so the test is whether the thing you are adding changes the figure handed over at the door — not whether it changed.

Two directions to get this wrong. A new *priced* thing (a discount, a surcharge, a second charge of any kind) must go in, or it reopens the defect for that figure. A new *configuration* thing must not, however tempting the "just fingerprint the Config" simplification looks — that version rejects every open form on every save of the Checkout tab.

Note what follows from Products being in it by id: soft-deleting one refuses the forms that offered it, rather than quietly selling the rest at a lower total than the visitor agreed to. `StoreOrderRequest::unofferedProducts()` and the quantity drop in `OrderCapture` still stand, but only for a hand-made post — the fingerprint reaches a stale form first.

`PricingFingerprint::for()` takes a Page, and everything reads the figures through it — the view is handed one, and `matches()` recomputes one — because the form and the check reading two different sources is exactly the drift it exists to prevent. Do not compute one from `$page->products` or the Delivery Options directly.

Note the two halves are fingerprinted differently on purpose. Products go in by id, sorted, so a reorder in the admin costs nothing. Delivery amounts go in by position, because with the labels left out there is nothing else tying an amount to the arrangement it belongs to — a bare set could not tell a reorder from the Merchant swapping 60 and 120 between "Inside Dhaka" and "Outside Dhaka", which is a repricing of both.
