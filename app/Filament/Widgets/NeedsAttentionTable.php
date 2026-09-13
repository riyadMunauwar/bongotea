<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Widgets\Concerns\PaginatesRows;
use App\Support\Reporting\NeedsAttentionRow;
use App\Support\Reporting\ReportingQuery;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The Needs Attention list, full width at the foot of the Dashboard above
 * the Page leaderboard: the Orders an operator must act on now, whatever
 * window the rest of the screen is counted over. One row per Order per
 * condition — New for over a day, a Payment Pending for over an hour, Paid
 * twice, Paid and Cancelled, a parcel that came back, a parcel nobody has
 * heard about in a week — naming the condition and what to do about it, and
 * every row opens the Order so acting on it is one click.
 *
 * A to-do list, not a report: it reads no page filter, and when there is
 * nothing to do it says so in words rather than drawing an empty table, so
 * silence is never mistaken for a broken widget. Ten rows at a time, so a
 * morning's backlog is worked through a page at a time rather than run on
 * down the screen.
 *
 * **It says in its own words that it ignores the window**, because it no
 * longer says it by position. It sat above the Reporting Window filter for
 * exactly that reason; now that both full-width tables are together at the
 * foot of the screen it is drawn under that filter like every figure that
 * does obey it, and a to-do list silently read as filtered is a to-do list
 * an operator trusts to be empty. The subheading is the whole of that
 * signal, so it cannot be dropped as decoration.
 *
 * The rows come whole from `ReportingQuery::needsAttention()`; the widget
 * labels and links and never counts. Lazy, like every report widget, so
 * the shell renders before the queries.
 */
class NeedsAttentionTable extends TableWidget
{
    use PaginatesRows;

    /**
     * Second from last, under the figures and above the Page leaderboard —
     * the two full-width tables together at the foot of the Dashboard, so
     * neither pushes the tiles and charts down the screen.
     */
    protected static ?int $sort = 70;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Needs Attention')
            ->description('Every Order waiting on someone, whatever the Reporting Window above says.')
            ->records(fn (int|string $page, int|string $recordsPerPage): LengthAwarePaginator => $this->paginateRows($this->rows(), $page, $recordsPerPage))
            ->columns([
                TextColumn::make('order_number')
                    ->label('Order')
                    ->weight('medium'),

                TextColumn::make('condition')
                    ->badge()
                    ->color('warning'),

                TextColumn::make('action')
                    ->label('To do'),

                TextColumn::make('customer_name')
                    ->label('Customer'),

                TextColumn::make('since')
                    ->since()
                    ->dateTimeTooltip(),
            ])
            ->recordUrl(fn (array $record): string => $record['url'])
            ->emptyStateHeading('Nothing needs attention')
            ->emptyStateDescription('Every New Order is under a day old, no Payment has been Pending for over an hour, no Order is Paid twice or Paid and Cancelled, and every parcel is accounted for.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }

    /**
     * The list as table rows, keyed by Order and condition so an Order in
     * two conditions keeps two rows, in the order the module hands them
     * over.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(): Collection
    {
        return collect(app(ReportingQuery::class)->needsAttention()->rows)
            ->map(fn (NeedsAttentionRow $row): array => [
                ArrayRecord::getKeyName() => $row->order->getKey().':'.$row->reason->name,
                'order_number' => $row->order->order_number,
                'condition' => $row->reason->label(),
                'action' => $row->reason->action(),
                'customer_name' => $row->order->customer_name,
                'since' => $row->since,
                'url' => OrderResource::getUrl('view', ['record' => $row->order]),
            ]);
    }
}
