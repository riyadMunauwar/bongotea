# Courier status is its own axis on an Order, and a Shipment is one attempt

A parcel's progress is a **Shipment** hung off an Order — one per attempt, carrying its own
Consignment, its own charge, its own recipient details as sent, and its own account of what
happened — rather than a state the Order passes through. An Order holds as many Shipments as it
took, and at most one is Active; a parcel that came back and was sent again is two, because the
first attempt cost money whatever the second one does. `OrderStatus` stays New, Confirmed,
Cancelled and says only what the operator decided. Whether a parcel moved is answered by the
Shipments, never by the status, and neither ever writes the other — the same separation ADR-0013
drew between a Payment's outcome and the operator's decision, applied a third time.

Every Courier's own status vocabulary is translated into one canonical set — Booked, In Transit,
On Hold, Delivered, Partly Delivered, Returned, Unknown — on the driver and nowhere else, with the
Courier's raw word kept beside it and shown verbatim in the timeline. **Steadfast and Pathao both
say `cancelled` for a parcel that came back, and it maps to Returned.** Nothing a Courier says may
ever produce a word resembling the Order's own Cancelled: a returned parcel is a question a User
answers, not an answer a courier in Dhaka gets to give.

**Considered options.** Extending `OrderStatus` with the tail its own doc comment anticipated —
packed, shipped, delivered, returned — was rejected on two counts. A single enum cannot express
"returned once, now out for delivery again", which is a weekly occurrence on cash-on-delivery
traffic and the whole reason attempts are separate records. And New and Confirmed mean *has anyone
phoned this Customer*, which would become unreachable the moment a parcel moved. Auto-advancing
`OrderStatus` from a courier's outcome — delivered sets Confirmed, returned sets Cancelled — was
rejected because Cancellation Rate is drawn as its own figure precisely so that cancelling moves a
number a Merchant watches, and an operator who rings a Customer, corrects the address and re-sends
would otherwise have spent four hours with the sale counted as cancelled. Overwriting a Shipment on
re-send was rejected because it discards what the failed attempt was charged, and a Merchant paying
return fees can then never see what returns cost.

## Consequences

- An Order can be Cancelled by a User while its parcel is still moving, and a Cancelled Order can
  be delivered. Both are allowed and neither overwrites the other, exactly as a Paid and Cancelled
  Order is allowed today.
- A Returned Shipment changes nothing by itself. It raises a **Needs Attention** item — the to-do
  list that ignores the Reporting Window — and a User decides between cancelling and re-sending.
- **Abandoned is a time and a name on a Shipment, never a status.** A Shipment On Hold that a User
  writes off records both, where a status overwritten would record only that somebody gave up, and
  the fact that the Courier never resolved it is worth keeping.
- A canonical state a given Courier never emits is expected, not a defect: Steadfast never reports
  In Transit and RedEx never reports Unknown. Courier figures are therefore always broken down by
  Courier, and no blended cross-courier funnel is drawn, because that is the one view in which the
  asymmetry would read as fact.
- The invoice reference sent to a Courier is scoped to the attempt, not the Order. Steadfast
  requires invoice uniqueness, so a re-send reusing the Order number would be refused at exactly
  the moment a User is rescuing a returned Order; and all three couriers echo the invoice back, so
  it must resolve to one Shipment rather than to an Order with several.
- A Shipment snapshots the recipient details it was actually sent with. Correcting an address in
  the send dialog writes to the Order as well, so the Order stays current truth, while each attempt
  keeps what it was told — attempt 1 and attempt 2 can and should differ.
- Shipments and Shipment Events are kept indefinitely, like Orders. They are fulfilment data
  holding no browser identifier, which is why Tracking Context is pruned and these are not.
