# Capture refuses what is not Free to Sell, and the ledger still moves only at Booked

Amends ADR-0021: its "the Checkout is not touched at all … no Page may refuse an Order for being out
of stock" is reversed. Everything else in it — movements written once per cause when a Shipment is
Booked, Returned or Abandoned, On Hand allowed below nothing, Committed drawn beside On Hand — stands.

The Merchant does not want a Customer able to order what is not there. So Capture checks each
Product's **Free to Sell** — On Hand less the weight on every Order that is neither Cancelled nor
already sent, New as well as Confirmed — and refuses an Order wanting more of any Product than that.
The check runs inside Capture's transaction with the Product's stock locked, so two Checkouts racing
for the last kilogram cannot both win. Bulk and retail are one pool (ADR-0035), so they contend for
the same grams.

Free to Sell is computed from Orders, never written as a Stock Movement. A cancelled or edited Order
frees or takes its weight the instant it changes, with no reversal to write and none to forget.

## Considered options

- **Gate on On Hand alone.** Rejected: On Hand drops only when a box ships, so every Order waiting
  for its call would be sold again.
- **A reservation movement at Capture.** Rejected for ADR-0021's reason: a large share of New Orders
  die on the confirmation call, and each would need a reversing movement, drifting the ledger.
- **No gate; warn staff at confirmation.** Rejected by the Merchant.

## Consequences

- A Product nobody has counted is not checked at all: unknown is not empty, and gating it would make
  every uncounted Product unorderable on the day this ships. Its first Stock Count brings it under
  the check, which keeps ADR-0021's adoption-a-few-at-a-time intact.
- Users are held to the same check at Capture and at Repricing, with the Order's own weight left out
  of its own check. A User with the Permission to sell beyond stock may pass it with a reason kept on
  the record, as with a Price Override: the phone Order against tomorrow's Supply is the case ADR-0021
  was protecting, and it stays possible for a named person, never for a Customer.
- A New Order holds stock until somebody cancels it, so a stale one costs sales; Free to Sell is
  drawn beside On Hand and Committed so this is visible.
- ADR-0021's argument that an oversold cash-on-delivery Order is a phone call, where a refused one is
  dead ad spend, is knowingly given up.
