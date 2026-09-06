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

## Validation is a bounded vocabulary, and never a rule a merchant wrote
There is no box anywhere — not on the Checkout tab, not in a stored Config — that takes a rule string or a regular expression of the merchant's. What a question will accept is a length, a bound, a switch, and a shape chosen from `FieldPattern`. That is the whole of it, and it is a deliberate refusal rather than a feature nobody got round to.

Two reasons, and both are about where the failure lands. A malformed rule does not surface in the admin as an error a merchant can act on; it surfaces as a conversion rate going to zero on a page that is spending money, discovered a day later. And an expression nobody reviewed runs on a public endpoint that has no session and a loose rate limit, against text a stranger posts — the merchant cannot test it against the traffic it will meet. So the vocabulary is fixed in front of a reviewer, every expression in it is a plain character class with no backtracking construct, and adding to it is a code change.

`FieldConstraints` resolves the merchant's figures **once**, in `effective()`, and `htmlFor()` and `rulesFor()` both read that. Do not add an attribute in one and a rule in the other: the property the whole thing exists for is that a customer is never blocked by their browser from typing something a capture would have taken, nor refused for a reason the form never showed. The same goes for a ceiling — `CheckoutFieldType::lengthCeiling()` is the one figure that becomes both `maxlength` and `max:`, which is why neither `controlHtml()` nor `answerRules()` states a length any more.

Two consequences worth knowing. A textarea has no HTML `pattern`, so a shape on one is checked at capture only; that asymmetry is allowed *because* it fails in the safe direction, and anything that made the browser stricter than the server would not be. And a built-in constrains nothing: its rules belong to the column waiting for the answer, a Config decides its wording and its two flags and no more (ADR-0006), so `CheckoutField::constraints()` returns `none()` unless the field is one the merchant authored.

Both halves of "refused twice" apply, and the silent half differs by which figure is wrong. A smallest above the largest is refused on the tab with a message and **dropped** on read — dropped rather than enforced, because the alternative is a form on a live page that cannot be submitted at all. A longest above the ceiling is refused on the tab and **capped** on read, because the ceiling is a real limit and the merchant's figure is only too generous.

## A stored Config is read totally, and a value is refused twice
Every `fromArray()` here drops what it cannot use rather than throwing: an unusable field, an option with no value, a colour that does not parse. A landing page answering paid traffic with a 500 costs more than one asking a merchant one question fewer, or drawn in the wrong blue, than they meant.

That means every refusal is made in two places — worded on the Checkout tab, where there is a merchant to tell, and silent in the Config, where there is not. Keep both. The tab is not the only route a Config gets written, and for style tokens the silent one is load-bearing: it is what stops a value in the database ending the declaration it is written into. Ask the domain object for the refusal (`StyleToken::refusal()`, `CustomCheckoutField::keyRefusal()`) so the two cannot disagree about what is allowed.
