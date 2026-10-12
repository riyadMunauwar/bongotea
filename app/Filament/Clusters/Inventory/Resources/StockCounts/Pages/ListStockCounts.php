<?php

namespace App\Filament\Clusters\Inventory\Resources\StockCounts\Pages;

use App\Filament\Clusters\Inventory\Resources\StockCounts\Actions\AdjustStockAction;
use App\Filament\Clusters\Inventory\Resources\StockCounts\StockCountResource;
use Filament\Resources\Pages\ListRecords;

class ListStockCounts extends ListRecords
{
    protected static string $resource = StockCountResource::class;

    /**
     * The three verbs a Merchant reaches for — **Set exact count**, **Increase
     * (+)** and **Decrease (−)** — side by side, each opening the same dialog.
     * There is no create page: a count is one Product and one number, which a
     * dialog over the list it lands in holds.
     */
    protected function getHeaderActions(): array
    {
        return [
            AdjustStockAction::exactCount(),
            AdjustStockAction::increase(),
            AdjustStockAction::decrease(),
        ];
    }
}
