# The Cart lives in the browser, and Capture checks the Quote it is sent

The **Cart** is kept by the Customer's browser and nowhere else — `{productId: kilograms}` in
`localStorage` — and the store knows nothing about it until Capture. The bulk order form, the price
calculator, every product card's stepper and the header all read and write that one store, so there
is never a second quantity that could disagree with it. Every figure the Cart shows is worked out in
the browser from the Tiers, Delivery Bands and Product prices the page was sent (ADR-0028).

The Checkout submits the Cart together with its **Quote**: each line's Tier Price, the Tier, the
Delivery Charge and the total the Customer was looking at. Capture prices the lines again with the
pricing module and compares. Any disagreement — a price moved, a Product no longer Available or no
longer published — refuses Capture and answers with the figures as they are now, for the
Customer to look at and submit again. Nothing is silently corrected. The store's own figures are the
only ones that count; the Quote exists to be compared, because the total on the Customer's screen is
the total the courier collects, and a surprise at the door is a refused parcel.

This replaces the Pricing Fingerprint (ADR-0007). The fingerprint was a hash of the figures baked
into a cached GioPages Page, re-checked at Capture, and its only possible answer was "something
changed, reload". It was the right shape for HTML that could sit on disk for weeks. The problem it
answered has not gone — a tab opened on Monday is still submitted on Wednesday — but a hash cannot
say *what* changed, and a reload would leave the Customer to find out for themselves. The Quote
carries the figures themselves, so the refusal can show the old total beside the new one while the
Cart is left exactly as it was.

## Considered options

- **A Cart held on the server**, in the session or a table. Rejected: nobody signs in to order, so
  it would be one row per visitor for no reader; it would not survive the browser being closed any
  better than `localStorage` does; and Capture would still have to price it afresh, since a stored
  price is exactly the stale figure the check exists to catch.
- **Correcting to the current price and capturing anyway.** Rejected for the reason above: the
  Customer agreed to a total, and the courier is about to ask for it.
- **Keeping a fingerprint beside the new Cart.** Rejected: it duplicates the Quote's job with less
  to say.

## Consequences

- The Cart belongs to a browser, not to a Customer. Signing in on another phone does not bring it
  along, and the store has no record of a Cart that was never checked out; the Added to Cart Moment
  is the only trace one leaves.
- The comparison is exact, in whole taka, line by line and in total. A rounding difference between
  the PHP module and its TypeScript mirror is a refused Capture, which is why the shared worked
  examples are a contract and not a convenience.
- Capture takes cart lines and delivery details and nothing from any Page. The Checkout is one fixed
  form for the whole store, so the per-Page field list (ADR-0006), the pre-ticked Picker
  (ADR-0010), the Checkout Layouts (ADR-0009, ADR-0015) and the Container (ADR-0027) go with the
  page they belonged to.
- Stock never refuses a Quote: Available is the Merchant's hand-set flag, and the stock
  ledger still moves only when a Shipment is Booked (ADR-0021).
