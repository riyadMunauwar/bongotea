---
paths:
  - resources/js/lib/cart.ts
  - resources/js/lib/contents.ts
---

# Lib

## The bulk Cart is a list of Product + Pack lines
Each bulk Cart's Contents are `[{productId, packId, kilograms}]`, each Product + Pack at most once (#52); the old `{productId: {kilograms, packId}}` and bare-number shapes still parse as one line each. `cartLines` resolves a null/unoffered packId to the default and merges, and `onlyOrderable` writes the resolved ids back. Limit and stock flags are per tea (summed across its Packs); steppers cap via `cappedKilograms(product, kg, held, elsewhere)`. In `useCart()`, `kilogramsOf(id, packId?)` is one Pack's line (default when omitted) and `teaKilogramsOf(id)` the whole tea. Cards, the product page and the calculator add only to the default Pack.

## Contents are kept per Cart id, one key per kind, read only through the hooks
`contentsStore()` keeps every Cart's Contents by Cart id: `bongotea.bulk-contents` (`bulkContents`, lines as in cart.ts) and `bongotea.retail-contents` (`retailContents`, `{packId: count}`). `useCart(cartId)` / `useRetailCart(cartId)` read one Cart's alone and price from it; null means no Cart, holds nothing, takes nothing. The pre-#54 keys `bongotea.cart` / `bongotea.retail-cart` move into the shared `startingCarts` ids on the first read that knows them, never overwriting what that Cart holds, then are removed. A closed Cart's Contents are never dropped. Checkout links go through `checkoutFor(links.checkout, cartId)`.
