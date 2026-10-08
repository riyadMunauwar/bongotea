---
paths:
  - 'app/Support/Sms/**'
  - app/Enums/SmsGateway.php
  - app/Enums/OrderText.php
  - app/Support/Settings/SmsSettings.php
  - app/Observers/OrderTextObserver.php
  - app/Observers/ShipmentTextObserver.php
  - app/Filament/Clusters/Settings/Pages/Sms.php
  - 'tests/Fixtures/Sms/**'
---

# Sms

## A text is queued after commit and never thrown into its caller
`OrderTextObserver` (Order created / status changed) and `ShipmentTextObserver` (a Consignment comes back, i.e. `wasHandedOver()`) are the only triggers; never call `OrderTexts` from a controller or action. `OrderTexts` reads the committed Order and words the text inside `DB::afterCommit()` + `rescue()`, because the placed text reads lines and a total that Capture writes after `created` fires — an Order created outside a transaction would be worded with no lines, so any new way of creating one must wrap it in one as Capture does. `SmsGatewayDriver::send()` answers a `TextSent` (sent / refused / unreachable) and never throws; `SendText` releases only on unreachable (429, 408, 5xx, connection) and logs the rest. It lives in `app/Support/Sms` (no `app/Jobs`) and forgets the `SmsSettings` instance on each run because a queue worker outlives a settings save. Gateways are the `SmsGateway` enum registry, and "usable" is worded once, in `SmsSettings::refusal()`; Order Text settings are `{OrderText value}Enabled/Template`. Tests: `tests/Fixtures/Sms` scripts over the `sms gateways` dataset.
