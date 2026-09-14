# Stock is costed at weighted average, stamped twice and never recomputed

A unit's cost is the weighted average of what has been supplied at the moment the unit moves,
stamped onto the row that records the move and never recalculated afterwards. It is an integer in
minor units like `Product::price` and goes through the existing `Money` support class, so rounding
happens once, at the point of stamping, and is visible on the row that carries it. A Supply
recorded today can change what tomorrow's movements cost and can never change what last month's
margin said.

There are **two stamps**, and they answer different questions rather than competing. On the **Order
Line, at Capture**, sits *what this sale cost me*: it feeds margin, and it is taken at Capture
because `Sales` is counted at Capture — a cash-on-delivery Order has no other moment — so margin
and revenue are always counted over the same set of Orders and two screens can never give a
Merchant two different answers for the same span of days. It is stamped in
`OrderCapture::capture()`, beside the unit price the line already records and under the rule that
already lives there: nothing priced is trusted from the browser. On the **Stock Movement, at
Booked**, sits *what this box cost*: it feeds the ledger, the value of what is held, and the money
figure on an Abandoned parcel. The two are equal nearly always and differ only when a Supply lands
between Capture and dispatch, which is a real thing that happened and which each figure correctly
reports from its own side. They are not to be reconciled.

**Considered options.** FIFO was rejected on returns. This is a business where parcels come back
routinely, and a returned unit has no honest answer to which batch it rejoins: any answer is an
invention, and the invention compounds every time the same unit goes out and comes back. Weighted
average lets a **Return** re-enter stock at the cost stamped on the outbound movement it reverses —
the actual figure that left, not an approximation — which is the one costing question a
returns-heavy ledger has to get right. Recomputing the average backwards whenever a late Supply is
recorded was rejected because it silently rewrites every past margin report, which is the same
failure as a typed quantity field: it answers "how much" by destroying the ability to answer "why".
A single stamp was rejected in both directions: costing only at Booked leaves every Order still in
the queue with no cost against revenue `Sales` has already counted, and costing only at Capture
leaves the ledger and the loss on an Abandoned parcel with no figure of their own.

## Consequences

- **Unknown is not zero.** A Product with no known average cost stamps none, and its margin reads
  unknown rather than zero — a Product nobody has supplied must not present itself as infinitely
  profitable. This is the same choice On Hand makes against `0`, and the Courier figures before it.
- **Margin over a Reporting Window is `Sales` over the same window minus the stamped costs of the
  same Orders**, so the two agree exactly and by construction. A Cancelled Order drops out of both
  sides together, exactly as it drops out of `Sales` today.
- A Voided Supply reverses at the cost that was stamped, never at a recomputed one, which is what
  makes voiding restore the prior state exactly rather than approximately.
- The average itself is read from the ledger at the moment of stamping and is not a column anybody
  maintains. There is no cost field on a Product to go stale, and nothing to backfill.
- The two stamps disagreeing is expected and worth showing. A future reader finding an Order Line
  and its movement carrying different figures should not "fix" either: a Supply arrived in between.
