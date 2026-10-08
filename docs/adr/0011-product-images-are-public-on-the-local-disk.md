# Product images are public, on the disk they already sit on

> **Still in force for Bongo Tea, on different grounds.** The static page cache and the Checkout island are gone ([ADR-0030](0030-a-page-is-made-only-of-blocks-and-a-system-page-is-known-by-its-role.md)), so the cache-flush consequence no longer applies, but images still need unsigned, long-lived URLs because shared links and Open Graph previews outlive any signature. Revisit when the Product gallery is built: moving it to the `public` disk, the alternative below, would supersede this.

Media uploaded to a Product's image collection lands on the `local` disk, and that disk is now
declared `'visibility' => 'public'`. Laravel registers a serve route for a local disk with
`'serve' => true`, and for a disk whose visibility is private that route demands a signed URL. The
Checkout island's `<img>` carries none — which is why product images returned 403 in development
and, far worse, a silent 404 in production on Pages that were spending money on traffic.

A signature cannot be the fix. The island is server-rendered into a static cached page, so its URLs
are frozen at cache-write time and may sit on disk for weeks. A signed URL expires and the image
breaks again, on a Page nobody is looking at. Signed delivery and the static cache of ADR-0004 are
structurally incompatible, and anything that bakes a time-limited URL into a long-lived document
will fail the same way.

## What compensates

The disk's root is named `private`, so a future reader will reasonably expect it to be, and this
decision makes that name misleading. Two things keep it from becoming a lie in practice. `Product`
is the only model in the application with a media collection, and nothing else in `app/` writes to
that disk. Livewire's temporary uploads — every file an admin drags into any Filament upload,
including ones they cancel and never save — are moved off it onto a private disk of their own,
because unguessable URLs are not a permission.

Anything stored on this disk in future is public from the moment it is written, with no error to
say so. That is the standing cost of the decision, and the reason to reach for a different disk
rather than this one.

## Consequences

- Already-cached pages keep the broken URL baked into them. The page cache must be flushed for the
  fix to reach a visitor.
- `public/.htaccess` gains `+FollowSymLinks`, without which Apache refuses to traverse the
  `public/storage` link and produces a second, unrelated 403.
- The `public` disk's URL and the `local` disk's serve route both claim `/storage`. `'serve'` is
  dropped from the `local` disk so the two stop competing for one path.

## The alternative, and why not

Declaring the collection on the `public` disk, which is what Laravel intends, and which serves an
image straight off disk without booting the framework. It was the recommendation. Rejected in
favour of the smaller change: it requires moving files already uploaded and rewriting the
collection's disk, and the exposure it avoids came down, on this installation's actual contents, to
one temporary-upload directory that has been moved to a private disk instead.
