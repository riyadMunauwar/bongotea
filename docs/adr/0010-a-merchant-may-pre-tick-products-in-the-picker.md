# A Merchant may pre-tick Products in the Picker

A Product Picker opened with nothing ticked, and the glossary stated the reason as domain law: on a
cash-on-delivery Page an extra nobody chose is not a larger sale, it is a refused parcel. A Merchant
may now designate any subset of a Page's Products to open ticked — one, several, or none, which
remains the default.

The old rule was half right, and the wrong half was costing conversions. A Picker with nothing
ticked opens showing a total of zero and no price at all until the visitor acts, which reads as a
broken form rather than as a choice being offered.

The refused-parcel risk is real and is not dismissed, only narrowed. One pre-ticked Product is a
*default selection*: the visitor sees a price, then chooses. Two or more is a *pre-loaded upsell*,
where a visitor tapping through without reading receives items they never chose, refuses the parcel
at the door, and the Merchant pays courier fees in both directions on an Order that was never worth
taking. Both are allowed, because a Merchant selling a genuine bundle knows their own Products
better than this rule does — and the Checkout tab warns above one, so the risky case is chosen
knowingly rather than reached by accident.

## Consequences

- A default selection is configuration, not pricing, so it stays out of the Pricing Fingerprint.
  Changing which Products open ticked must never reject the forms visitors are half-way through
  typing.
- Nothing changes at Capture. The server still checks what was actually ticked; a pre-ticked box is
  a starting position and never an agreement.
- A Page down to a single Product is unaffected. A choice of one is not a choice, and it is sold
  without asking.
- Soft-deleting a pre-ticked Product must not leave a Page opening with a tick against something it
  no longer sells. The Pricing Fingerprint already refuses such a form; the default selection is
  read against the Products the Page currently offers, and a name it cannot find is dropped.

## The alternative, and why not

Exactly one pre-ticked Product, enforced by the model so the upsell case cannot be configured at
all. Attractive because it fixes the zero-total form while making the expensive mistake
unreachable. Rejected because a Merchant selling a set that is normally bought together would have
to un-model it to fit, and because the failure it guards against is one a warning can raise at the
exact moment the Merchant makes the choice — which is where a Merchant can still act on it.
