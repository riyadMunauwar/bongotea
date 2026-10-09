---
paths:
  - 'app/Support/Customers/**'
  - app/Models/Customer.php
  - app/Models/OneTimeCode.php
  - app/Enums/OrderProgress.php
  - app/Http/Controllers/SigningInController.php
  - app/Http/Requests/OneTimeCodeRequest.php
  - app/Http/Requests/SignInRequest.php
  - app/Support/Pages/Blocks/MyOrders.php
---

# Customers

## A Customer is Signed in by its own cookie guard, never the session, and Signing in creates nothing
`auth('customer')` is `CustomerGuard` (registered with `Auth::extend('customer')` in AppServiceProvider): a dedicated encrypted cookie `customer_session` holding the canonical phone and an `until` timestamp, 30 days, HttpOnly, SameSite Lax. Not the session (the admin's two-hour lifetime) and not a remember token (that would store something on the Customer). Expiry is checked inside the cookie too, so a replayed cookie dies at 30 days. `user()` answers `Customer::firstOrNew` — never create a Customer row on sign-in; one with `exists === false` simply has no Orders. One-Time Codes (`OneTimeCodes`): one hashed row per number, replaced on each send, deleted on use, burned after 5 wrong guesses (4 digits fall to 10,000 tries), and a number takes no code after 10 wrong guesses in a day from any address; 3 sends/number/hour via RateLimiter plus `throttle:one-time-code` / `throttle:sign-in` per IP. Order Progress is `OrderProgress::of()` over loaded shipments — never stored, never exposes a Consignment.
