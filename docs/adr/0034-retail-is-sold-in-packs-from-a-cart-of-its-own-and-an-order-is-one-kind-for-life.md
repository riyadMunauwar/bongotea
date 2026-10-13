# Retail is sold in Packs from a Cart of its own, and an Order is one kind for life

Bongo Tea opens some Products to retail: a **Pack** is a sealed size of a Product — 100 g, 200 g,
250 g — known by its weight alone, with an MRP and a Pack Price of its own and an Available switch
that only counts while its Product's does. A Product is open to retail exactly when it has at least
one Pack; there is no retail-only Product, so every Product keeps its Tiers and the bulk order page,
the price calculator and the bulk Cart do not change at all.

Packs are gathered in a **retail Cart** of their own, kept in the browser beside the bulk Cart and
never merged with it. The one Checkout is opened for one Cart at a time, and its Capture makes an
ordinary **Order** — same number, Payments, Shipments, Texts and Reports — that is either bulk or
retail and stays so for life, even under a User's edits. A retail Order owes nothing to the Tiers or
the Delivery Bands: each line is its Pack Price times its count, with no quantity break, and it pays
one flat retail Delivery Charge set in Settings, waived above a threshold set beside it. Repricing a
retail Order reads today's Pack Prices and that charge; a Price Override on a Pack line never exceeds
the Pack's MRP.

The reason for keeping the two apart is that they are priced by rules that cannot share a Cart. Bulk
prices every line from the whole Cart's Kilograms (ADR-0031); a 250 g Pack has no Kilograms to
contribute and no Tier to fall in. Letting the two mix would mean deciding whether a Pack counts
toward a bulk Tier and which delivery rule a mixed parcel pays — both questions with no answer the
Merchant wants — and it would put the bulk side, which the Merchant asked to leave untouched, on the
path of every retail change.

## Considered options

- **One price pair on the Product** (`retail_regular_price`, `retail_sale_price`). Rejected: one
  Product is sold in several sizes, so the prices belong to each Pack, and the store already measures
  every discount from an MRP rather than a regular price (ADR-0031's note).
- **One Order holding Kilogram and Pack lines together.** Rejected for the reason above.
- **A separate retail Order system.** Rejected: Payment, Courier booking, Texts and Reports all work
  on Orders already, and nothing about retail needs them to work differently.
- **A separate retail Checkout.** Rejected: one form for everybody stays true, and a second copy
  would drift.
- **Per-channel switches, allowing retail-only Products.** Rejected for now: the bulk page would have
  to start filtering, and Tiers would stop being something every Product has.

## Consequences

- Capture, Repricing and the Quote check branch on the Order's kind; the bulk branch is today's code.
- Reports count both kinds unless narrowed to one, and Kilograms Sold counts a Pack by its weight.
- Tracking Moments report a Pack as its Product, so ad catalogues see one tea however it is sold, and
  each Moment carries which Cart it came from.
- A retail-only Product, if it is ever wanted, is a new decision that reopens the bulk page.
