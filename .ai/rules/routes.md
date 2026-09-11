---
paths:
  - 'routes/*.php'
  - 'app/Http/Controllers/TrackingController.php'
---

# Routes

## The beacon is middleware-free, two segments, and answers 204 to everything it will not record
`POST /tracking/report` is registered in `routes/tracking.php` through `bootstrap/app.php`'s `then:` closure, with no middleware group — no session, no cookies, no CSRF — like the landing routes, because it is what a Page served as a static file reaches back to. Two path segments, like every other non-landing route, so the one-segment catch-all cannot shadow it. Its only middleware is `throttle:tracking`; `TrackingVisitTest` pins that exact list.

Forgery is answered by `Sec-Fetch-Site: same-origin` (403 otherwise) and nothing else. Do not add a signed token: one baked into cached HTML either never expires and is replayable, or expires and silently kills tracking on every Page the cache has not refreshed.

Every other outcome — a bot User-Agent, an unpublished or unknown slug, a Moment outside `TrackingMoment::reportableByBrowser()` (Visit and Checkout Started only), a missing or over-long id — answers the same empty 204 and writes nothing. A beacon has no use for being told which, and answering them alike means nothing about an installation's Pages can be read back out of the endpoint. Capture and Paid are written by the code that captures and verifies and cannot be forged through here.
