# The Tier is chosen by the whole Cart's Kilograms, from one store-wide table

A Product's price per Kilogram falls as the **Cart** gets heavier, not as that Product's own
quantity grows. The **Tiers** are one table for the whole store, kept in the Pricing settings group
as an ordered list of `{min, max|null}` ranges covering every whole kilogram from one upwards
without gap or overlap, the last open-ended. A Cart's Tier is the one its total Kilograms — across
every line — fall in, and every line is priced from that same Tier. A Product chooses its prices,
never its ranges: it carries a Regular Price, one discount kind (a percentage or a fixed amount of
taka), and one Tier Discount per Tier. The Tier Price is the Regular Price less that Discount,
rounded half-up to whole taka for a percentage; a Tier the Product has said nothing about charges
the Tier below's price.

The **Delivery Charge** is read the same way and from a table of its own: **Delivery Bands**,
`{min, max|null, fee}` over the same total Kilograms, the same for the whole country. The two
tables owe each other nothing — where a band's edge falls says nothing about where a price changes.

One pricing module is the authority for all of it. Given the catalogue and a list of
`{product, kilograms}` lines, plus any Price Overrides, it answers the Tier, every line's Tier
Price and total, the Delivery Charge and its band, the total, the savings, and how far the next
Tier and free delivery are. The Checkout's Capture, Repricing and the Capture of an Order taken by phone
all ask it; the storefront runs a TypeScript mirror of the same arithmetic for display, and a shared
JSON file of worked examples is the contract both are tested against.

This replaces two GioPages decisions at once. Delivery Options authored per Page (ADR-0005) and a
Checkout Config held whole by each Page (ADR-0008) existed because every GioPages Page was its own
campaign with its own prices. Bongo Tea is one store with one price list, so there is nothing for a
Page to own.

## Considered options

- **Quantity breaks per Product** — the price of a tea falls as *that tea's* kilograms grow. This is
  what every off-the-shelf store offers, and it was rejected because Bongo Tea's buyers mix: a tea
  stall taking four kilograms each of three teas is a twelve-kilogram buyer, and pricing each line
  as a four-kilogram one punishes exactly the order the business wants.
- **Tier ranges per Product.** Rejected: the price matrix stops being a matrix, no Cart can be "in"
  one Tier, and "add N more kilograms to save ৳X" has no single answer to give.
- **Delivery by District.** Rejected: the store charges the same nationwide, and weight is what
  costs the courier. District stays on every Order for the courier's sake and settles no figure.

## Consequences

- Adding a Tier in Settings never leaves a Product unpriced; it charges the Tier below until the
  Merchant fills its Discount in.
- The settings form refuses a gap or an overlap in either table, so every Cart weight has exactly
  one Tier and one Delivery Charge. The pricing module may assume both.
- Editing a Tier or a Discount moves every Cart and every future Order at once, and no Order already
  captured: the Order Line records the Tier Price in force at Capture.
- The "next Tier" saving counts only the kilograms already in the Cart, so the figure is what the
  Customer's current Cart would save, never a guess at what they might add.
- A Price Override changes one line's price and nothing else: its kilograms still count toward the
  Tier and the Delivery Band.
- Two implementations of the same arithmetic can drift. The shared worked examples are what stops
  them; a rule changed in one and not the other fails a test, and a drift that escapes shows up as a
  refused Quote (ADR-0029), never as a wrong charge.
