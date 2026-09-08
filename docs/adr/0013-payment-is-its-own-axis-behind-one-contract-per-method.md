# Payment is its own axis on an Order, behind one contract per method

An Order comes into existence at Capture whatever the Payment Method, and an online payment is
a separate record hung off it — a Payment, one per attempt, ending Paid, Failed, Cancelled or
Expired — rather than a state the Order passes through. `OrderStatus` stays New, Confirmed,
Cancelled and says only what the operator decided; whether money moved is answered by the
Payments, never by the status. Every Payment Method, Cash on Delivery included, is one class
behind one contract: what credentials it needs, how it starts a Payment and where to send the
Customer, how it reads the Customer's return, how it verifies a Payment by asking the gateway,
and how it reads a Notification. Adding a method is one class and one enum case.

**Considered options.** Creating the Order only once the gateway confirms was rejected because
it needs a second "almost an Order" type that repeats every snapshot rule, and because an
abandoned payment is a lead worth a phone call rather than something to lose. Folding payment into
`OrderStatus` (New, Paid, Confirmed, Cancelled) was rejected because a paid Order still needs the
confirmation call that checks the address, and because a Payment that completes after the operator
cancelled the Order would then have to overwrite a decision the operator made. A merchant-defined
list of gateway rows was rejected in favour of a fixed set known by name: a Merchant has one bKash
account, and a Page's list of names survives a Settings row being wiped.

## Consequences

- A Paid Order can be Cancelled and a Cancelled Order can become Paid. The admin surfaces "paid
  but cancelled" as its own filter; refunds are the operator's, and out of scope for now.
- Nothing the gateway puts in the Customer's return URL marks a Payment Paid. Only a Verification
  the system makes does, from the Processing Page, a Notification, or the sweep.
- A Payment records the amount it was for. Today that is always the Order's total; an advance or
  delivery-charge-only payment later is a Payment for a smaller figure and no change of shape.
- The chosen method is not in the Pricing Fingerprint. A method that vanished under a live form
  captures the Order Unpaid and sends the Customer to the Retry Page, rather than refusing them.
