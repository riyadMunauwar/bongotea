# The admin panel has no topbar

Filament's topbar is switched off, and the sidebar owns the full height of the window. Read cold,
the panel configuration looks like someone forgot it: there is no strip across the top, and the
account menu and global search are somewhere other than where every other Filament panel puts them.
This is deliberate.

The topbar spent 4rem of every screen on a search field and an avatar. The screen that matters in
this product is the Orders queue, and vertical space there is rows — how much of a
cash-on-delivery day a User can see without scrolling. Filament relocates global search, database
notifications and the account menu into the sidebar on its own once the topbar is absent, so the
things the strip carried survive the strip.

Nothing else about the panel's appearance is recorded, here or anywhere. Radii, colours, density
and the typeface are cheap to reverse and self-evident from the stylesheet.

## Consequences

- There is no sticky header. Scrolled halfway down a long Orders queue, a User has nothing pinned
  at the top of the working area; the sidebar is what stays on screen, and it is navigation rather
  than context. This is the real cost, and it is paid on exactly the screen the decision was made
  for.
- Global search, notifications and the account menu are reached from the sidebar footer, including
  the dark mode switcher. On a phone that means opening the drawer first.
- Anything that would later want to live "at the top of the page" — a page-level action bar, a
  breadcrumb trail — has to be placed by the page rather than inherited from the shell.

## The runner-up, if the missing header starts to hurt

Reinstating the topbar and running the sidebar full-height beside it, rather than beneath it. That
keeps the sticky header and most of the vertical saving, at the cost of the silhouette the panel
has now. It remains a small change: `->topbar(false)` comes off, and the sidebar's own rules in
`resources/css/filament/admin/sidebar.css` are already written against the full window height. If
daily use on long queues proves the header worth having, this is the thing to reach for.

## This is not a decision about the Dashboard

The two were taken together and argued for together — both stripped default chrome so the panel
opened onto work — and the Dashboard half has since been reversed. It is back, as
`App\Filament\Pages\Dashboard` owning the panel root, so a glanceable entry screen is no longer
something this decision gives up.

They are recorded apart because only the topbar's saving is collected on every screen, every day,
against a cost paid on the one screen that matters most. The Dashboard's cost was a single screen
on the way in. What it is for, and why it ships carrying no widgets, is written on the class rather
than here.
