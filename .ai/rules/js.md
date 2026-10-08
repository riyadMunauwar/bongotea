---
paths:
  - 'resources/js/**'
---

# Js

## Storefront copy, numbers and head tags each have one home
Interface copy lives only in `resources/js/lang/bn.ts`; Merchant-set text arrives as props. Display numbers go through `lib/bangla.ts` (Bangla digits, Indian grouping, `formatTaka`, `formatPhone`), and every numeric/phone input through `toLatinDigits`/`parseWholeNumber`. SEO tags are drawn twice under the same keys: `resources/views/app.blade.php` inside `<x-inertia::head>` (the no-SSR fallback, `data-inertia="key"`) and `components/storefront/seo-head.tsx` (`head-key="key"`, used when SSR answers and on client visits). Add a tag to both or a crawler sees it only half the time. Colours are tokens in `resources/css/app.css`; components write no hex values.
