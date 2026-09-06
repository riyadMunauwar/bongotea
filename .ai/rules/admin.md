---
paths:
  - 'resources/css/filament/admin/**'
---

# Admin

## The theme binds the collapsed sidebar width itself
Filament v4 prints `--collapsed-sidebar-width` from `collapsedSidebarWidth()` into the document but reads it nowhere in its own CSS, so a collapsed sidebar is only as wide as its contents. `sidebar.css` binds it on `.fi-sidebar:not(.fi-sidebar-open)` inside an `lg` variant — below `lg` that selector is the off-canvas drawer, not the rail. Changing the panel's collapsed width alone changes nothing without that rule.

Size the sidebar's insets against the rail, not the open sidebar: 3.5rem is the tighter constraint, and the widest control it holds is 2.25rem.

## Style Filament against the semantic tokens; never write dark variants in component rules
Every component rule draws its surfaces, borders and radii from the `--gp-*` properties in `tokens.css`. Dark mode is `:root.dark` redefining that one list — a dark variant written into a component rule turns dark mode from one edit into a second design maintained by hand, and stops a Filament component the panel does not render yet from inheriting correctly the first time it appears.

The partials are imported unlayered while all of Filament's CSS sits in `@layer components`/`utilities`, so theme rules win on cascade layer, not specificity. Never reach for `!important` or selector padding to override Filament here.

Shadows are banned on static surfaces. `components.css` clears `--tw-shadow` on `*` and hands it back only to a named list of things that float — dropdowns, modals, popovers, toasts. Add to that list rather than reintroducing a shadow locally, and separate anything static with a hairline instead. Clear `--tw-shadow`, not `box-shadow`: Tailwind composes rings and shadows into the same property, so clearing `box-shadow` would take the hairlines with it.

## The one rule here that a PHP file has to agree with
`forms.css` dims a switched-off question row on the Checkout tab by `:has([data-gp-question-asked][aria-checked='false'])`. That attribute is `Questions::ASKED_MARKER`, written onto one toggle in `app/Filament/Resources/Pages/Schemas/Checkout/Questions.php`, and `aria-checked` is Alpine's — nothing in the rendered HTML records that the two belong together. `PageAuthoringTest` pins the pair; if you rename or move either half, fix both. See `.ai/rules/checkout.md` for why the row needs saying at all.
