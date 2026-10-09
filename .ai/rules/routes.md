---
paths:
  - 'routes/*.php'
  - 'app/Http/Controllers/TrackingController.php'
  - 'app/Http/Controllers/CourierController.php'
---

# Routes

## The beacon is middleware-free, two segments, and answers 204 to everything it will not record
`POST /tracking/report` is registered in `routes/tracking.php` through `bootstrap/app.php`'s `then:` closure, with no middleware group — no session, no cookies, no CSRF — because it sits on the path of every page load and exists to be cheap. Its only middleware is `throttle:tracking`; `TrackingVisitTest` pins that exact list.

Forgery is answered by `Sec-Fetch-Site: same-origin` (403 otherwise) and nothing else. Do not add a signed token: one baked into a page either never expires and is replayable, or expires and silently kills tracking.

Every other outcome — a bot User-Agent, a Moment outside `TrackingMoment::reportableByBrowser()` (Visit, Product Viewed, Added to Cart and Checkout Started), a missing or over-long id — answers the same empty 204 and writes nothing. A beacon has no use for being told which, and answering them alike means nothing about the store can be read back out of the endpoint. The address a Moment is reported against is the request's Referer, which a same-origin beacon carries. Capture and Paid are written by the code that captures and verifies and cannot be forged through here.

## The Announcement address is one per Courier, constrained to every enum name
`POST /courier/{courier}/notify` (`courier.notify`) is where a Courier announces on its own. Shaped like `payment.notify`: POST only, `withoutMiddleware(PreventRequestForgery::class)` because a Courier's server carries no session, `throttle:courier-announcement`, and registered **before** anything else under `/courier/{courier}/` so `notify` can never be read as a reference by a later route taking one in that position.

**An announcement about a parcel awaiting dispatch is ignored, never polled.** `CourierController::notify()` checks `Shipment::isAwaitingDispatch()` before it asks the Courier anything — the same state `Shipment::abandon()` refuses on, for the same reason. A batch writes its rows before the sweep dispatches them (ADR-0020), so between the two a row is Written with no Consignment; the invoice reference is `{order number}-{n}` and guessable, and `CourierPoller::ask()` would put the question with an empty Consignment, get a non-silent answer and write `Unknown` plus `polled_at` onto the row. Unknown is not terminal, so the row stays Active and out of `awaitingDispatch()` — which the dispatcher selects on — while `CourierSweep::dueFor()` needs a Consignment to poll. The parcel is then reachable by nothing at all, from one unauthenticated POST.

Constrained with `Courier::names()` — every case and not only the couriers that announce. Which of them could post here is `supportsWebhook()`, a driver's answer, and asking it in a route file would resolve a driver and so this installation's Settings on every request. A Courier that announces nothing reads nothing off the request and is answered 200 all the same (ADR-0019). Every response is 200, including one whose own outbound call threw.
