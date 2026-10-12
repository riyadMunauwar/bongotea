<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Filament\Widgets\Concerns\DescribesChange;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\ReportingWindow;
use App\Support\Reporting\VisitsByPlace;
use App\Support\Reporting\VisitsByPlaceRow;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Collection;

/**
 * Most visited, half width beside Top Customers: the ten places of the store
 * that drew the most Visits in the Reporting Window, each with its share of
 * the store's Visits and its change against the comparison window.
 *
 * Ten and no more, unpaged: the top of the list is the whole of what a
 * Dashboard widget is for, and the full list is the Visits Report's.
 *
 * The rows come whole from `ReportingQuery::visitsByPlace()`; nothing here
 * counts or re-orders. Lazy, like every report widget.
 */
class MostVisitedTable extends TableWidget implements GatedOnDashboard
{
    use DescribesChange;
    use InteractsWithPageFilters;

    /**
     * How many places the Dashboard names.
     */
    public const int LISTED = 10;

    protected static ?int $sort = 56;

    private ?VisitsByPlace $places = null;

    /**
     * A Merchant's figure, drawn on the Dashboard behind `view reports`.
     */
    public static function dashboardPermission(): Permission
    {
        return Permission::ViewReports;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Most visited')
            ->description(fn (): string => 'The top '.self::LISTED.'. Every place is on the Visits Report.')
            ->records(fn (): Collection => $this->rows())
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->label('Place')
                    ->weight('medium'),

                TextColumn::make('kind')
                    ->label('')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('visits')
                    ->numeric()
                    ->alignEnd(),

                TextColumn::make('share')
                    ->alignEnd()
                    ->placeholder('—'),

                TextColumn::make('change')
                    ->label('Change')
                    ->alignEnd(),
            ])
            ->emptyStateHeading(fn (): string => $this->visitsByPlace()->isCounted() ? 'No Visits in this window' : 'Visits are not counted')
            ->emptyStateDescription(fn (): string => $this->visitsByPlace()->isCounted()
                ? 'A place appears here once a page of the store is loaded inside the Reporting Window.'
                : 'Switch Visit counting on in Settings → Tracking to see where Visits go.');
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(): Collection
    {
        $places = $this->visitsByPlace();

        return collect($places->rows)
            ->take(self::LISTED)
            ->map(fn (VisitsByPlaceRow $row): array => [
                ArrayRecord::getKeyName() => $row->key(),
                'name' => $row->name(),
                'kind' => $row->kind->getLabel(),
                'visits' => $row->visits,
                'share' => $this->percent($row->shareOf((int) $places->visits)),
                'change' => $this->change($row->visits, $row->previousVisits),
            ]);
    }

    private function visitsByPlace(): VisitsByPlace
    {
        return $this->places ??= app(ReportingQuery::class)->visitsByPlace(ReportingWindow::fromFilters($this->pageFilters));
    }
}
