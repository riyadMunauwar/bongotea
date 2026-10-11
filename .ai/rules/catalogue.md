---
paths:
  - 'app/Models/Product.php'
  - 'app/Models/Category.php'
  - 'app/Models/Brand.php'
  - 'app/Models/Tag.php'
  - 'app/Enums/CategoryTheme.php'
  - 'app/Enums/ProductStatus.php'
  - 'app/Filament/Resources/Products/**'
  - 'app/Filament/Resources/Categories/**'
  - 'app/Filament/Resources/Brands/**'
  - 'app/Filament/Resources/Tags/**'
---

# Catalogue

## Categories, Brands and Tags sit under Products and share its gate
Each declares `$navigationParentItem = 'Products'` (sorted Categories 0, Brands 1, Tags 2), so the top-level order in `AdminShellTest` is unchanged; `CatalogueScreensTest` asserts the child order. Their `canAccess()` delegates to `ProductResource::canAccess()` (`manage catalogue`), so the four open and close together. Category and Brand deletion is offered only while no Product, trashed included, names it (`products()` is `withTrashed()`, FKs are restrict) — so no bulk delete there.

## A Product's slug locks at first publish, enforced in the model
`first_published_at` is stamped the first time status becomes Published and never cleared, so pulling a Product back to draft keeps the lock. `Product::booted()` throws `LogicException` on any slug change once the *original* `first_published_at` is set; the form only mirrors this by disabling the field (a disabled field is not dehydrated). Renaming stays open. Never add a path that rewrites the slug, and never force-delete a Product: the soft delete is "taken out of the catalogue" and keeps the slug claimed (the unique index covers trashed rows).

## Category Themes are code; a Category stores only the key
Premium, Gold and Silver tokens are copied exactly from the mockup's SEGS (c, bgc, panel, fg, sub, accent, pillBg, pillFg, tickBg, line → text, tint, panel, panelText, panelSubtext, accent, pillBackground, pillText, tickBackground, line); a test pins them. `theme_key` is a plain string, not an enum cast, so a key whose case was removed still loads: read it through `Category::theme()`, which falls back to Neutral. Adding a theme means adding a case with all ten tokens.

## A Product's price is its first Tier's, written by the model — set Tiers, not price
Each Product owns its Tiers (`tiers` JSON, `{min, max|null, price}` in minor units, ADR-0031). `price` is kept only as the first Tier's price (tracking and reports read it); `Product::booted()` writes it from `tiers[0]`. It is not the MRP: `mrp` is a separate, required column every Saving is measured from, and `booted()` throws `LogicException` when it is missing or any Tier Price exceeds it (`ProductTiers::mrpProblem()`, which the form also raises on the MRP field). `ProductFactory` defaults `mrp` to the first Tier's price, so a test raising a flat Product's `price` above that must give an `mrp` too. A price set alone on a flat Product (one Tier, or none yet — every factory call) becomes its single 1+ kg Tier; a price set alone on a laddered Product throws `LogicException` rather than silently flattening it. Tiers that leave a gap or rise in price also throw on save (`ProductTiers::problem()`), since `PriceBook` assumes both. Read prices through `ProductPrices::of()` / `priceTiers()`, never `price`, for anything weight-dependent.
