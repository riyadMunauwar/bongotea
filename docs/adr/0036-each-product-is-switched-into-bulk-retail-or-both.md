# Each Product is switched into bulk, retail or both, and every Product comes in Packs

Supersedes ADR-0034's "there is no retail-only Product". Its retail Cart, one-kind-for-life Order,
flat retail Delivery Charge and Pack pricing stand.

A Product carries two switches of its own, **পাইকারি** and **খুচরা**, at least one always on. A
Product sold পাইকারি has Tiers and is listed by the bulk side — the bulk order form, the price
calculator, the price matrix and the পাইকারি steppers on cards. A Product sold only খুচরা has no
Tiers and appears on none of them. Capture and Repricing refuse a Product on an Order of a kind it is
not sold as.

A **Pack** is no longer a retail thing. It is a sealed size the Product reaches any Customer in: a
bulk line names the Pack its whole Kilograms are supplied in and records the packet count they make,
and a quantity the Pack cannot make exactly is refused, never rounded. The Pack never prices a bulk
line — the Tiers still price it from the whole Cart's Kilograms (ADR-0031). So every Product needs at
least one Pack before it can be Available, and only a Pack sold retail needs an MRP and a Pack Price.

The reason is that "open to retail exactly when it has a Pack" stopped being true the moment bulk
lines were packed in Packs: a bulk-only Product now has Packs too, so something explicit has to say
which ways a Product is sold, and the Merchant wants retail-only teas.

## Considered options

- **Keep inferring retail from Packs** (e.g. "retail when any Pack has a Pack Price"). Rejected: a
  price typed for one Pack would silently open a whole Product to a new kind of Customer.
- **Bulk stays loose, Packs stay retail-only.** Rejected: wholesale buyers receive sealed packets,
  and the Order should say which and how many.
- **Fractional bulk Kilograms** (2.5 kg in 25 g Packs). Rejected: every Product's Tiers, the Delivery
  Bands and Kilograms Sold are whole kilograms, and common Pack weights divide a kilogram anyway.

## Consequences

- The bulk side filters by the পাইকারি switch in one place, which ADR-0034 named as the cost of this.
- Tiers are required only of a Product sold পাইকারি.
- Each Pack is ticked for পাইকারি, খুচরা or both, within its Product's switches, for the same reason
  the Product is: a Pack Price typed must never be what opens a Pack to retail. The bulk Pack picker
  offers only পাইকারি Packs, the retail Cart only খুচরা ones.
- The store had not gone live when this was decided, so no data is carried over: the installer's
  demo store and the factories give every Product its switches and Packs, and a fresh install is the
  only kind there is.
- A switch and Available are separate: Available is for now, the switches are how a Product is ever sold.
