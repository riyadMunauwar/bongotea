---
paths:
  - 'app/Support/PageCache/**'
  - 'public/index.php'
  - 'deploy/**'
---

# Page cache

## FallbackPageCacheReader may reference nothing, and must stay above the autoloader
It runs in `public/index.php` before `require __DIR__.'/../vendor/autoload.php'`, so at the moment it executes the only thing loaded is its own file. Adding a `use` of any class — a helper, a facade, a value object, an enum for its own header vocabulary — makes the production path fatal while the test suite stays green, because in tests it is ordinary autoloaded code. That is why the header constants and the response shape live inside the one class rather than in a type of their own.

The two lines in the front controller are the whole wiring, and a framework upgrade rewrites that file. `tests/Feature/FallbackPageCacheReaderTest.php` pins their presence and their position above the autoloader; if that test fails after an upgrade, reapply the two lines rather than relaxing the test.

## Existence is the contract between the writer and every reader
`StaticPageCache` validates the slug; nothing that reads the cache does. A reader builds a candidate path, confirms containment in the cache directory, confirms the file is there, and serves it — the file exists only because the writer already accepted the slug that made it. Do not add slug validation to a reader, and do not add a lookup that is not existence-based (a reserved key, a magic name, a URL-to-filename translation). Removing the last one of those is what #23 was for.

The filename shape — `<slug>.html` plus a `.gz` sibling with a matching mtime — is the one thing knowingly spelled out in more than one place: the writer, the fallback reader, and each webserver configuration. Change it in all of them, and lean on the three-way byte identity in `tests/Feature/LandingPageTest.php`.

## One header names the serving path, and the fast path sets nothing
`X-GioPages-Cache: fallback` from the fallback reader, `X-GioPages-Cache: miss` from a full render in `LandingPageController`, and no header at all when the webserver served the file itself. Absence is the marker for the fast path because Apache cannot reliably stamp a header onto a static file, so there is no marker it could set everywhere. Anything diagnosing the cache reads it that way round: absence is the healthy case.
