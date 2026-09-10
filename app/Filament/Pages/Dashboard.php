<?php

namespace App\Filament\Pages;

use App\Support\Reporting\ReportingPreset;
use App\Support\Reporting\ReportingWindow;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * The panel's own Dashboard, owning the panel root.
 *
 * It is subclassed rather than registered as Filament's, so the screen the
 * panel opens onto is a file in this application that a widget can be given a
 * home on. It carried none for a while: the two Filament ships by default — a
 * card greeting the User by a name they already know, and the framework's
 * version number — are the reason the Dashboard was dropped once, and they were
 * never what earned it back.
 *
 * `App\Filament\Widgets\CachePathIndicator` is what did: glanceable,
 * per-installation operational truth, surfaced nowhere else in the panel. The
 * report widgets came after it and sit above it, in the order each one's sort
 * property says, all of them discovered rather than listed here.
 *
 * The Reporting Window is the one thing the page itself holds: a filter form
 * every report widget reads through Filament's page filters and hands to
 * `ReportingWindow::fromFilters()`, so every figure on the screen is counted
 * over the same days. Filament keeps the chosen filter in the session, which
 * is what makes it survive a visit to the Orders list and back.
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    /**
     * The preset select, and the two date pickers that appear only once
     * Custom is chosen. Last 7 days is the default so a quiet morning with
     * no Orders yet still opens onto something. Each picker bounds the other
     * so the dates cannot be picked the wrong way round; the window builder
     * tolerates it anyway, for a session that stored them before the bound
     * was there.
     */
    public function filtersForm(Schema $schema): Schema
    {
        $isCustom = fn (Get $get): bool => $get(ReportingWindow::PRESET_FILTER) === ReportingPreset::Custom->value;

        return $schema
            ->components([
                Select::make(ReportingWindow::PRESET_FILTER)
                    ->label('Reporting Window')
                    ->options(ReportingPreset::labels())
                    ->default(ReportingPreset::Last7Days->value)
                    ->selectablePlaceholder(false),
                DatePicker::make(ReportingWindow::FROM_FILTER)
                    ->label('From')
                    ->visible($isCustom)
                    ->maxDate(fn (Get $get): ?string => $get(ReportingWindow::UNTIL_FILTER)),
                DatePicker::make(ReportingWindow::UNTIL_FILTER)
                    ->label('Until')
                    ->visible($isCustom)
                    ->minDate(fn (Get $get): ?string => $get(ReportingWindow::FROM_FILTER)),
            ]);
    }
}
