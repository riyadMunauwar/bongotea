---
paths:
  - 'app/Filament/**'
---

# Filament

## Filament evaluates a closure's parameters by name — call the query $query
`->modifyQueryUsing(fn (Builder $products) => ...)` is handed **null**, not the query: Filament's `evaluate()` resolves closure arguments by parameter *name* first, and only a few names are registered (`$query`, `$record`, `$state`, `$livewire`, `$get`, `$set`, …). The type hint does not save it. The failure is a fatal deep inside a Blade view — "Call to a member function stockMovements() on null (View: …/tables/index.blade.php)" — which reads like a broken relation and is not one.

Name it `$query` (and `$record`, `$state`) in every Filament closure, however much a domain name would read better.
