---
paths:
  - 'routes/*.php'
  - 'app/Http/Controllers/TrackingController.php'
  - 'app/Http/Controllers/CourierController.php'
---

# Routes

## The beacon is middleware-free, two segments, and answers 204 to everything it will not record
`POST /tracking/report` is registered in `routes/tracking.php` through `bootstrap/app.php`'s `then:` closure, with no middleware group — no session, no cookies, no CSRF — like the landing routes, because it is what a Page served as a static file reaches back to. Two path segments, like every other non-landing route, so the one-segment catch-all cannot shadow it. Its only middleware is `throttle:tracking`; `TrackingVisitTest` pins that exact list.

Forgery is answered by `Sec-Fetch-Site: same-origin` (403 otherwise) and nothing else. Do not add a signed token: one baked into cached HTML either never expires and is replayable, or expires and silently kills tracking on every Page the cache has not refreshed.

Every other outcome — a bot User-Agent, an unpublished or unknown slug, a Moment outside `TrackingMoment::reportableByBrowser()` (Visit and Checkout Started only), a missing or over-long id — answers the same empty 204 and writes nothing. A beacon has no use for being told which, and answering them alike means nothing about an installation's Pages can be read back out of the endpoint. Capture and Paid are written by the code that captures and verifies and cannot be forged through here.

## The Announcement address is one per Courier, constrained to every enum name
`POST /courier/{courier}/notify` (`courier.notify`) is where a Courier announces on its own. Shaped like `payment.notify`: POST only, `withoutMiddleware(PreventRequestForgery::class)` because a Courier's server carries no session, `throttle:courier-announcement`, three segments so no slug shadows it, and registered **before** anything else under `/courier/{courier}/` so `notify` can never be read as a reference by a later route taking one in that position.

Constrained with `Courier::names()` — every case and not only the couriers that announce. Which of them could post here is `supportsWebhook()`, a driver's answer, and asking it in a route file would resolve a driver and so this installation's Settings on every request. A Courier that announces nothing reads nothing off the request and is answered 200 all the same (ADR-0019). Every response is 200, including one whose own outbound call threw.
