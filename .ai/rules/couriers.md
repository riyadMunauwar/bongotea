---
paths:
  - 'app/Support/Couriers/**'
  - app/Enums/Courier.php
---

# Couriers

## The Courier enum is the registry and the driver is the only thing that knows a courier
`Courier` (app/Enums/Courier.php) is the whole registry — adding a fourth courier is one case plus one class implementing `CourierDriver`, with no config list and no provider line (ADR-0013). `config('giopages.couriers.*')` holds only per-courier parcel defaults and is read in `CourierSettings::__construct()` and nowhere else; it never decides which couriers exist.

Nothing outside `app/Support/Couriers/` may branch on a courier's name. Ask the driver: `supportsBulk()`, `supportsWebhook()`, `supportsBalance()`, `needsAreaResolution()`, `batchLimit()`, `reAskIntervalSeconds()`. RedEx declares a batch limit of 1 rather than "no bulk", so a batch is the same loop for every courier.

"Why this Courier cannot be used" has one wording, in `CourierSettings::refusal()`. `CourierDriver::refusal()` and `Courier::refusal()` are delegations to it — never restate the rule (on, and every credential filled) at a call site. A driver may later add its own reason; it must not re-implement the shared one.

The contract declares only what is implemented. Booking, status, webhooks and balance arrive with #127 onwards; do not declare a method three drivers would throw from.
