<?php

namespace App\Filament\Widgets\Concerns;

use App\Models\Page;

/**
 * The one Page a report widget counts over, or nothing at all.
 *
 * A report widget is one class wherever it is drawn: on the Dashboard the
 * property is null and the figures cover the whole installation, and on the
 * Page edit screen it holds the record being edited and the same figures
 * cover that Page alone. Filament hands a resource page's record to every
 * widget it draws under exactly this name
 * (`InteractsWithRecord::getWidgetData()`), so declaring the property is
 * the whole of the wiring; the scoping itself is `ReportingQuery`'s, which
 * every method already takes a Page for.
 *
 * A trashed Page still reports: Livewire restores a model property through
 * `Model::newQueryForRestoration()`, which drops the soft-delete scope.
 */
trait ScopedToPage
{
    public ?Page $record = null;
}
