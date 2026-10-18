# Delivery is charged by zone, drawn from District, with a table per kind

Supersedes the Delivery Bands half of ADR-0028 — "the same for the whole country" — and the flat
retail Delivery Charge of ADR-0034. The Tier half of ADR-0028 was already superseded by ADR-0031;
the one pricing module, the TypeScript mirror and the shared worked examples all stand.

A **Delivery Zone** is a named group of Districts the Merchant sets up. Bulk and retail each have a
list of zones of their own, shown in Settings as **Bulk Delivery Charges** and **Retail Delivery
Charges**, and the two never borrow from each other: one District may sit in differently drawn zones
for the two kinds. In each list every District belongs to exactly one zone, and one zone is marked
"everywhere else", so a District nobody listed still has one. Each zone holds its own Delivery
Bands — `{min, max|null, fee}` over whole kilograms from one upwards, without gap or overlap, the
last open-ended — and a retail zone may also hold a subtotal from which its delivery is free.

The Order's District decides its zone, and its total kilograms decide the band within it. A retail
Order's kilograms are its Packs' weights added up and rounded up to the next whole kilogram. Before
a District is known — the Cart page, the Cart bar, a price calculator — the storefront shows the
charge as the range across that kind's zones, never one zone's figure as though it were the price.
The Order records the zone's name and the band's range it was charged under, and a User changing
the District of an unsent Order reprices it.

The store started out charging by weight alone because that is what costs the courier and because
one price list nationwide was simpler than ADR-0005's per-Page options. In practice the couriers
the store uses charge more to leave Dhaka than to stay inside it, and a bulk sack and a retail
packet go out on different terms, so one national table either overcharged the city or lost money
on everywhere else.

## Considered options

- **A zone the Customer picks at Checkout** ("ঢাকার ভিতরে / ঢাকার বাইরে"). Rejected: it can
  disagree with the District, and a Customer who picks the cheaper zone for a door in the dearer one
  is a parcel the courier charges the Merchant for.
- **Inside Dhaka fixed as Dhaka District, everything else outside.** Rejected: Dhaka District holds
  Savar, Keraniganj and Dhamrai, which couriers price as suburbs, and a Merchant wanting a third zone
  would be waiting on a code change.
- **One shared list of zones, each with a bulk and a retail table.** Rejected: bulk and retail go
  out through different couriers on different maps, and a shared list would force one map onto both.
- **Gram-precise retail ranges** ("up to 500 g"). Rejected: a new shape beside the bulk bands for
  little gain, when couriers already bill by the kilogram rounded up.

## Consequences

- District now moves a figure, so Checkout must have it before it can show the exact charge, and the
  Quote checked at Capture includes the zone's charge.
- The settings form refuses a District in two zones of one kind, a list with no "everywhere else"
  zone, and any zone's bands with a gap or an overlap, so every Order has exactly one Delivery Charge
  and the pricing module may assume it.
- An installation carried over keeps charging exactly what it charged: today's bulk bands become the
  bulk "everywhere else" zone, and the flat retail charge and its threshold become the retail one,
  as a single 1 kg-and-over band. New zones are the Merchant's to add.
- The Delivery Chart Block is told which kind it draws and shows a tab per zone of it.
- A zone renamed, redrawn or deleted leaves captured Orders explaining their charge in the words
  they were charged under.
