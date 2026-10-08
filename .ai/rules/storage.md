---
paths:
  - 'config/filesystems.php'
  - 'config/livewire.php'
  - 'app/Models/Product.php'
  - 'app/Filament/Resources/Products/**'
---

# Storage

## The `local` disk is public on purpose, and its name is the lie
`config/filesystems.php` declares `'visibility' => 'public'` on a disk rooted at `storage/app/private`.
That reads like a mistake and is not one (ADR-0011). A Product's image collection lands there, and
Laravel's serve route for a *private* local disk demands a signed URL that a storefront `<img>`
does not carry — which is a 403 in development and a silent 404 in production, on pages spending
money on traffic.

A signature cannot be the fix, and this is the part worth understanding before changing anything
here. A product image's URL ends up in shared links, link previews and caches that outlive any
expiry, so a signed URL breaks the image again on a page nobody is watching. Anything that bakes a
time-limited URL into a long-lived document fails the same way.

Three things hold this together. Do not remove one without the others.

**Livewire's temporary uploads live on a disk of their own.** Every file an admin drags into any
Filament upload passes through Livewire's staging directory, including files they cancel and never
save. On a public root those are fetchable by anyone who learns the URL, and an unguessable name is
not a permission. `config/livewire.php` pins them to the `staging` disk — which has no URL, no
visibility and no link — and that one line is the only reason the file is published at all. If it
goes, they land back on the default disk, which is the public one.

Moving them left `storage/app/private/livewire-tmp` behind on every installation that predates the
change: published by the link, and no longer swept, because Livewire's cleanup only walks the disk
it is currently writing to. `deploy/README.md` deletes it before the link is made. Any future move
of a directory off this disk carries the same obligation — the old copy stays public and stops
being anybody's to tidy.

**`'serve'` is off on the `local` disk, and `/storage` is the link's.** The `public` disk's URL and
the local disk's serve route both claimed `/storage`. They are not both allowed to want it, so the
route went and the address became a file the webserver reads: the `local` disk carries a `'url'`,
and the one entry in `filesystems.links` points `public/storage` at that disk's root.

There is no `public` disk any more, and putting Laravel's back is the trap. It is rooted at
`storage/app/public` and addressed at `/storage` — including by the framework's fallback when the
disk declares no URL at all — so a file written to it does not 404, it resolves to whatever the
`local` disk has at that path, which is a Product's image. Serving the wrong picture is worse than
serving none. Anything that wants a public address takes the `local` disk; `Storage::disk('public')`
raises, which is the intended answer.

This is also why `Product` names its disk on the collection rather than accepting a default: the
panel's upload falls back to the framework's default disk and the media library's own falls back to
`public`, and those two were agreeing by accident.

**`public/.htaccess` carries `+FollowSymLinks`.** Without it Apache refuses to traverse
`public/storage` and produces a second 403 that looks exactly like the first one and has nothing to
do with it.

## Anything new stored here is public the moment it is written
No error says so. A model that needs to keep something private — an export, an invoice, anything
belonging to one merchant — takes a different disk, and adding one is cheaper than discovering this
the other way round. `Product` is currently the only model in the application with a media
collection, which is what makes the trade above survivable rather than merely convenient.
