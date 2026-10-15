---
paths:
  - resources/js/lib/cart.ts
  - resources/js/lib/contents.ts
  - resources/js/lib/use-adding.ts
  - resources/js/lib/current-cart.ts
---

# Lib

## The bulk Cart is a list of Product + Pack lines
Each bulk Cart's Contents are `[{productId, packId, kilograms}]`, each Product + Pack at most once (#52); the old `{productId: {kilograms, packId}}` and bare-number shapes still parse as one line each. `cartLines` resolves a null/unoffered packId to the default and merges, and `onlyOrderable` writes the resolved ids back. Limit and stock flags are per tea (summed across its Packs); steppers cap via `cappedKilograms(product, kg, held, elsewhere)`. In `useCart()`, `kilogramsOf(id, packId?)` is one Pack's line (default when omitted) and `teaKilogramsOf(id)` the whole tea. Cards and the product page add only to the default Pack, on top of what it holds (see use-adding.ts).

## Contents are kept per Cart id, one key per kind, read only through the hooks
`contentsStore()` keeps every Cart's Contents by Cart id: `bongotea.bulk-contents` (`bulkContents`, lines as in cart.ts) and `bongotea.retail-contents` (`retailContents`, `{packId: count}`). `useCart(cartId)` / `useRetailCart(cartId)` read one Cart's alone and price from it; null means no Cart, holds nothing, takes nothing. The pre-#54 keys `bongotea.cart` / `bongotea.retail-cart` move into the shared `startingCarts` ids on the first read that knows them, never overwriting what that Cart holds, then are removed. A closed Cart's Contents are never dropped. Checkout links go through `checkoutFor(links.checkout, cartId)`.

## Outside a Cart's page, buttons only add, through useAdding and the add-rule
Product cards, the product page and the pack chooser show no Cart's quantity (`useCart(null)` / `useRetailCart(null)` for prices, limits and স্টক শেষ only). They add through `useAdding()`: `ruleFor(product.cartIds, kind)` (pure `addRule()` in lib/add-rule.ts — one open assigned Cart straight in, several a choice, none every open Cart of the kind, closed never offered) fed to `useCartChoice()` (the কোন কার্টে যোগ করবেন? dialog), then `addKilograms`/`addPacks`, which add on top via `withAddedKilograms`/`withAddedPacks`. Steppers on a Cart's page keep set semantics (`withCappedKilograms`). Both paths write through `changeBulkContents`/`changeRetailContents`, which raise Added to Cart.

## The header icons and Cart bar follow the Current Cart, resolved every time
`bongotea.current-carts` stores the Cart of each kind the browser last minded: set by `mindCart()` from a Cart Block's page, `useAdding()` and the header's list, cleared by `forgetCart()` after a Capture empties it. Never read it raw: `useCurrentCarts()` resolves through `resolveCurrentCart()` (stored if open, else an open Cart of the kind holding something, else the header button's, else the only open one), so a closed Cart is never Current. The Cart bar is `barCarts()`: Current Carts holding something, then a "+N আরও কার্ট" row; a closed Cart's Contents (named from the shared `closedCarts` prop) show as বন্ধ with only মুছুন and are never dropped. The price matrix and delivery chart read the Current bulk Cart.
