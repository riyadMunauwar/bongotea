<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Widgets\Concerns\PaginatesRows;
use App\Models\Order;
use App\Support\Reporting\AttentionReason;
use App\Support\Reporting\NeedsAttentionRow;
use App\Support\Reporting\ReportingQuery;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The Needs Attention list, full width at the foot of the Dashboard: what
 * an operator must act on now, whatever window
 * the rest of the screen is counted over. One row per condition — New for
 * over a day, a Payment Pending for over an hour, Paid twice, Paid and
 * Cancelled, a parcel that came back, a parcel that half arrived and nobody
 * has accounted for, a parcel nobody has heard about in a week, and a shelf
 * that will not outlast the rate it is going out at — naming the condition
 * and what to do about it, and every row opens the record so acting on it is
 * one click.
 *
 * Seven of the eight are about an Order and the eighth is about a Product,
 * which is why the first column names a record rather than an Order and why
 * two columns are blank on a stock row: nobody is waiting on a reply, and no
 * day is the day a shelf crossed the line. What that row carries instead is
 * the figure — five Products all running out is not a list anybody can work
 * in order.
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
class NeedsAttentionTable extends TableWidget implements GatedOnDashboard
{
    use PaginatesRows;

    /**
     * Under the figures, at the foot of the Dashboard, so it never pushes
     * the tiles and charts down the screen.
     */
    protected static ?int $sort = 70;

    /**
     * An operator's to-do, drawn on the Dashboard behind `work orders`
     * (#161): every row links into the Order or Product it names, which a
     * User without `work orders` could not open — so a to-do list of refusals
     * is worse than none. Drawn only here, so the gate has no other screen to
     * be inert on.
     */
    public static function dashboardPermission(): Permission
    {
        return Permission::WorkOrders;
    }

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Needs Attention')
            ->description('Everything waiting on someone, whatever the Reporting Window above says.')
            ->records(fn (int|string $page, int|string $recordsPerPage): LengthAwarePaginator => $this->paginateRows($this->rows(), $page, $recordsPerPage))
            ->columns([
                TextColumn::make('name')
                    ->label('Order or Product')
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
            ->emptyStateDescription('Every New Order is under a day old, no Payment has been Pending for over an hour, no Order is Paid twice or Paid and Cancelled, every parcel is accounted for, and no shelf is running out.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }

    /**
     * The list as table rows, keyed by record and condition so an Order in
     * two conditions keeps two rows, in the order the module hands them
     * over.
     *
     * The key carries what kind of record it is as well as its id, because
     * the conditions now name two kinds and an Order and a Product can share
     * a number.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(): Collection
    {
        return collect(app(ReportingQuery::class)->needsAttention()->rows)
            ->map(fn (NeedsAttentionRow $row): array => [
                ArrayRecord::getKeyName() => class_basename($row->subject).':'.$row->subject->getKey().':'.$row->reason->name,
                'name' => $row->name(),
                'condition' => self::condition($row),
                'action' => $row->reason->action(),
                'customer_name' => $row->customerName(),
                'since' => $row->since,
                'url' => self::url($row),
            ]);
    }

    /**
     * The condition as the badge reads it: the reason's own sentence, and the
     * figure behind it where the reason is one that carries a figure.
     *
     * Branching on the reason and not on whether a figure happens to be there:
     * a shelf running out always has one, so a row of that reason arriving
     * without it is a defect to be seen rather than a suffix to drop in
     * silence.
     *
     * A day and a bit rather than 1.4285714, and never more precision than a
     * rate read over four weeks can honestly carry.
     */
    private static function condition(NeedsAttentionRow $row): string
    {
        if ($row->reason !== AttentionReason::StockRunningOut) {
            return $row->reason->label();
        }

        return $row->reason->label().' — '.number_format((float) $row->daysOfCover, 1).' days of cover';
    }

    /**
     * Where the row goes when it is clicked: the Order for a condition about
     * an Order, and a Product's own stock history for a shelf running out,
     * which is where the movements emptying it are read.
     */
    private static function url(NeedsAttentionRow $row): string
    {
        return $row->subject instanceof Order
            ? OrderResource::getUrl('view', ['record' => $row->subject])
            : ProductResource::getUrl('stock', ['record' => $row->subject]);
    }
}
