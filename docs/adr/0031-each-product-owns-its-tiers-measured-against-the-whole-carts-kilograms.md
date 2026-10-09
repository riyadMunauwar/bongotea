# Each Product owns its Tiers, measured against the whole Cart's Kilograms

Supersedes the Tier half of ADR-0028. The Delivery Bands, the one pricing module, its TypeScript
mirror and the shared worked examples carry over unchanged.

A Product's **Tiers** are its own: an ordered list of `{min, max|null, price}` kept on the Product,
covering every whole kilogram from one upwards without gap or overlap, the last open-ended. The
price is a **Tier Price** typed by the Merchant in whole taka — there is no discount kind and no Tier
Discount. A Product may have one Tier, which is a flat price, and no Tier may cost more per Kilogram
than one before it; an equal price is allowed. The **Regular Price** is the first Tier's price, and
is what shows struck through once a cheaper Tier applies. There is no store-wide table of Tiers and
no default: Settings → Pricing holds only the Delivery Bands.

What is measured against a Product's Tiers is still the **whole Cart's** total Kilograms, never the
line's own. Four kilograms of one tea and three of another are seven kilograms, and each tea charges
whatever its own Tiers say for seven. So the business rule ADR-0028 was protecting survives: a buyer
who mixes teas is a bulk buyer.

## Why ADR-0028 is reversed here

ADR-0028 rejected per-Product ranges because "no Cart can be 'in' one Tier" and "add N more
kilograms to save ৳X has no single answer". The Merchant wants teas to break at different weights —
a premium tea at 1–3 / 4–20 kg, an everyday one at 1–5 / 6–10 / 11+ kg — and is willing to pay that
cost. Both objections are answered rather than ignored:

- **No Cart is in one Tier.** Each priced line now names the range it was priced in, and an Order
  Line will record that range as it stood at Capture, so "why did they pay ৳500?" still has an
  answer after the Product's ranges move.
- **The nudge.** It names the **nearest** Cart weight at which any line already in the Cart gets
  cheaper, and the saving at that weight on the Kilograms already there, across every line that drops
  at it. Adding *any* tea reaches that weight, so a Cart-level message is the true one; a per-line
  hint would wrongly suggest only that tea counts.

## Considered options

- **A store-wide default table with per-Product overrides.** Rejected by the Merchant: one place for
  a Product's prices is simpler than a default that some Products follow and some do not, and moving
  a default's edge would silently re-attach every following Product's prices.
- **Typed Tier Prices versus a Regular Price less a discount.** Typed, because wholesale tea is quoted
  as a price per kilogram at a weight, and discounts are unreadable across teas whose edges differ.
- **Measuring each line's own Kilograms** (per-Product quantity breaks). Still rejected, for ADR-0028's
  reason: it punishes the mixed order the business wants.

## Consequences

- The price matrix can no longer be one grid with shared columns; it shows a price ladder per Product.
- A Product's Tiers are refused on save, by the form and by the model, unless they cover every
  kilogram once and never rise; the pricing module may assume both.
- `products.price` is kept as the Regular Price — the first Tier's price, written by the model from
  the Tiers — because tracking and reports read it. Order capture still stamps it as the unit price; until Capture prices through the pricing module and records each line's Tier range, a bulk Order captured is charged the Regular Price.
- Editing a Product's Tiers moves every Cart at once and no Order already captured.
