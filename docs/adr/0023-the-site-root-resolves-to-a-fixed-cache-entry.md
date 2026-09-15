# The Site Root resolves to a fixed cache entry, not to a Slug

A Merchant designates one published Page as the **Front Page**, and the **Site Root** serves that
Page's bytes from the static cache with the framework never booting. The root entry is a fixed
filename — `page-cache/_front.html` and its `.gz` sibling — written from the same render that writes
the Page's own entry, so the two are byte-identical by construction rather than by coincidence. When
no Front Page is designated, or when the designated one is a draft or gone, the root entry holds the
**Placeholder** instead and is served the same way.

This is the reinstatement ADR-0004 anticipated, and it does not take the shape ADR-0004 predicted.
That ADR said *"reinstating homepage-as-landing-Page later should resolve the root to a designated
Slug, which stays existence-based"*. Only the second half survived. A Slug cannot be the answer,
because the thing that resolves `/` on the fast path is Apache or nginx, and neither of them can read
a Merchant's Settings to learn which Slug was designated. Either the mapping is baked into the
webserver configuration and regenerated on every save — a generated `.htaccess`, on hosts where an
upgrade or a control panel routinely replaces that file — or the root has a name of its own that
never changes. It has a name of its own.

What issue #23 actually removed was a lookup that was **not existence-based**: an empty Slug
translated into a magic filename, with the translation living in the reader. `_front.html` keeps the
property that mattered. Every reader builds a candidate path, confirms containment in the cache
directory, confirms the file is there, and serves it — the same three steps as a Slug, against a
constant instead of a captured segment. Nothing is reserved, nothing is validated twice, and absence
is still the whole signal: no file, no Front Page, boot and render.

`_front` is unspellable as a Slug at any case on any filesystem, which is the point of the
underscore. `index.html` was rejected because `index` is a legal Slug and a Merchant may already own
`/index`; an uppercase name was rejected because case-insensitive filesystems — including the one
this project is developed on — would collide it with the legal Slug `front`. `StaticPageCache` gets
`putFront()` / `forgetFront()` / `hasFront()` rather than a carve-out in `SLUG_PATTERN`: the root
entry is a second named thing the writer knows about, and the rule that makes traversal impossible by
construction stays absolute.

## Consequences

- Three configurations learn one new rule, and it must sit **above** the existing `-d` stanza. `/`
  resolves to the document root, which is a directory, so `RewriteCond %{REQUEST_FILENAME} -d` matches
  first and stops rewriting — which is exactly why the root falls through to `index.php` today. A root
  rule placed below it does nothing at all, on a correctly configured host, with every test in the
  suite still green.
- `FallbackPageCacheReader` learns the same lookup, and needs no pointer file and no knowledge of
  which Page was designated. It currently returns `null` for an empty path; it now answers the root
  from the fixed name. Without this, a Merchant whose webserver was never configured — the population
  ADR-0004 exists for — would have every Slug Page served from disk and their single most valuable
  address served by a full framework boot, silently. That asymmetry is the reason the second reader is
  in this decision rather than deferred.
- `Route::get('/')` moves out of `routes/web.php` into the middleware-free `routes/landing.php`. In
  the `web` group it carries `StartSession` and `PreventRequestForgery`, so the response ships a
  `Set-Cookie` and an `XSRF-TOKEN` cookie and is no longer byte-identical to what was written to disk
  — the exact reasoning already written at the top of `routes/landing.php`.
- Whichever address misses first renders once and writes both entries, which means
  `LandingPageController::show()` resolves `GeneralSettings` on a miss to ask whether the Page it just
  rendered is the Front Page. This is a deliberate, bounded exception to "a public Page that reads no
  Setting pays no query": a miss is the render that *fills* the cache, not a served request, and it has
  already paid a framework boot and a query for the Page. On a warm cache it never happens. The
  alternative — each address writing only its own entry — produces two renders at two moments with
  nothing holding them equal, and no test could see them drift.
- Nothing in the cached HTML may depend on whether a Page is the Front Page. The moment it does, the
  bytes stop being a copy and changing the Setting requires a re-render rather than a re-write. This is
  why the canonical link is injected into **every** Page self-referentially rather than only into the
  Front Page: one value that is correct at both addresses.
- One document now answers at two addresses, so `<link rel="canonical">` is injected beside the Pixel,
  at the one point every byte passes through, under ADR-0017's reasoning. It cannot ride in an HTTP
  header: the fast path is a webserver reading a static file, and Apache cannot reliably stamp a header
  onto one — the same constraint that made absence the marker for that path. The injector stands down
  when the Merchant authored a canonical of their own, because two canonicals are worth less than none.
