---
paths:
  - 'resources/js/**'
---

# Js

## Storefront copy, numbers and head tags each have one home
Interface copy lives only in `resources/js/lang/bn.ts`; Merchant-set text arrives as props. Display numbers go through `lib/bangla.ts` (Bangla digits, Indian grouping, `formatTaka`, `formatPhone`), and every numeric/phone input through `toLatinDigits`/`parseWholeNumber`. SEO tags are drawn twice under the same keys: `resources/views/app.blade.php` inside `<x-inertia::head>` (the no-SSR fallback, `data-inertia="key"`) and `components/storefront/seo-head.tsx` (`head-key="key"`, used when SSR answers and on client visits). Add a tag to both or a crawler sees it only half the time. Colours are tokens in `resources/css/app.css`; components write no hex values.

## Every view of the Cart goes through useCart()
The Cart (ADR-0029) is `lib/cart.ts` (localStorage `bongotea.cart`, pure helpers Vitest-tested in `cart.test.ts`) read only through `useCart()` in `lib/use-cart.ts`, which prices it with `lib/pricing.ts` from the shared `pricing` prop (`StorefrontShell::pricing()`: every published Product's Tiers + isAvailable, and the Delivery Bands). Never read localStorage or keep a second quantity in a component. `useSyncExternalStore` gives SSR an empty Cart, so nothing that touches `window` may run during render. Writes drop lines for Products not Available/not sent (`onlyOrderable`), so an unavailable tea never comes back unasked. A Bulk Order row is addressed as `#tea-{id}` via `rowAnchor`/`rowFromAnchor`.

## Storefront Moments go through lib/tracking.ts and useMoments()
The storefront reports Visit, Product Viewed, Added to Cart and Checkout Started only through `lib/tracking.ts` (`reportMoment()` calls `window[tracking.moments][moment](id, selection)` from `TrackingSnippet::forStorefront()` and `sendBeacon`s the same id). Pages use `useMoments()`; never call `fbq` or post to the beacon from a component. Visit is fired from `reportVisits()` on Inertia's `navigate` event, skipping a same-URL navigate (partial reload). The base code fires no bare PageView on the storefront, so a second copy would be undeduplicated. Added to Cart is raised inside `useCart().set` when a Product goes from 0 to some, once per Product per Cart: `bongotea.cart-moments` holds the Cart id (a new one when the Cart was empty) and the Products reported, and the deduplication id is `{cart}-{productId}`. A Moment's selection is priced with `priceCart` over just what was named, matching `Selection::forProducts()`. The shared `tracking.beacon` is null when the store reports nowhere and counts no Visits; skip the beacon then.

## The retail Cart is a second store, read only through useRetailCart()
The packet basket (ADR-0034) is `lib/retail-cart.ts` (localStorage `bongotea.retail-cart`, `{packId: count}`, Vitest-tested in `retail-cart.test.ts`) read only through `useRetailCart()`, which prices it with `lib/retail-pricing.ts` from the shared `retailPricing` prop (`StorefrontShell::retailPricing()`: every Pack of every published Product with `isOrderable`, plus the retail Delivery Charge and threshold). It never reads or writes the bulk Cart, and nothing on the bulk side reads it. Unlike the bulk Cart, Packs that can no longer be ordered stay visible in the basket (`unorderable`, flagged and out of every figure) until the next write drops them through `onlyOrderablePacks`. A Product is open to retail exactly when `packsOf(productId)` is non-empty — no flag is sent on the card. Pack names are drawn only through `packName()` in `components/cart/figures.ts`.
