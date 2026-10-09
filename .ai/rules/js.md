---
paths:
  - 'resources/js/**'
---

# Js

## Storefront copy, numbers and head tags each have one home
Interface copy lives only in `resources/js/lang/bn.ts`; Merchant-set text arrives as props. Display numbers go through `lib/bangla.ts` (Bangla digits, Indian grouping, `formatTaka`, `formatPhone`), and every numeric/phone input through `toLatinDigits`/`parseWholeNumber`. SEO tags are drawn twice under the same keys: `resources/views/app.blade.php` inside `<x-inertia::head>` (the no-SSR fallback, `data-inertia="key"`) and `components/storefront/seo-head.tsx` (`head-key="key"`, used when SSR answers and on client visits). Add a tag to both or a crawler sees it only half the time. Colours are tokens in `resources/css/app.css`; components write no hex values.

## Every view of the Cart goes through useCart()
The Cart (ADR-0029) is `lib/cart.ts` (localStorage `bongotea.cart`, pure helpers Vitest-tested in `cart.test.ts`) read only through `useCart()` in `lib/use-cart.ts`, which prices it with `lib/pricing.ts` from the shared `pricing` prop (`StorefrontShell::pricing()`: every published Product's Tiers + isAvailable, and the Delivery Bands). Never read localStorage or keep a second quantity in a component. `useSyncExternalStore` gives SSR an empty Cart, so nothing that touches `window` may run during render. Writes drop lines for Products not Available/not sent (`onlyOrderable`), so an unavailable tea never comes back unasked. A Bulk Order row is addressed as `#tea-{id}` via `rowAnchor`/`rowFromAnchor`.
