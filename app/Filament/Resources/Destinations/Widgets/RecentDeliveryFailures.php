<?php

namespace App\Filament\Resources\Destinations\Widgets;

use App\Models\Destination;
use App\Models\TrackingEvent;
use App\Support\Tracking\DeliveryHealth;
use App\Support\Tracking\TrackingPrune;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Features\SupportPagination\WithoutUrlPagination;

/**
 * The events that never arrived, with what the Provider said about each.
 *
 * Refused and Expired together, because both are an event an ad account was
 * owed and never got — the two are told apart by the badge on the row rather
 * than by being split across two screens, and an operator reading this wants
 * the whole of what did not arrive. The error is the Provider's own wording
 * in both cases, which is the point: a wrong token becomes a thing that can
 * be found rather than a mystery.
 *
 * Backed by a real query rather than a collection, so it pages and sorts
 * through the database — a refusing Destination can produce thousands of
 * rows, and loading thirty days of them into memory to draw ten is not worth
 * it. That is the one reason it does not use `PaginatesRows`; it owes both of
 * that trait's guarantees all the same, and pays them below — a paging key of
 * its own on the table, and the clamp in `paginateTableQuery()`.
 */
class RecentDeliveryFailures extends TableWidget
{
    use WithoutUrlPagination;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Destination::query()->exists();
    }

    /**
     * A page past the end is answered with the last page that exists, never
     * with nothing.
     *
     * Filament's table view draws the **empty state** for a page holding no
     * rows however large the total is, so an out-of-range page here does not
     * read as a short table — it reads as "Nothing has failed" over a list of
     * failures, which is the one thing this screen must never say wrongly.
     * The rows shrink under an open page for an ordinary reason: the nightly
     * prune, or a Destination being deleted, while an operator sits on page
     * three.
     *
     * The re-paginate costs a second query only in the case it is fixing —
     * an empty page past the first, which the normal render never produces.
     */
    protected function paginateTableQuery(Builder $query): CursorPaginator|Paginator
    {
        $records = parent::paginateTableQuery($query);

        if ($records instanceof LengthAwarePaginator && $records->isEmpty() && $records->currentPage() > 1) {
            $this->setPage($records->lastPage(), $this->getTablePaginationPageName());

            return parent::paginateTableQuery($query);
        }

        return $records;
    }

    public function table(Table $table): Table
    {
        return $table
            /**
             * A paging key of this table's own, so it reads `failuresPage`
             * and not `page`. Filament names every table's page plainly
             * `page` otherwise, and Livewire resolves that name straight off
             * the request (`SupportPagination::resolvePage()`) — so the
             * Destinations list on this same screen would hand its page
             * number here, and a page holding no rows draws the **empty
             * state** however large the total is. That reads as "nothing has
             * failed" over a list of failures.
             *
             * It has to be set on the table. Overriding
             * `getTableQueryStringIdentifier()` on the component does
             * nothing: `InteractsWithTable::makeTable()` never reads that
             * method, and `getIdentifiedTableQueryStringPropertyNameFor()`
             * asks the Table.
             */
            ->queryStringIdentifier('failures')
            /**
             * Counted pagination rather than the Next/Previous a widget table
             * gets by default. Two reasons, and the second is the one that
             * matters: an operator wants to know how many failures there are
             * without paging to the end of them, and a simple paginator knows
             * no last page, so there would be nothing to clamp an
             * out-of-range one to. The count is one extra query on a screen
             * nobody opens twice a day.
             */
            ->paginationMode(PaginationMode::Default)
            ->heading('Recent delivery failures')
            ->description('Events a Provider refused, and events that grew too old to be accepted. Kept for '.TrackingPrune::DIAGNOSTIC_RETENTION_DAYS.' days.')
            ->query(fn () => app(DeliveryHealth::class)->failures())
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('updated_at')
                    ->label('When')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),

                TextColumn::make('state')
                    ->badge()
                    ->sortable(),

                TextColumn::make('destination.label')
                    ->label('Destination')
                    ->description(fn (TrackingEvent $record): string => $record->destination->provider->getLabel()),

                TextColumn::make('event')
                    ->label('Event')
                    ->description(fn (TrackingEvent $record): ?string => $record->page_slug),

                /**
                 * The whole of what the Provider said, wrapped rather than
                 * truncated: a Meta refusal names the field it refused, and a
                 * cut-off one names nothing an operator can act on.
                 */
                TextColumn::make('last_error')
                    ->label('What happened')
                    ->wrap()
                    ->placeholder('No reason recorded'),
            ])
            ->emptyStateHeading('Nothing has failed')
            ->emptyStateDescription('Every event written down in the last '.TrackingPrune::DIAGNOSTIC_RETENTION_DAYS.' days either reached its Destination or is still waiting to.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
