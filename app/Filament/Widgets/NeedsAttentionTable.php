<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Orders\OrderResource;
use App\Support\Reporting\NeedsAttentionRow;
use App\Support\Reporting\ReportingQuery;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Collection;

/**
 * The Needs Attention list, pinned first on the Dashboard and above the
 * Reporting Window filter: the Orders an operator must act on now, whatever
 * window the rest of the screen is counted over. One row per Order per
 * condition — New for over a day, a Payment Pending for over an hour, Paid
 * twice, Paid and Cancelled — naming the condition and what to do about
 * it, and every row opens the Order so acting on it is one click.
 *
 * A to-do list, not a report: it reads no page filter, and when there is
 * nothing to do it says so in words rather than drawing an empty table, so
 * silence is never mistaken for a broken widget.
 *
 * The rows come whole from `ReportingQuery::needsAttention()`; the widget
 * labels and links and never counts. Lazy, like every report widget, so
 * the shell renders before the queries.
 */
class NeedsAttentionTable extends TableWidget
{
    /**
     * Ahead of every report widget, which sort in the tens from 20. The
     * Dashboard places it in its header slot on the strength of this
     * being first.
     */
    protected static ?int $sort = 10;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Needs Attention')
            ->records(fn (): Collection => $this->rows())
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
            ->emptyStateDescription('Every New Order is under a day old, no Payment has been Pending for over an hour, and no Order is Paid twice or Paid and Cancelled.')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->paginated(false);
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
