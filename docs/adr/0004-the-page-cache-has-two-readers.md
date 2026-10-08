# The page cache has two readers

> **Superseded by [ADR-0030](0030-a-page-is-made-only-of-blocks-and-a-system-page-is-known-by-its-role.md).** The static page cache is gone; the storefront is rendered by Inertia with SSR.

`StaticPageCache` writes `<slug>.html` and a `.gz` sibling. Two separate things read them back: the
webserver, where it has been configured to — `public/.htaccess` on Apache and LiteSpeed, `try_files`
on nginx, the vhost snippet on OpenLiteSpeed — and `FallbackPageCacheReader`, from inside
`public/index.php` above the Composer autoloader, on requests that reached PHP anyway.

The second reader exists because the first one is a configuration, and a Merchant on shared hosting
may not have it. The failure is invisible from the outside: pages load, checkouts capture, and the
Merchant simply pays a full framework boot on every click of the ad traffic they bought. Serving the
file from PHP is slower than the webserver doing it and far faster than booting the framework, so
the fallback turns the worst outcome into a middling one for precisely the people who cannot
diagnose it. It ships enabled with no setting, because a Merchant who cannot tell that their
webserver is not serving the cache cannot be asked whether to switch on the thing that compensates.
Why it costs a correctly configured host nothing is written on the class.

## Consequences

- Existence is the contract between the writer and every reader. A reader confirms containment and
  confirms the file is there; it carries no slug pattern, because the file only exists if the writer
  already accepted the slug that made it. That is what lets the slug rules change, or a third reader
  arrive, without touching either existing one — and it is what `try_files` has always done, so the
  rule is the webserver's, adopted rather than invented.
- The reserved root cache key had to go (issue #23). It was the one lookup that was not
  existence-based: an empty slug translated into a magic filename, a rule every reader would have
  had to learn. Nothing ever wrote it. Removing it while the cache still had a single reader meant
  the translation was never taught, rather than taught again in each new reader and then untaught
  in all of them. Reinstating homepage-as-landing-Page later should resolve the root to a designated
  Slug, which stays existence-based.
- Apache is not taught to serve the pre-compressed sibling, though nginx and the fallback reader
  both do. There it needs optional modules and directory override permissions not every shared host
  grants, and it collides with LiteSpeed's own compression layer, for a payload saving in the low
  single digits over compressing on the fly. A default path that cannot be verified in CI is not
  worth that; `deploy/README.md` carries it as opt-in, with the rules to paste.
- The byte-for-byte guarantee is three-way rather than pairwise: what the controller renders, what
  lands on disk, and what the fallback would serve are asserted equal in one place. Pairwise, a
  Merchant on the fallback path could silently ship different HTML from one on the fast path.
- One class is deliberately loaded two ways — required by path above the autoloader in production,
  autoloaded normally in tests — which is why it may reference nothing at all, and why the wiring
  lives in a file that a framework upgrade rewrites. Both constraints, and the test that pins them,
  are recorded in `.ai/rules/page-cache.md`, where the next person to edit that file will meet them.
- Three serving paths have to be told apart, and one header does it with absence marking the fast
  path, because Apache cannot reliably stamp a header onto a static file. `deploy/README.md` reads
  it that way round for an operator, and the panel's indicator does it for a User with no shell.

## What a future reader gets wrong

That the fallback is redundant with the webserver configuration, and one of the two should go. It
reads that way: PHP in the request path doing what `.htaccess` and `try_files` already do, on a
project whose whole premise is that PHP does not run on a cache hit.

It is not duplication. The fallback is the only reader that reaches a Merchant whose webserver was
never configured, which after the move to shared hosting is a large share of them. Deleting it
breaks nothing any test can see — every configured host keeps passing and the suite stays green —
and returns those Merchants to a full framework boot per click with no signal anywhere. That
silence is the reason it exists.

## The alternatives, and why not

A middleware-based response-cache package. The mature ones return the cached response from
middleware, which means the framework has already booted: roughly 30–80ms on the hosting in
question, against well under 1ms for a webserver reading a file. That discards the single property
the cache was built for, and the one ADR-0001 leans on when it argues that expanding shortcodes on
render costs nothing. The fallback reader is that idea's compromise, kept above the autoloader
precisely so it does not become this.

Staying nginx-only. It had been the configuration since the cache existed, and the honest reading
was that a Merchant on unsupported hosting should move. But the overwhelming majority of shared
hosting is Apache and LiteSpeed, so nginx-only left most Merchants with the feature switched off and
nothing reporting it. The shipped `.htaccess` covers the ones whose host allows overrides; the
fallback reader covers the rest.
