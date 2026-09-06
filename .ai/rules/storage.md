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
Laravel's serve route for a *private* local disk demands a signed URL that the checkout's `<img>`
does not carry — which is a 403 in development and a silent 404 in production, on pages spending
money on traffic.

A signature cannot be the fix, and this is the part worth understanding before changing anything
here. The checkout island is server-rendered into a static cached page, so its URLs are frozen at
cache-write time and may sit on disk for weeks. A signed URL expires and the image breaks again,
on a page nobody is watching. Anything that bakes a time-limited URL into a long-lived document
fails the same way.

Three things hold this together. Do not remove one without the others.

**Livewire's temporary uploads live on a disk of their own.** Every file an admin drags into any
Filament upload passes through Livewire's staging directory, including files they cancel and never
save. On a public root those are fetchable by anyone who learns the URL, and an unguessable name is
not a permission. `config/livewire.php` pins them elsewhere; if that file goes, they land back on
the default disk.

**`'serve'` is off on the `local` disk.** The `public` disk's URL and the local disk's serve route
both claim `/storage`. They are not both allowed to want it.

**`public/.htaccess` carries `+FollowSymLinks`.** Without it Apache refuses to traverse
`public/storage` and produces a second 403 that looks exactly like the first one and has nothing to
do with it.

## Anything new stored here is public the moment it is written
No error says so. A model that needs to keep something private — an export, an invoice, anything
belonging to one merchant — takes a different disk, and adding one is cheaper than discovering this
the other way round. `Product` is currently the only model in the application with a media
collection, which is what makes the trade above survivable rather than merely convenient.

## Changing where images live means flushing the page cache
Absolute URLs are baked into `public/page-cache/*.html` at cache-write time. A disk change, a URL
change or an `APP_URL` change reaches no visitor until the cache is flushed or the page re-saved.
A fix that "does not work" here has usually already worked, and is being read off a stale file.