- The Front Page is stored as a Page id and not a Slug, because a Slug can be changed — the
  `wasChanged('slug')` branch in `PageCacheInvalidator::forgetPage()` exists for that — and a rename
  would silently orphan the pointer. `0` means none, and `SettingsGroup::coerce()` maps a stored null on
  a non-nullable int to `0`, so a damaged row reads as *no Front Page* and the Site Root serves the
  Placeholder. The failure direction is harmless by construction.
- The Select offers every non-deleted Page, drafts included, and forces the stored value into its
  options whatever state it is in. A Select derives an implicit `in:` rule from its options
  (`.ai/rules/settings.md`), so an option set of published Pages only would make unpublishing the Front
  Page refuse to save the **General settings page at all** — currency, timezone and phone rules
  unsaveable because a Page was unpublished on another screen entirely. The group's `with()` settling a
  dangling id cannot be the only defence: validation runs before `with()` does.
- The Placeholder is cached like anything else, so it is one document for everyone and cannot say one
  thing to a signed-in Merchant and another to a customer. It therefore carries the "choose a Front
  Page" line as ordinary visible content, and `noindex` — thousands of byte-identical documents across
  unrelated domains is the shape search engines penalise, and the Merchant's domain wears it, not ours.
  It links to no admin panel: `/` is public, and advertising the panel's location to every crawler buys
  a convenience the Merchant does not need.
- `StaticPageCache::flush()` must not count `_front.html` in the number it returns. An operator reads
  that figure as "Pages removed", and the root entry is a copy of a Page rather than a Page.
- An existing installation upgrading with a hand-edited `public/.htaccess` will not have the root rule.
  It degrades to the fallback reader rather than to a framework boot, which is the two-reader design
  working as designed and not a migration step anybody has to run.

## What a future reader gets wrong

That `_front.html` is issue #23 coming back, and should be removed again for the reasons recorded on
ADR-0004. The history reads that way: a magic filename in the cache directory, taught to every reader,
on a project that deliberately deleted one.

The thing #23 removed was a **translation** — an empty Slug becoming a filename, a rule each reader had
to learn and apply to input that came off the wire. What is here is a **constant**. No request is
transformed into it; a reader either finds `_front.html` on disk or does not. The distinction is the
one `try_files` has always drawn, and it is why a change to what counts as a legal Slug still cannot
break a reader. Removing this does not restore a property — the property is intact — it just returns
the Site Root to a full framework boot on every installation that uses it.

The second thing: that the byte copy is redundant and `/` should simply redirect to the Slug. That was
the live alternative, and it is recorded below.

## The alternatives, and why not

**Redirect `/` to the Front Page's Slug.** One 301, then the ordinary fast path: no new rule in any
reader, no new entry on disk, no canonical question at all. It was the cheapest thing that could work
and it is not obviously wrong. It was rejected because the Site Root stops being an address and becomes
a signpost — a Merchant who puts the bare domain on a business card or a delivery box watches it
bounce, and the address a customer types is never the address they end up on. A WordPress front page
does not behave that way, and the Merchant's mental model came from there.

**Serve the root through PHP only, via the fallback reader.** `/` already reaches `index.php` on every
host, so the reader could answer it with no webserver configuration changed anywhere — nothing for a
shared-hosting Merchant to paste, and about a millisecond of PHP against nginx's fraction of one.
Rejected as the *only* path, because it would leave a correctly configured host paying a PHP process
start on its most valuable URL forever. It is not rejected as a path: it is the second reader, and it
is in the decision above.

**A generated webserver rule carrying the designated Slug.** This would have honoured ADR-0004's
prediction literally — the root really would resolve to a Slug. It requires the application to rewrite
`public/.htaccess` on every Settings save, on hosts where an upgrade or a control panel replaces that
file without warning, and it has no equivalent at all on nginx or OpenLiteSpeed, where the
configuration is root-owned and outside the document root. A feature that works on one webserver and
silently does nothing on two others is worse than a constant filename.

**Render `/` separately so it can carry its own self-referential canonical.** Two renders per save, two
entries free to drift, and a Visit at `/` that has to decide whether it is the same Page for reporting.
The canonical it was bought for is unnecessary: one value pointing at the Slug is correct at both
addresses, and pointing it at the Slug is what we want anyway — a Slug is claimed for good, while the
Site Root is a dropdown a Merchant can repoint on a Tuesday, and ranking should accrue to the permanent
identity.
