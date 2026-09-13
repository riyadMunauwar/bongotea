---
paths:
  - 'app/Filament/Resources/Shipments/**'
---

# Shipments

## The timeline is written once and drawn on both screens
`ShipmentTimeline::entries()` is the only reading of a parcel's history, drawn on `ShipmentInfolist` and again per attempt in `OrderDocument::shipments()` — never copied, or a parcel's history gets told two ways two clicks apart. `couriersOwnWord()` is the same for the raw status.

Whose time an entry carries goes in the VALUE, not the label: a `table()` repeatable hides every per-row label from `@xl` up (`filament/infolists/.../repeatable.css`), so a dynamic `->label()` is invisible on the screen this is read on — and `assertSeeText` still passes on it, so a test cannot catch it.

`ShipmentResource` is read-only on purpose: no create page, no edit page. A Shipment exists because a Courier accepted a parcel, and every field is what that Courier was told or has since said. `invoice_reference` is `{order number}-{attempt}`, so searching it finds an Order's parcels by the Order's own number with no join.

`OrderDocument::shipments()` must `loadMissing('events')` — the nested timeline is one query per attempt otherwise.
