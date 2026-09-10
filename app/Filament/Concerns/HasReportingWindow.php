<?php

namespace App\Filament\Concerns;

use App\Support\Reporting\ReportingPreset;
use App\Support\Reporting\ReportingWindow;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard\Concerns\HasFilters;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;

/**
 * The Reporting Window control, and the screen state behind it, shared by
 * every screen that carries a report: the Dashboard draws it as a filter
 * form above its widgets, and the Page edit screen offers it as a header
 * action beside its own. Both read the same presets, so a Merchant moving
 * between the two meets one control rather than two that resemble each
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
     * Every field is live because each one is read by another: the pickers
     * appear on the select, and each picker bounds the other. The Dashboard
     * gets that for free — `HasFiltersForm` makes the whole filter schema
     * live — but a modal's schema does not, and a bound that never
     * re-evaluates is a calendar that offers a date the form then refuses.
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
     * What the chosen window is called where the control itself is not on
     * screen — a screen that keeps it behind a header action still has to
     * say which days its figures cover, or a report pinned to Today reads
     * as a Page that never sold. The preset's own name, or the two days a
     * Custom window runs between as the Merchant reads them.
     */
    protected function reportingWindowName(): string
    {
        $window = ReportingWindow::fromFilters($this->filters);

        if ($window->preset !== ReportingPreset::Custom) {
            return $window->preset->label();
        }

        return $window->localStart()->format('j M').' – '.$window->localEnd()->subDay()->format('j M Y');
    }
}
