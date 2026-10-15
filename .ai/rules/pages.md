---
paths:
  - 'app/Support/Pages/**'
  - app/Models/Page.php
  - app/Http/Controllers/PageController.php
  - 'app/Filament/Resources/Pages/**'
  - 'resources/js/components/blocks/**'
  - resources/js/types/blocks.ts
  - app/Support/Pages/CartPlacements.php
---

# Pages

## A Block kind is one PHP definition plus one React component under the same type name
Each kind extends `App\Support\Pages\PageBlock` (Filament `fields()` + `props()`), is listed in `PageBlocks::KINDS`, and has a component in `resources/js/components/blocks/` registered in its `index.tsx` under the same `type()` string, with its props typed in `resources/js/types/blocks.ts`. `props()` maps every key explicitly (never pass stored data through), re-checks links with `StorefrontMenus::ALLOWED_URL`, turns image paths into URLs and sanitises rich text via `RichContentRenderer::toHtml()`. `PageRenderingTest` pins each content kind's props in one dataset — add a row for a new kind; live kinds (category tiles, category panels, product rail), whose props depend on the catalogue, are pinned in `CatalogueBlocksTest` instead. A live kind stores only headings, switches and ids — never a price — and reads the catalogue through `App\Support\Storefront\Catalogue` in `props()`. A Page's top-level slug must not collide with a fixed route: any new first path segment goes into `Page::RESERVED_SLUGS` (the `/{slug}` route is registered last in routes/web.php). System Page delete/role-change is refused in `Page::booted()`, not only in the UI.

## A Cart's Page is found from the Blocks that draw it; open means switched on, not trashed, on a published Page
`CartPlacements::blockTypes()` lists the Block kinds that draw (place) a Cart through `data.cart_id`; a Block that merely works with a Cart (Price Calculator) is not listed. Pages are scanned each call, never cached. `Page::booted()` refuses a Page drawing a Cart twice or one another Page draws (LogicException naming it); `PageBlock::cartInput()` refuses it on the form. `Cart::link()` is its Page's path only while that Page is published; `Cart::isOpen()` also needs the switch on and not trashed. Checkout (`?cart={id}`, POST `cart`) and `OrderCapture::refuseCart()` refuse a closed Cart or one of the other kind; tests use `openCart($kind)` / `CartFactory::onAPage()`.
