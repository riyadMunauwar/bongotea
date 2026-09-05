# Shortcodes expand on render, never on save

A Page's stored `body` keeps the literal `[checkout]` text forever; expansion happens when the
Page is rendered, on a cache miss. The obvious optimisation is the opposite — expand once at save
time and store the result — but the static page cache (issue #4) means there is no per-request cost to
optimise away: every cache hit is answered from a file on disk with nothing expanded — by the
webserver where it is configured to, and otherwise by the fallback reader (ADR-0004) — and a render
happens only on a miss, so expansion is already amortised to near zero.

## Consequences

- `pages.body` is permanently the merchant's source of truth. It is what they typed, so the editor
  shows them what they wrote and a shortcode bug is fixed by deploying rather than by backfilling
  every affected row.
- A Product price change never rewrites stored HTML. Invalidation alone is enough: the Page
  observer and the Product-to-Page pivot walk clear the cached file, and the next render picks up
  the new price.
- Expanding on save would have made every price edit a data migration over every linked Page.

## Not in tension with validating on save

Parse-on-render is about not *storing* expanded output. The parser still runs at save time purely
to validate — unknown tags, a duplicate `[checkout]`, an ambiguous or missing Product link — so the
merchant is told at their desk instead of discovering it from a flat conversion graph.
