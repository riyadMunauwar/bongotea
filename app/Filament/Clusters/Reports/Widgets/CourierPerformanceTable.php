<?php

namespace App\Filament\Clusters\Reports\Widgets;

use App\Support\Money;
use App\Support\Reporting\CourierPerformanceRow;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\ReportingWindow;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Collection;

/**
 * The Courier Report itself: one row per Courier over the Reporting Window,
 * with what it was handed, what became of it, how often and how fast it
 * delivered, and what it charged.
 *
 * **There is no total row and there must never be one** (ADR-0018). A
 * canonical state a given Courier never emits is expected rather than a
 * defect — Steadfast never reports In Transit, RedEx never reports Unknown —
 * and a blended cross-courier figure is the one view in which that asymmetry
 * would read as fact. Every column here is answered per Courier or not at
 * all.
 *
 * Three of the columns draw a sentence where they have no figure rather than
 * a nought, which is the same distinction the module draws: nothing carried
 * is a count of zero, but a rate with no parcels under it is unknown, and a
 * carriage charge a Courier has never named is unknown rather than free.
 *
 * It lives beside the cluster that draws it rather than in
 * `App\Filament\Widgets` — which the panel discovers, and everything the
 * panel discovers is drawn on the Dashboard. The Dashboard carries the
 * courier to-do list, which ignores the Reporting Window; a windowed courier
 * figure kept in the discovered folder would land there whether or not any
 * screen named it.
 *
 * Every report widget belongs in this folder for that reason, and not beside
 * the resource it reports on: a report page's subject need not have a
 * resource behind it at all, which is the shape inventory reporting will
 * arrive in.
 *
 * The counting is `ReportingQuery::courierPerformance()`'s and nothing here
 * counts. Lazy, like every report widget, so the shell renders before the
 * query.
 */
class CourierPerformanceTable extends TableWidget
{
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Courier performance')
            ->description('What each Courier was handed inside the Reporting Window, and what it did with it. Never added up across couriers: they do not report the same things, and one total would present one courier\'s silence as everybody\'s.')

            /**
             * One row per Courier and the registry holds three, so there is
             * no second page to reach and a paginator under three rows is
             * furniture — the same reading `CourierMoneyTable` takes, and for
             * the same reason: these rows are counted from the enum and not
             * from anything that can run on.
             */
            ->paginated(false)
            ->records(fn (): Collection => $this->rows())
            ->columns([
                TextColumn::make('courier')
                    ->label('Courier')
                    ->weight('medium'),

                TextColumn::make('sent')
                    ->label('Parcels sent')
                    ->numeric()
                    ->alignEnd(),

                TextColumn::make('delivered')
                    ->numeric()
                    ->alignEnd(),

                TextColumn::make('returned')
                    ->numeric()
                    ->alignEnd(),

                TextColumn::make('delivery_rate')
                    ->label('Delivery rate')
                    ->formatStateUsing(fn (float $state): string => self::percent($state))
                    ->placeholder('No parcels')
                    ->color(fn (array $record): ?string => $record['delivery_rate'] === null ? 'gray' : null)
                    ->alignEnd(),

                TextColumn::make('return_rate')
                    ->label('Return rate')
                    ->formatStateUsing(fn (float $state): string => self::percent($state))
                    ->placeholder('No parcels')
                    ->color(fn (array $record): ?string => $record['return_rate'] === null ? 'gray' : null)
                    ->alignEnd(),

                TextColumn::make('average_days_to_delivery')
                    ->label('Days to deliver')
                    ->formatStateUsing(fn (float $state): string => number_format($state, 1))
                    ->placeholder('Nothing delivered')
                    ->color(fn (array $record): ?string => $record['average_days_to_delivery'] === null ? 'gray' : null)
                    ->alignEnd(),

                TextColumn::make('charges')
                    ->label('Charges paid')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->placeholder('Not reported')
                    ->color(fn (array $record): ?string => $record['charges'] === null ? 'gray' : null)
                    ->alignEnd(),
            ]);
    }

    /**
     * The module's rows as table rows, keyed by Courier and in the registry's
     * order.
     *
     * An absent figure stays null so the column draws its own placeholder
     * rather than a nought a Merchant would read as an answer — a 0.0%
     * delivery rate is a Courier that fails, where no parcels at all is a
     * Courier that was never tried; and a blank in a money column is read as
     * a zero, which for carriage tells a Merchant it was free.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(): Collection
    {
        $performance = app(ReportingQuery::class)->courierPerformance(ReportingWindow::fromFilters($this->pageFilters));

        return collect($performance->rows)->map(fn (CourierPerformanceRow $row): array => [
            ArrayRecord::getKeyName() => $row->courier->value,
            'courier' => $row->courier->getLabel(),
            'sent' => $row->sent,
            'delivered' => $row->delivered,
            'returned' => $row->returned,
            'delivery_rate' => $row->deliveryRate(),
            'return_rate' => $row->returnRate(),
            'average_days_to_delivery' => $row->averageDaysToDelivery,
            'charges' => $row->charges,
        ]);
    }

    /**
     * A rate from 0 to 1 as the panel writes one, to a single decimal — the
     * same spelling the Cancellation Rate and the Conversion Rate are drawn
     * in, so three rates on three screens read alike.
     */
    private static function percent(float $rate): string
    {
        return number_format($rate * 100, 1).'%';
    }
}
