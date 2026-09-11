<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\PaginatesRows;
use App\Support\Money;
use App\Support\Reporting\DeliveryMix;
use App\Support\Reporting\DeliveryMixRow;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\ReportingWindow;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The Delivery mix, half width beside Top Products: one row per Delivery
 * Option label the Reporting Window's Orders recorded, with the Orders
 * that chose it and the Delivery Charge it collected, so a Merchant can
 * judge delivery pricing. Where those Orders were bound is the same
 * question asked of the map, so the five Districts named most often sit
 * under the heading rather than in a table of their own.
 *
 * Both halves read the Orders that are not Cancelled, which is what makes
 * the column a charge collected rather than a charge quoted; the counting
 * is `ReportingQuery::deliveryMix()`'s, and nothing here counts. The
 * result is asked for once per render and read twice, because the heading
 * and the rows are two halves of one answer.
 *
 * Lazy, like every report widget, so the shell renders before the query.
 */
class DeliveryMixTable extends TableWidget
{
    use InteractsWithPageFilters;
    use PaginatesRows;

    protected static ?int $sort = 70;

    /**
     * The window's mix, once per render. Private, so Livewire neither
     * carries it between requests nor tries to serialise it.
     */
    private ?DeliveryMix $mix = null;

    /**
     * A new Reporting Window is a new question, answered from the top.
     *
     * Filament resets the table page for every filter it owns and not for a
     * page filter. This table is short — one row per Delivery Option — so the
     * second page is rare, but an installation that has retired a few labels
     * can reach it, and a narrowed window would then leave it asking for a
     * page the mix no longer has.
     */
    public function updatedPageFilters(): void
    {
        $this->resetPage();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Delivery mix')
            ->description(fn (): ?string => $this->districts())
            ->records(fn (int|string $page, int|string $recordsPerPage): LengthAwarePaginator => $this->paginateRows($this->rows(), $page, $recordsPerPage))
            ->columns([
                TextColumn::make('label')
                    ->label('Delivery Option')
                    ->weight('medium')
                    ->placeholder('Not recorded'),

                TextColumn::make('orders')
                    ->numeric()
                    ->alignEnd(),

                TextColumn::make('delivery_charge')
                    ->label('Collected')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->alignEnd(),
            ])
            ->emptyStateHeading('No Orders in this window')
            ->emptyStateDescription('A Delivery Option appears here once an Order that chose it is captured inside the Reporting Window.');
    }

    /**
     * The mix as table rows, keyed for Filament, in the module's order —
     * most Orders first. A label a row does not carry stays null so the
     * column draws its own placeholder rather than an invented name.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(): Collection
    {
        return collect($this->mix()->rows)->map(fn (DeliveryMixRow $row, int $index): array => [
            ArrayRecord::getKeyName() => $row->label === null ? 'unlabelled:'.$index : 'label:'.$row->label,
            'label' => $row->label,
            'orders' => $row->orders,
            'delivery_charge' => $row->deliveryCharge,
        ]);
    }

    /**
     * Where the window's Orders were bound, as one line under the heading:
     * the five Districts named most often with their Orders beside them.
     * Null when nothing recorded a District, so no empty sentence is drawn.
     */
    private function districts(): ?string
    {
        $districts = $this->mix()->districts;

        if ($districts === []) {
            return null;
        }

        $named = collect($districts)
            ->map(fn (int $orders, string $district): string => "{$district} ({$orders})")
            ->implode(', ');

        return 'Most Orders to '.$named;
    }

    private function mix(): DeliveryMix
    {
        return $this->mix ??= app(ReportingQuery::class)->deliveryMix(ReportingWindow::fromFilters($this->pageFilters));
    }
}
