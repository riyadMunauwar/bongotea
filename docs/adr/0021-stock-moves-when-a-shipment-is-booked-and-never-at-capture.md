# Stock moves when a Shipment is Booked, and never at Capture

> Amended by ADR-0037: Capture now refuses an Order wanting more than a Product's **Free to Sell**.
> Movements still happen only at Booked, Returned and Abandoned.

A Stock Movement is written when a parcel physically changes hands and at no other moment. Stock
leaves when a Shipment reaches **Booked** — a Courier has taken the box — comes back when one is
**Returned**, and is written off when one is **Abandoned**. A Shipment **Awaiting Dispatch** moves
nothing, because nobody has been handed anything yet; a Shipment **Refused at Booking** moves
nothing, because it never left; and a **Partly Delivered** parcel moves nothing on its own, because
the Courier reports a collected amount and never which items came back, so the system structurally
cannot know. Capture moves nothing, Confirmed moves nothing, and a Payment moves nothing. The
Checkout is not touched at all: no stock gate, nothing in the Pricing Fingerprint, and no Page may
refuse an Order for being out of stock.

The reason is the shape of the Order queue this system was built for. On cash-on-delivery traffic
bought from ad platforms, a large share of `New` Orders are wrong numbers, duplicates and people
who change their mind on the confirmation call — which is exactly why `OrderStatus` means *has
anyone phoned this Customer* (ADR-0018) and why Cancellation Rate is drawn as a figure a Merchant
watches. A ledger that deducted at Capture would be pessimistic by however many dead Orders are
sitting in that queue at any moment, permanently and invisibly, and every one of those deductions
would later need reversing. Deducting at Booked means every row in the ledger corresponds to a box
that really left the building, which is the property that makes the ledger settleable in an
argument with an operator, a Supplier or a Courier.

**Considered options.** Deducting at Capture was rejected for the reason above, and because it puts
the ledger on the checkout path: the moment stock is decided at Capture, somebody will ask the
Checkout to refuse an Order for being out of stock, and an oversold cash-on-delivery Order is a
phone call where a refused one is dead spend on traffic already paid for. Reserving at Confirmed —
writing a movement when an operator confirms — was rejected because confirming is a decision about
a Customer, not an event in a warehouse: nothing has happened to a box, and a reservation that is
also a movement makes On Hand mean two things at once. What a Confirmed Order actually justifies is
**Committed**, which is computed from Orders, drawn beside On Hand, and never taken off it.

## Consequences

- **The ledger is idempotent per cause.** A Shipment reaching `Booked` writes its movements exactly
  once however many times a poll, a webhook or a save re-asserts the same status. ADR-0019 makes a
  poll and a webhook reporting the same transition at once normal operation rather than an edge
  case, so a ledger that wrote per save rather than per cause would drift downward under precisely
  the traffic the courier layer was designed for — the same deduplication `ShipmentEvent` already
  enforces at the database.
- **Movements are written from a `ShipmentObserver` rather than from explicit calls**, and it
  watches `abandoned_at` as well as `status`, because Abandoned is a time and a name and never a
  status (ADR-0018) — a Loss would otherwise be invisible to anything watching the status column,
  and Refused at Booking, which is the other time-and-a-name, needs nothing watched because it
  moves nothing. Status alone is written from four places today — `CourierBooker` twice,
  `CourierDispatcher` and `CourierPoller` — and with `Shipment::abandon()` that is five explicit
  call sites, which is five places the next Courier path can forget. One module, `StockLedger`
  under `App\Support\Inventory`, is the only thing that writes a movement.
- **What a parcel owes after it leaves is read off the movements it already wrote**, never off the
  Order it was for. A Return is the exact opposite of the Sale it answers, at the figure stamped on
  it (ADR-0022), which is what makes it right in all three ways at once: a Product nobody had
  counted has nothing to bring back, the number that comes back is the number that left however the
  Order has been edited since, and a Supply that arrived while the parcel was out cannot revalue
  units on a round trip. **Abandoning writes an Unsold and a Loss together**, and both are meant: the
  units left the shelf once, at Booked, so a write-off that only deducted would take the same box
  twice and leave On Hand below what is on the shelf for good. The pair moves On Hand by nothing —
  nothing has happened to a box that had not already happened — and what it adds is the name. The
  put-back is an Unsold rather than a Return for a reason the one-cause-one-movement key enforces: a
  parcel written off and *then* brought back by the Courier owes a Return as well, and a Return
  already spent on the write-off would swallow it as a second telling and leave the units off the
  shelf for good. A parcel the Courier has finished with is not one to write off at all, whatever a
  column says: it was delivered or it came back, and either way what became of the units is already
  on the record.
- **A Partly Delivered parcel raises a Needs Attention item** and holds its units as a question
  until a User names the lines that came back. Treating it as fully delivered was rejected: it
  designs a known defect into the one figure whose entire value is that people believe it, and it
  resurfaces months later as unexplained shrinkage in the number built to detect theft and damage.
- **On Hand is allowed to go negative**, and is drawn loudly when it does. A parcel can really be
  booked for stock nobody recorded arriving, and the honest reading of that is a figure below
  nothing that asks to be explained — not a movement refused at the moment an operator is sending a
  real parcel.
- **Nothing is written retroactively.** Orders and Shipments predating the ledger produce no
  movements, and a Product produces none until its first Stock Count. A Product nobody has counted
  reads `—` and a Shipment for it moves nothing, which is what makes adoption five Products at a
  time rather than a migration.
- An Order Cancelled while its parcel is still moving has still moved stock, and a Cancelled Order
  that is later delivered has too: the ledger records boxes, and cancelling is a decision about a
  Customer. Its **margin** is a separate question and drops out on both sides at once (ADR-0022) —
  the ledger and the money answer different questions about the same Order. The two axes do not
  write each other here any more than they do anywhere else (ADR-0018).
