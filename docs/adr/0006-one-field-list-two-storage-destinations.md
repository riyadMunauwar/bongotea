# One field list, two storage destinations

A Checkout's fields are a single ordered list. The built-in ones the system knows by name — name,
phone, email, address, district — and the Merchant's own sit in it together, are reordered together,
styled the same way and validated the same way. What differs is one property of a Checkout Field:
where its answer goes. A built-in's answer goes to the column that has always held it; one of the
Merchant's own goes to the Order's snapshot, with its label and type recorded beside the value.
Storage is a property of a field, not a second system with its own list.

This is the reasoning ADR-0002 already applied to Shortcodes and Blocks, reached from the other end.
The tempting shape is two lists — the known fields, then the Merchant's own bolted alongside —
because that is how the two arrived. It means writing ordering, styling, validation and presentation
twice and keeping them in step forever, and every one of those is a thing a Merchant expects to work
identically on both. They would drift on the first feature that touched only one list.

## Consequences

- A Merchant can place their own field between two built-in ones, because position is a property of
  the list rather than of which kind of field it holds.
- A built-in field carries an immutable key and type. It can be relabelled and switched off, never
  deleted or retyped, because a column is waiting for it and nothing else may be written there.
  Phone has no off-switch at all: a cash-on-delivery Order nobody can call is not fulfillable, and
  a Customer is their phone number. So the list is uniform in how a field behaves, not in what may
  be done to one.
- Enabled and required are independent. A required field that is switched off is not a broken
  configuration, so experimenting with a shorter form does not throw away what was configured
  behind it.
- The snapshot is what makes relabelling safe. A Merchant A/B-testing wording across a campaign —
  the thing a landing-page system exists for — would otherwise retroactively relabel every Order
  already taken, and a deleted field would leave older Orders unreadable.
- District renders as an ordinary field of the list whose choices come from installation
  configuration, and Delivery Options render as an ordinary field fed by the Page. Neither is a
  special case in the form.
- Two answer shapes have to be read on one Order screen: columns and a snapshot. That screen reads
  the Order alone and never the Page, which is what keeps history correct once the Config moves on.

## The alternatives, and why not

The Merchant's own fields as a separate feature appended after the built-ins. Cheapest to build and
the one a reader will assume was chosen. It fixes the field's position for the Merchant, splits the
styling and validation models in two, and gives the same word two meanings depending on which list
it is in.

Putting every answer in the snapshot, including the built-ins. Tempting for its symmetry. But the
built-ins are the Order — the name, number and address a User reads to work it and a courier is
given — and they have had columns since before any of this, so the change would be moving settled
data into a serialised blob to buy uniformity the field list already has without it.
