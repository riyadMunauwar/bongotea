---
paths:
  - 'app/Filament/Concerns/**'
---

# Concerns

## The Reporting Window control lives in one trait, drawn two ways
`App\Filament\Concerns\HasReportingWindow` holds Filament's `HasFilters` plus the preset select and the two Custom date pickers. A screen that carries a report uses the trait and draws `reportingWindowComponents()` somewhere: the Dashboard as a filter form (`filtersForm()`), the Page edit screen as a header action's modal schema. Never restate the presets on a new screen.

The widgets are wired by `HasFilters` alone — `getWidgetsSchemaComponents()` passes `$this->filters` as `pageFilters` because the property exists. A screen that applies the window server-side (an action, not a live field) must call `updatedFilters()` afterwards, or the session forgets the choice.

Filament's `HasFilters` reaching a class through two traits is fine; PHP deduplicates the same trait.
