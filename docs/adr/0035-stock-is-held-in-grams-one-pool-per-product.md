# Stock is held in grams, one pool per Product

The stock ledger counts **grams**, not whole Kilograms. Every Stock Movement, Supply line and Stock
Count records a Product's quantity in grams, and On Hand, Committed, Value at Cost and Days of Cover
are summed in grams and drawn in kilograms. There is one pool per Product: a bulk Order Line ships
its Kilograms × 1000 and a retail Order Line ships its Pack's weight × its count, and both leave the
same shelf. A Pack has no stock of its own. The weighted average cost (ADR-0022) is carried per gram,
so a Pack line's cost is stamped the same way a Kilogram line's is. Existing rows are converted by
multiplying by 1000; the stamped costs are converted so that no Order's margin moves.

The reason is how retail is fulfilled: Packs are weighed out of loose stock when an order is packed,
not bought or packed ahead. The ledger exists to answer "how much Assam do we have?" truthfully, and
with retail that answer is a weight that is no longer a whole number of kilograms. Integer grams keep
the ledger exact — a 250 g Pack is 250, not 0.25 — without decimals that a sum could drift on.

## Considered options

- **A stock item per Pack, counted in packs**, with repacking recorded as a movement (−5 kg loose,
  +20 × 250 g). Rejected: the Merchant packs to order, so there is no stock of sealed Packs to count,
  and a repacking step would be a chore nobody does — leaving both figures wrong.
- **Decimal kilograms.** Rejected: the same meaning with rounding in every sum; grams are the smallest
  unit anything is sold in.
- **Leaving retail out of the ledger.** Rejected: On Hand would quietly overstate every retail tea.

## Consequences

- Every screen and report that reads a ledger quantity has to divide by 1000 to show kilograms, and
  the Supply and Stock Count forms still take kilograms from the Merchant.
- If the Merchant ever buys pre-sealed retail packs, they are a new decision; this one assumes
  packing to order.
