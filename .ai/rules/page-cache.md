---
paths:
  - 'app/Support/PageCache/**'
  - 'public/index.php'
  - 'public/.htaccess'
  - 'public/robots.txt'
  - 'deploy/**'
---

# Page cache

## FallbackPageCacheReader may reference nothing, and must stay above the autoloader
It runs in `public/index.php` before `require __DIR__.'/../vendor/autoload.php'`, so at the moment it executes the only thing loaded is its own file. Adding a `use` of any class — a helper, a facade, a value object, an enum for its own header vocabulary — makes the production path fatal while the test suite stays green, because in tests it is ordinary autoloaded code. That is why the header constants and the response shape live inside the one class rather than in a type of their own.

The two lines in the front controller are the whole wiring, and a framework upgrade rewrites that file. `tests/Feature/FallbackPageCacheReaderTest.php` pins their presence and their position above the autoloader; if that test fails after an upgrade, reapply the two lines rather than relaxing the test.

## Existence is the contract between the writer and every reader
`StaticPageCache` validates the slug; nothing that reads the cache does. A reader builds a candidate path, confirms containment in the cache directory, confirms the file is there, and serves it — the file exists only because the writer already accepted the slug that made it. Do not add slug validation to a reader, and do not add a lookup that is not existence-based (a reserved key, a magic name, a URL-to-filename translation). Removing the last one of those is what #23 was for. The Site Root is the one constant a reader knows, and it is not a counter-example — see the section on it below for why a constant is not a translation.

The filename shape — `<slug>.html` plus a `.gz` sibling with a matching mtime — is the one thing knowingly spelled out in more than one place: the writer, the fallback reader, `public/.htaccess`, and each webserver configuration under `deploy/`. Change it in all of them. Two tests pin the pairing and will fail if you miss one of the PHP halves: `tests/Feature/StaticPageCacheTest.php` holds the writer to producing both files, stamping them from one reading of the clock, and removing them together; the gzip cases in `tests/Feature/FallbackPageCacheReaderTest.php` hold the reader to finding the `.gz` sibling for a client that accepts it and falling back to the plain file when it is gone. Nothing in the suite reads the webserver configurations, so those are yours to change by hand.

The three-way byte identity in `tests/Feature/LandingPageTest.php` is a different guarantee and not a pin on the filename shape: it says the visitor, the disk and the fallback reader ship the same bytes. It resolves with an empty `Accept-Encoding`, so it never touches the `.gz` sibling, and a suffix change leaves it green.

## One header names the serving path, and the fast path sets nothing
`X-GioPages-Cache: fallback` from the fallback reader, `X-GioPages-Cache: miss` from a full render in `LandingPageController`, and no header at all when the webserver served the file itself. Absence is the marker for the fast path because Apache cannot reliably stamp a header onto a static file, so there is no marker it could set everywhere. Anything diagnosing the cache reads it that way round: absence is the healthy case.

## The Site Root's entry is a constant, and its rewrite must sit above the -d rule
`/` is served from the cache as `_front.html` (ADR-0023) — a constant, because no webserver can read Settings to learn which Page was designated. It is not the reserved key #23 removed: nothing off the wire is translated into it, and the answer is still existence.

In `public/.htaccess` and `deploy/openlitespeed/`, the root rule MUST stay above `RewriteCond %{REQUEST_FILENAME} -d`. `/` resolves to the document root, a directory, so the `-d` rule matches first and stops rewriting — which is exactly why the root used to fall through to index.php. Below it, the front page silently stops being served from the cache on a correctly configured host with every test green. nginx needs a `location = /` instead: `try_files $uri $uri/` resolves `/` through the index directive before the cache lookup is ever reached.

`_front` is spelled in five places — `StaticPageCache::FRONT_ENTRY`, `FallbackPageCacheReader::FRONT_ENTRY` (which may reference no class), `.htaccess`, and both deploy configs. Same knowing duplication as `<slug>.html`. The underscore is load-bearing: it cannot appear in a Slug, so no Page can own this entry. `index` was rejected because `index` is a legal Slug; an uppercase name because a case-insensitive filesystem collides it with the Slug `front`.

`StaticPageCache` writes it through `putFront()`/`forgetFront()`, never through `put()` with a reserved slug: SLUG_PATTERN stays absolute because it is what makes traversal impossible by construction. `flush()` deliberately does not count it — that figure is read as "Pages removed".

Nothing in cached HTML may depend on whether a Page is the Front Page, or the bytes stop being a copy. That is why the canonical is drawn self-referentially on every Page rather than only on the Front Page.
