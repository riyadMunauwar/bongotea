---
paths:
  - resources/js/lib/cart.ts
---

# Lib

## The bulk Cart is a list of Product + Pack lines
`bongotea.cart` stores `[{productId, packId, kilograms}]`, each Product + Pack at most once (#52); the old `{productId: {kilograms, packId}}` and bare-number shapes still parse as one line each. `cartLines` resolves a null/unoffered packId to the default and merges, and `onlyOrderable` writes the resolved ids back. Limit and stock flags are per tea (summed across its Packs); steppers cap via `cappedKilograms(product, kg, held, elsewhere)`. In `useCart()`, `kilogramsOf(id, packId?)` is one Pack's line (default when omitted) and `teaKilogramsOf(id)` the whole tea. Cards, the product page and the calculator add only to the default Pack.
