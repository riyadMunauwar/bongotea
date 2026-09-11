---
paths:
  - 'app/Filament/Resources/Pages/Schemas/Tracking/**'
---

# Schemas Tracking

## The Tracking tab speaks in three words and stores a nullable boolean
An event switch is a `ToggleButtons` holding a `TrackingChoice` value (`inherit`/`on`/`off`); `formatStateUsing`/`dehydrateStateUsing` map to and from the nullable boolean a Tracking Config stores, and the enum is the only place that mapping lives. So `$get('tracking_config')` is NOT a stored Config — read it through `Events::asConfig($get)`, which every other section on the tab goes through.

The switches, the Destination picker and the suppression toggle are all `live()` so the preview below answers for the choices being made rather than the last ones saved. The event list comes from `TrackingProvider::events()`, derived from the driver, so a Provider that gains an event gains a switch here with no edit.
