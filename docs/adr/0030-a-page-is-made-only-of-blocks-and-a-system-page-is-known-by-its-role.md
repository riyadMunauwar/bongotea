# A Page is made only of Blocks, and a System Page is known by its role

A **Page** is a title, a Slug, a status, SEO fields and an ordered list of **Blocks**, stored as the
Filament Builder's JSON. There is no authored HTML and no shortcode. Each Block kind is one
definition with two halves — a Filament schema the Merchant fills in and a React component that
draws it — chosen from a fixed set. Content Blocks say what the Merchant typed (a hero carousel, a
strip of promises, reviews, an FAQ). Live Blocks do something (category panels, a product rail, the
bulk order form, the price matrix, the delivery chart) and store only headings and switches, never
figures: the server resolves their Products, Tiers and Delivery Bands into the Page's props on every
request, so no Page can quote a price that has since changed.

Three Pages are **System Pages** — home, bulk order and my orders — known by a nullable, unique
`role` on the row rather than by their title, Slug or a setting that points at them. A Page with a
role cannot be deleted and its role cannot change; its Blocks are rearranged and reworded like any
other's. The home page is what `/` serves. Everything that needs "the bulk order page" asks for the
role, so a renamed Slug breaks no link the system draws.

Products, Categories, search, the Checkout, the Order Complete Page, the Retry Page and the payment
addresses are fixed routes, never Pages: what they show comes from the thing they are about, so
nobody authors them. Their Slugs and prefixes are reserved and no Page can claim one.

GioPages was the opposite. A Page's body was a whole HTML document the Merchant wrote, so the
system had to expand shortcodes inside it (ADR-0001, ADR-0002), cache the result to disk and serve
it two ways (ADR-0004), inject the Pixel into markup it did not own (ADR-0017), and point the site
root at a cached copy of one chosen Page (ADR-0023). Bongo Tea is one store with one design, the
mockup. Free HTML cannot stay faithful to it, and a Block is the smallest thing the Merchant can
move without being able to break it.

## Considered options

- **Keep authored HTML with shortcodes for the live parts.** Rejected: every Page would be a fork of
  the design, and every shortcode would be a Block with worse editing.
- **Fixed templates with no editor.** Rejected: the Merchant runs campaigns — an Eid offer, a
  wholesale FAQ — and should not need a developer for one.
- **The home page chosen by a setting**, as the Front Page was (ADR-0023). Rejected: a setting can
  point at a draft or a deleted Page, which is why ADR-0023 needed a Placeholder. A role on the row
  cannot dangle.

## Consequences

- A new kind of Block is code — a schema and a component — and a developer's job. The Merchant
  chooses among kinds, never invents one.
- There is no static page cache. Every storefront response is rendered with Inertia's server-side
  rendering, and falls back to rendering in the browser when the SSR process is down, so a Node
  crash never takes the store offline.
- The Pixel is injected into the Inertia root document for every storefront response. ADR-0017's
  principle — injected, never authored — carries over; the cached document it was injected into
  does not.
- Menus link to Pages, Categories and Products by reference, not by typed address, so renaming
  something never leaves a Menu pointing nowhere.
- The install command creates the three System Pages and never overwrites one already edited.
