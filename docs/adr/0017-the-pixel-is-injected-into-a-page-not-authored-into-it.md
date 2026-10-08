# The Pixel is injected into a Page, not authored into it

> **Superseded by [ADR-0030](0030-a-page-is-made-only-of-blocks-and-a-system-page-is-known-by-its-role.md).** The Pixel is injected into the Inertia root document; the principle — injected, never authored — carries over.

A Page's `body` is the whole HTML document and belongs to the Merchant. Until now nothing was
added to it: the only thing the system wrote into a served Page was the Checkout island, and that
reached no further than the point `[checkout]` was expanded at. **The tracking snippet breaks
that rule deliberately.** It is inserted before `</head>` — falling back to `</body>`, then
appended — at the one point every byte a visitor receives passes through: after shortcode
expansion, before the single string is both written to the static cache and returned.

The alternative was a snippet the Merchant pastes into their own `<head>`, which is how every ad
platform documents it. It was rejected because a Merchant who forgets is a Page that reports
nothing with nothing anywhere to say so, and because the server half and the browser half must
agree on which Destination a Page reports to — a pasted Pixel could name a different dataset from
the one the outbox is writing rows for, and Events Manager would show two half-populated pictures.

**The snippet is baked into the cached file.** It depends on the Page and its Destinations and on
nothing about the visitor, so it is stable at render time; saving a Page forgets that Page and
saving a Destination forgets every Page (ADR-0016), so a changed dataset never outlives the edit.
This is what keeps the central performance guarantee intact: a published Page is still served by
the webserver as a file with no PHP running (ADR-0004), and the Pixel costs that path nothing.

**A Page load is deduplicated on an identifier the browser makes up, not one written into the
document.** One file is served to every visitor, so a baked-in identifier would be the same string
for all of them and the Provider would count a whole campaign as one Visit. The document therefore
carries JavaScript naming the value rather than the value, and the same name is handed to the
Pixel and to the beacon.

**The server's copy arrives over a beacon, and the beacon carries no token.** `POST
/tracking/report` is registered with no middleware group — no session, no cookies, no CSRF — like
the landing routes themselves, and two path segments deep so the one-segment landing catch-all
cannot shadow it. Forgery is answered by `Sec-Fetch-Site: same-origin`, the same signal the
Checkout POST already trusts. A signed token was rejected outright: one baked into cached HTML
either never expires, and is trivially replayable for no gain, or expires and silently kills
tracking on every Page the cache has not refreshed since.

## Consequences

- "What you paste is what a visitor receives" is no longer true, and the Page form no longer says
  it. A Page that reports nowhere is still served byte for byte as it was written — no element, no
  global, no beacon, no external request — which is every Page until a Merchant adds a Destination.
- A Page carrying a Destination loads a script from Facebook's CDN. This is the first external
  request the product has ever made from a served Page, fonts included, and it is deliberate.
- The authoring preview carries no Pixel. A Merchant looking at their own draft is not a Visit, and
  reporting one would put admin loads into their Conversion Rate and their dataset.
- The three-way byte identity — visitor, disk, fallback reader — survives injection because the
  injection happens to one string before it is both cached and returned, not to a response on the
  way out.
- The beacon can write only Visit and Checkout Started. Capture and Paid are written by the code
  that captures and verifies, so no Purchase can be forged through an unauthenticated endpoint.
- Everything the beacon will not record — a bot, an unpublished slug, a Moment it may not write —
  is answered with the same empty `204`. Being refused costs the redundancy and not the event,
  because the browser's Pixel has already fired.
