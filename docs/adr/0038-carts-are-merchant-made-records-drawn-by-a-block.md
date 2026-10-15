# Carts are Merchant-made records drawn by a Block, and a bulk line is one Product in one Pack

Supersedes the parts of ADR-0030 that make the bulk order page (and the packet basket page that
followed it) a **System Page**, and the parts of ADR-0034 that give a browser exactly one bulk Cart
and one retail Cart, with one Pack per bulk line. Everything else in both stands: a Page is still
made only of Blocks, home and my orders are still System Pages known by their role, and an Order is
still bulk or retail for life.

A **Cart** is now a record the Merchant makes in the admin: a name, a kind (bulk or retail, fixed
once made), an on/off switch, a sort order, and the Products assigned to it. Assignment is stored
per Product; picking a Category is a form shortcut that ticks what it holds at that moment, so a
Cart never changes behind the Merchant's back. Only a Product sold the Cart's way can be assigned,
and assignment is a shortcut for laying out a page, never a fence on what a Customer may add. A
deleted Cart keeps its row, so Orders and Contents still read its name, and every Order records its
Cart and a copy of the name it bore at Capture.

A Cart is drawn by a **Cart** Block placed on an ordinary Page, at most one, so its link is that
Page's path. Nothing in the store links to "the" bulk or retail Cart any more: every link — the
header's two buttons, a Price Calculator, the Cart bar, Checkout — names the Cart it opens. The
store opens with two, খুচরা অর্ডার করুন and পাইকারি অর্ডার করুন, which are ordinary Carts in every
other way.

The browser keeps **Contents** per Cart, keyed by its id, and never lets two meet: each is checked
out alone as one Order, priced from its own Kilograms or Packs. A bulk line is one Product in one
পাইকারি Pack, so a buyer can take some of a tea in 1 kg Packs and some in 500 g Packs in one Order;
however a Product's Kilograms are split between Packs, they are one Product's Kilograms to its Tier
and Order Limit.

The reason is that the Merchant wants themed places to order — an Eid gift Cart, a hotel and
restaurant Cart — each with its own link and its own hand-picked teas, and two Carts fixed in code
cannot be that. Making a Cart content rather than a role lets the Merchant open and close them like
any other content, while the pricing rules stay exactly the two ADR-0034 settled.

## Considered options

- **Keep two fixed Carts and add filtered views of them.** Rejected: one set of Contents shown on
  several pages would mix teas from different themes into one Order, and its totals would not be the
  totals of the page the Customer stands on.
- **A Cart that follows a Category live.** Rejected: a tea added to a Category would appear in a
  Cart nobody chose to put it in.
- **Restricting a Cart to its assigned Products.** Rejected: a Customer adding a tea from its own
  page should never be told no for a reason that is only about layout.
- **A Cart standing on several Pages.** Rejected: every link to a Cart should lead to one place.

## Consequences

- The bulk order and packet basket System Page roles are retired; the two Pages become ordinary.
- Checkout and Capture name a Cart by its id and read the kind from it; a closed Cart (switched off,
  on no Page, or deleted) is refused.
- Orders captured before Carts existed are counted under the starting Cart of their kind.
- A bulk Order may repeat a Product in different Packs; Repricing measures the Product's Kilograms
  across all its lines.
- Delivery stays per kind, never per Cart: the Delivery Bands for bulk, the flat charge for retail.
