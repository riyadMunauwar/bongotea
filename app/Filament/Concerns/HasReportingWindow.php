<?php

namespace App\Filament\Concerns;

use App\Enums\OrderKind;
use App\Support\Reporting\ReportingPreset;
use App\Support\Reporting\ReportingWindow;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard\Concerns\HasFilters;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;

/**
 * The Reporting Window control, and the screen state behind it, shared by
 * every screen that carries a report: the Dashboard and the windowed
 * reports, each of which draws it as a filter form across the top, above the
 * widgets that obey it. All read the same presets, so a Merchant moving
 * between them meets one control rather than several that resemble each
 * other.
 *
 * Filament's `HasFilters` is what the widgets are actually wired to: it
 * holds the chosen window under `$filters`, keeps it in the session per
 * screen, and `Page::getWidgetsSchemaComponents()` hands it to every widget
 * as `pageFilters` because the property exists. A screen using this trait
 * therefore needs no plumbing of its own — only somewhere to draw the
 * components.
 */
trait HasReportingWindow
{
    use HasFilters;

    /**
     * The preset select and the two date pickers that appear only once
     * Custom is chosen. Last 7 days is the default so a quiet morning with
     * no Orders yet still opens onto something. Each picker bounds the
     * other so the dates cannot be picked the wrong way round; the window
     * builder tolerates it anyway, for a session that stored them before
     * the bound was there.
     *
     * Every field says it is live, though a filter form is live already —
     * `HasFiltersForm` makes the whole schema so. It is stated here because
     * each field is read by another: the pickers appear on the select, and
     * each picker bounds the other, and a bound that never re-evaluates is
     * a calendar offering a date the form then refuses.
     *
     * @return array<Component>
     */
    protected function reportingWindowComponents(): array
    {
        $isCustom = fn (Get $get): bool => $get(ReportingWindow::PRESET_FILTER) === ReportingPreset::Custom->value;

        return [
            Select::make(ReportingWindow::PRESET_FILTER)
                ->label('Reporting Window')
                ->options(ReportingPreset::labels())
                ->default(ReportingPreset::Last7Days->value)
                ->selectablePlaceholder(false)
                ->live(),
            DatePicker::make(ReportingWindow::FROM_FILTER)
                ->label('From')
                ->visible($isCustom)
                ->live()
                ->maxDate(fn (Get $get): ?string => $get(ReportingWindow::UNTIL_FILTER)),
            DatePicker::make(ReportingWindow::UNTIL_FILTER)
                ->label('Until')
                ->visible($isCustom)
                ->live()
                ->minDate(fn (Get $get): ?string => $get(ReportingWindow::FROM_FILTER)),
        ];
    }

    /**
     * Bulk, Retail or All (ADR-0034), beside the window on every screen whose
     * figures are counted from Orders; All by default, because Sales is the
     * whole takings. Left off the screens that count no Order — what was
     * bought, who visited — since a control that changes nothing is worse
     * than none.
     */
    protected function orderKindComponent(): Select
    {
        return Select::make(ReportingWindow::KIND_FILTER)
            ->label('Orders')
            ->options(OrderKind::class)
            ->placeholder('Bulk and retail')
            ->live();
    }
}
