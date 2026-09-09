---
paths:
  - 'app/Filament/Resources/Pages/Schemas/OrderComplete/**'
---

# Schemas Order Complete

## A slot box hidden by the layout select rides along `dehydratedWhenHidden()`
The status line box is `visible()` off `LayoutChoice::selected($get)->drawsX()`. Filament drops a hidden field from the saved state unless it is `dehydratedWhenHidden()` (plain `dehydrated()` is not enough), and the cast's `fromArray()` reads a missing key as null — so without it a Merchant switching to Centered classic and saving would silently lose the words they wrote. Keep the words for the day the Page is moved back, as a question's width is kept across Compact.

## A slot Section hidden by the layout select carries `dehydratedWhenHidden()` itself
Steps and Tiles are whole Sections `visible()` off `LayoutChoice::selected($get)->drawsSteps()/drawsTiles()`, with `dehydratedWhenHidden()` on the Section, not on each field. `Component::isHidden()` never consults the parent, so the fields inside a hidden Section read as visible and would carry no hint of their own — it is the Section's own `dehydrateState()` that forgets every descendant path when it is hidden and not dehydrated-when-hidden. Assert the Section's `isHidden()` in tests, not a field's. Rows are fixed by position (`order_complete_config.steps.N.label`), never a Repeater: the tracker and the grid draw a fixed count whatever is typed, and `fromArray()` pads or cuts by position.
