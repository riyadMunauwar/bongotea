---
paths:
  - 'app/Filament/Resources/Pages/Schemas/OrderComplete/**'
---

# Schemas Order Complete

## A slot box hidden by the layout select rides along `dehydratedWhenHidden()`
The status line box (and any steps/tiles box to come) is `visible()` off `LayoutChoice::selected($get)->drawsX()`. Filament drops a hidden field from the saved state unless it is `dehydratedWhenHidden()` (plain `dehydrated()` is not enough), and the cast's `fromArray()` reads a missing key as null — so without it a Merchant switching to Centered classic and saving would silently lose the words they wrote. Keep the words for the day the Page is moved back, as a question's width is kept across Compact.
