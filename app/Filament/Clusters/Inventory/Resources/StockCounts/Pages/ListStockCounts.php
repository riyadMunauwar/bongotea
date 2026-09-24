<?php

namespace App\Filament\Clusters\Inventory\Resources\StockCounts\Pages;

use App\Filament\Clusters\Inventory\Resources\StockCounts\Actions\AdjustStockAction;
use App\Filament\Clusters\Inventory\Resources\StockCounts\StockCountResource;
use Filament\Resources\Pages\ListRecords;

class ListStockCounts extends ListRecords
{
    protected static string $resource = StockCountResource::class;

    /**
     * The two everyday verbs a Merchant reaches for — **Increase (+)** and
     * **Decrease (−)** — each doing the arithmetic for them off live On Hand.
     * The exact-count form still exists behind the create route (a stocktake,
     * or adopting a Product into the ledger), but it is no longer surfaced as a
     * header button here.
     */
    protected function getHeaderActions(): array
    {
        return [
            AdjustStockAction::increase(),
            AdjustStockAction::decrease(),
        ];
    }
}
