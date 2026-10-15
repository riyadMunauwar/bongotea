---
paths:
  - 'app/Support/Menus/**'
  - app/Models/Menu.php
  - app/Models/MenuItem.php
  - 'app/Filament/Resources/Menus/**'
  - app/Filament/Pages/Header.php
  - app/Filament/Pages/Footer.php
  - app/Support/Settings/HeaderSettings.php
  - app/Support/Settings/FooterSettings.php
  - app/Support/Storefront/StorefrontMenus.php
---

# Menus

## A Menu's tree is validated whole and replaced in one transaction
`MenuTree::save()` is the only writer of `menu_items` (ADR-0033). The builder (`EditMenu`) holds the tree as page state and submits all of it on Save; `save()` validates the whole tree first (depth ≤ `MAX_DEPTH` 3, each item's target fits its `MenuLinkType`, every Page, Category and Product exists — a trashed Product does not — URLs match `StorefrontMenus::ALLOWED_URL`, and an All Categories item holds no links and sits above level 3, since its Categories are the level beneath it) and throws `MenuTreeRefused` with the offending item's index path, writing nothing. Then it deletes the old items and inserts the new in one transaction and logs one `menu_saved` activity entry. The builder's drag limit and form validation are conveniences, never the guard. Never add a per-item write (move, rename) that bypasses it: the storefront would draw a half-finished tree and the history would read as every drag. `EditMenu` fills the name rather than saving it, so `MenuTree`'s `touch()` writes it only once the tree passes.

## "Assigned" is asked of the place groups, never restated
A Menu holds nothing about where it appears. Each place's settings group holds the Menu id (`HeaderSettings::$menuId`, `FooterSettings`' columns and `$bottomMenuId`) and answers `placesOf(int $menuId): list<string>` in the Merchant's words ("Header", "Footer column “অর্ডার”", "Footer bottom line"). `MenuPlaces::of()` asks every place group; a new place joins by being asked there. Never add a `place` column, pivot or cached list on `menus`. `Menu::booted()` refuses deletion while any place draws it (`MenuIsAssigned`), and `MenuResource::refuseDeletingAssigned()` checks first on screen so the refusal is a notification naming the places, not an error page. The Menus list has no bulk delete for the same reason.

## Category, Product and All Categories links resolve against what the storefront shows
A Category link is drawn while the Category exists (every Category has a reachable page); a Product link only while the Product is published and not trashed. All Categories stores no children: `StorefrontMenus` fills them at draw time from `Catalogue::categories()` (display order: sort_order, then name), sharing the request's one query with Blocks, and drops the item when there are none or when it sits where its Categories would exceed the place's depth (so it never draws in the one-level footer). Never store or snapshot its Categories as `menu_items`. `Catalogue` is scoped, so a test making two requests sees the first request's Categories; create them before the first request.

## Header order buttons name their Carts; unset falls back to the starting Cart
`retailCartId`/`bulkCartId` choose the Carts খুচরা অর্ডার করুন and পাইকারি অর্ডার করুন open; read them only through `cartFor($kind)`, which falls back to `Cart::startingFor($kind)` when unset (so upgrades need no data migration). The storefront gets them as the shared `headerCarts` prop (`StorefrontShell::headerCarts()`), null for a closed Cart, whose button is then not drawn. There is no `links.bulkOrder`/`links.packetBasket` and no bulk/retail System Page: every link names a Cart. Install writes only these two keys into the header row, and only for Carts made in that run.
