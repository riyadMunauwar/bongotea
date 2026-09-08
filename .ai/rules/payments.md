---
paths:
  - 'app/Support/Payments/**'
  - 'app/Models/Payment.php'
  - 'app/Http/Controllers/PaymentController.php'
  - 'resources/views/checkout/processing.blade.php'
  - 'tests/Fixtures/Payments/**'
---

# Payments

## A return is read for a reference and nothing more; a Verification decides
`PaymentController::processing()` reads the gateway's return through `paymentReferenceFromReturn()` and writes only the gateway's reference onto the Payment. Whatever the return says about the outcome — SSLCommerz posts `status=VALID` beside it — is never believed (ADR-0013). The state changes in exactly one place, `PaymentVerifier::verify()`, and only for a Pending Payment: the gateway is asked outside any lock, then the outcome is written under the Order's lock against a fresh read, so the Processing Page, a Notification and the sweep verifying at once produce one write and never two Paid Payments. A settled Payment (Paid, Failed, Cancelled, Expired) is not asked about again, which is what makes a refresh of the Processing Page and a second call of the verification endpoint free.

A driver's `verify()` takes the Payment, not a reference, because the reference is not always there. SSLCommerz sends a Customer who cancelled back with no `val_id`, so `SslCommerz::verify()` asks the validation API by `val_id` where the Payment holds one and the transaction query API by our `tran_id` otherwise — that is the only way a Cancelled state is ever reached honestly. Paid needs three things to agree: the status says taken, the answer names this Payment's invoice reference, and the amount and currency taken are the Payment's own. The `val_id` arrived on an unauthenticated POST to a guessable address, so an answer that does not name this Payment — `INVALID_TRANSACTION`, or someone else's transaction — settles nothing: the driver falls through to the query by our `tran_id` and decides on that. Do not "simplify" that into Failed; it would let a forged return pin a real Payment Failed before the Customer's own return arrives. The gateway's own `EXPIRED` maps to the Expired state; only a taken status with the wrong amount or the wrong reference is Failed.

## Could not start is Failed on the record and never an exception to the Customer
`PaymentStarter::start()` creates the Pending Payment for the Order's total *before* asking the gateway, under the Order's lock so the invoice reference (`{order number}-{attempt}`) is never reused. A driver that cannot reach or is refused by the gateway throws `PaymentNotStarted` carrying what was said; the starter marks the Payment Failed with that, and answers null, which `CheckoutController::sendOn()` turns into the Retry Page. Starting is refused without a call when the method is not online, has no driver, or is not among `Order::retryPaymentMethods()` — so a method the Page lists but can no longer offer captures Unpaid with no Payment, and no gateway is spoken to for it.

The start call fills every mandatory field from the Order and puts a fixed placeholder where the Checkout never asked (`SslCommerz::PLACEHOLDER_*`). The Checkout does not grow a question for a gateway's sake; a blank refused by the gateway would be a Customer turned away for a question the Merchant chose not to ask.

## The gateway is one seam, and every test fakes it
Every driver speaks through the framework's HTTP client with a short timeout, and `tests/Pest.php` calls `Http::preventStrayRequests()` before every feature test. A test that captures under a usable online method without a fake used to reach the real sandbox, wait out the timeout, and pass on the Failed Payment the outage produced — which is why `RetryPageTest` fakes an outage to make an Unpaid Order. `OnlinePaymentTest` runs as a dataset over `tests/Fixtures/Payments/GatewayScript`, one script per gateway holding its documented shapes; a new gateway is a new script and a new row, never a new test file.

## The Processing Page and its verification sit outside request-forgery protection on purpose
A gateway sends the browser back with a cross-site POST carrying no session and no token, so both routes drop `PreventRequestForgery` and take GET as well as POST. The verification endpoint answers JSON to the page's script and a 303 to a client without one (the meta refresh), and both routes 404 a Payment under another method's path (`PaymentController::abortUnlessUnder()`). Nothing on either page names the Customer; the invoice reference is the only thing about the Order printed, because this is the one address a gateway's own logs hold.
