---
paths:
  - 'app/Filament/**'
---

# Filament

## Filament evaluates a closure's parameters by name — call the query $query
`->modifyQueryUsing(fn (Builder $products) => ...)` is handed **null**, not the query: Filament's `evaluate()` resolves closure arguments by parameter *name* first, and only a few names are registered (`$query`, `$record`, `$state`, `$livewire`, `$get`, `$set`, …). The type hint does not save it. The failure is a fatal deep inside a Blade view — "Call to a member function stockMovements() on null (View: …/tables/index.blade.php)" — which reads like a broken relation and is not one.

Name it `$query` (and `$record`, `$state`) in every Filament closure, however much a domain name would read better.

## Every picker is non-native by default — set once in AdminPanelProvider
`AdminPanelProvider::boot()` calls `configureUsing(... ->native(false))` on `Select`, `DateTimePicker` (which covers `DatePicker`/`TimePicker`) and `SelectFilter`. Do not add `->native(false)` to a field; it is already the default, and a field that genuinely wants the browser control says `->native()`. Searchability is not global: add `->searchable()` on a list that grows or runs past about six options (Category, Brand, Tags, Product, Supplier, District, a Courier's stores), and leave short fixed enums (status, reason, theme) plain.

## An image upload is sized through `App\Enums\ImageSlot`, never by hand
Every `FileUpload`/`SpatieMediaLibraryFileUpload` of an image goes through `ImageSlot::X->fit($upload)`, which writes the size advice as helper text and, for a fixed-shape slot, opens the crop editor at that shape (viewport plus `[ratio, Free]` options) and draws a single upload at it. It never calls `imageAspectRatio()`: that adds a validation rule refusing any other shape, and the agreed behaviour is to guide, not enforce. A second `helperText()` replaces the first, so a field with its own words appends `ImageSlot::X->advice()`. A new storefront image box means a new case whose size matches the box's CSS.

## Small records are edited in dialogs, not pages
Brands and Tags are `ManageRecords` resources (create/edit in a modal over the list), and a Stock Count is taken through the `count` header action beside Increase and Decrease. The big forms (Product, Category) use a three-column grid: content `Group` spanning 2, a sidebar `Group` of small Sections spanning 1, stacking on phones. A Page puts its title strip over a full-width Blocks builder.
