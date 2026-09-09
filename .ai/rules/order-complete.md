---
paths:
  - 'app/Support/Orders/OrderOwnership.php'
  - 'app/Http/Controllers/CheckoutController.php'
  - 'resources/views/checkout/order-complete.blade.php'
---

# Order Complete Page

## Ownership of an Order is a cookie set at Capture and read by the Order Complete Page alone
`OrderOwnership` is the one wording of ADR-0014: a dedicated, framework-encrypted cookie holding the last `KEEP` order numbers this browser placed, thirty days, SameSite Lax, HttpOnly. `CheckoutController::store()` attaches it to the Capture response itself — JSON or 303 — so it is in hand before the browser leaves for a gateway; `choose()` on the Retry Page sets nothing, because the browser choosing again already has it. Only `orderComplete()` reads it, into `$placedByThisBrowser`, and all it unlocks is the Deliver-to block (name, phone, address, district, and the answer to a Merchant question keyed `note`, via `Order::deliveryNote()`). A stranger is shown less, never refused — no 404, no login, no session. Do not move ownership into the session (a gateway's cross-site POST return starts a fresh one and every online Order would land stripped) and do not have the Processing Page re-grant it (its address sits in the gateway's logs). Read the cookie totally: unparseable is an empty list.

## The Print button is the page's only script, and it is an attribute
Receipt's Print button is a `<button type="button" onclick="window.print()">` drawn behind `OrderCompleteLayout::drawsPrintButton()`, after the secondary button in the actions nav. It is the whole of the page's script: `expectNoExternalRequestIn()` refuses any `<script`, and the print test pins one `onclick` and one `print()` on Receipt and none on the other nine layouts. Never add a `<script>` tag here; see the same-named rule in `.ai/rules/support-order-complete.md` for the enum side.
