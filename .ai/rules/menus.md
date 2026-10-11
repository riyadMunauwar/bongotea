---
paths:
  - 'app/Support/Menus/**'
  - app/Models/Menu.php
  - app/Models/MenuItem.php
  - 'app/Filament/Resources/Menus/**'
  - app/Filament/Pages/Header.php
  - app/Support/Settings/HeaderSettings.php
  - app/Support/Storefront/StorefrontMenus.php
---

# Menus

## A Menu's tree is validated whole and replaced in one transaction
`MenuTree::save()` is the only writer of `menu_items` (ADR-0033). The builder (`EditMenu`) holds the tree as page state and submits all of it on Save; `save()` validates the whole tree first (depth ≤ `MAX_DEPTH` 3, each item's target fits its `MenuLinkType`, every Page exists, URLs match `StorefrontMenus::ALLOWED_URL`) and throws `MenuTreeRefused` with the offending item's index path, writing nothing. Then it deletes the old items and inserts the new in one transaction and logs one `menu_saved` activity entry. The builder's drag limit and form validation are conveniences, never the guard. Never add a per-item write (move, rename) that bypasses it: the storefront would draw a half-finished tree and the history would read as every drag. `EditMenu` fills the name rather than saving it, so `MenuTree`'s `touch()` writes it only once the tree passes.

## "Assigned" is asked of the place groups, never restated
A Menu holds nothing about where it appears. Each place's settings group holds the Menu id (`HeaderSettings::$menuId`, the footer's columns and bottom line next) and answers `placesOf(int $menuId): list<string>` in the Merchant's words ("Header"). `MenuPlaces::of()` asks every place group; a new place joins by being asked there. Never add a `place` column, pivot or cached list on `menus`. `Menu::booted()` refuses deletion while any place draws it (`MenuIsAssigned`), and `MenuResource::refuseDeletingAssigned()` checks first on screen so the refusal is a notification naming the places, not an error page. The Menus list has no bulk delete for the same reason.
