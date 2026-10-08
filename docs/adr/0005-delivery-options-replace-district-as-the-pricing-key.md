# Delivery Options replace District as the pricing key

> **Superseded by [ADR-0028](0028-the-tier-is-chosen-by-the-whole-carts-kilograms-from-one-store-wide-table.md).** The Delivery Charge is read from store-wide Delivery Bands by total Kilograms.

Delivery pricing keys off Delivery Options — label-and-amount pairs a Merchant authors on each Page
— and no longer off District. None means no delivery row and no charge; one is added to the total
with no picker; two or more are offered as a choice, and the Order records the chosen label beside
the amount. District survives the change as an ordinary optional field that settles no figure.

The old model could not express what these Pages actually sell. One flat charge covered a whole
installation, so "Inside Dhaka 60, Outside Dhaka 120" had nowhere to live and free delivery had to
be faked as a zero. Making the amount a property of an option the Merchant names carries all three
cases in one shape, and keeps the label the Customer agreed to — which is what a User working the
Order needs in order to explain the figure at the door.

## Why District is kept anyway

It has stopped earning its place at Capture and is retained for something not yet built. A courier
API wants a structured region, and "Mirpur 10, Dhaka" typed into an address box cannot be sorted
into one a year later; nothing else on the Order carries it. The data is collectable only while the
Customer is on the form, so retaining the field is the only version of this decision that leaves the
courier integration possible.

## Consequences

- District is now switch-off-able like any other optional field, and the Merchant who switches it
  off loses the region data with nothing telling them so, because nothing today reads it. That is
  the cost this decision accepts: the reason the field exists is one no Merchant can currently feel.
- Delivery charges are per Page, and are edited Page by Page, for the reason ADR-0008 records for
  the Checkout Config as a whole.
- The Order states the arrangement in the Merchant's own words, not a district name a reader has to
  map back to a price band that has since changed.
- Delivery amounts are part of what the courier collects, so they are fingerprinted into the
  rendered form and re-checked at Capture (ADR-0007).

## The alternatives, and why not

Grouping Districts into priced tiers. It preserves District's role and looks like the smaller
change, but it makes a Merchant map sixty-four Districts into bands before selling anything, and it
still cannot express the common case: one charge, added directly, with no question asked. A Page
selling to one city would be configuring a national table to reach a single number.

Dropping District entirely. Nothing forces a Page to ask for it any more, so keeping it costs a
Merchant nothing they cannot switch off, while removing it forecloses the courier integration and
throws away the only structured region the system will ever have had the chance to collect.
