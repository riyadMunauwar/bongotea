---
paths:
  - 'resources/css/filament/admin/**'
---

# Admin

## The theme binds the collapsed sidebar width itself
Filament v4 prints `--collapsed-sidebar-width` from `collapsedSidebarWidth()` into the document but reads it nowhere in its own CSS, so a collapsed sidebar is only as wide as its contents. `sidebar.css` binds it on `.fi-sidebar:not(.fi-sidebar-open)` inside an `lg` variant — below `lg` that selector is the off-canvas drawer, not the rail. Changing the panel's collapsed width alone changes nothing without that rule.

Size the sidebar's insets against the rail, not the open sidebar: 3.5rem is the tighter constraint, and the widest control it holds is 2.25rem.
