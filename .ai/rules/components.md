---
paths:
  - 'app/Filament/Forms/Components/**'
---

# Components

## LayoutCards is Filament's Radio with a thumbnail slot; keep its markup a superset of Radio's
`LayoutCards extends Radio` and overrides `setUp()` (the grid columns) and `toEmbeddedHtml()`, the latter copied from Filament's and adding a `.gp-layout-card-thumbnail` span per option, so state, validation and `wire:model` stay Filament's. Keep `fi-fo-radio`, `fi-fo-radio-label`, `fi-radio-input` and `fi-fo-radio-label-text` on the markup so the panel's own radio styling still applies; `gp-layout-cards` (forms.css) only adds the grid and the card. A Filament upgrade that changes `Radio::toEmbeddedHtml()` has to be mirrored here — diff against vendor when bumping. Thumbnails are static files under `public/`, passed as `thumbnails([value => path|null])`; an option with `null` keeps an empty slot so the grid holds its shape. A picture is a 240×160 SVG drawn in the design's own colours (grey ground, white card, `#2451d6` accent, `#0f1d4f` navy for Dark panel), one rectangle per element the layout arranges. `PageAuthoringTest` pins ten `gp-layout-card-thumbnail` slots on the edit screen and the pictures of the layouts drawn so far (Stacked, Split, Split reversed, Dark panel, Bento grid, Compact embed, Chips) inside them, and that every path the enum names exists under `public/`.
