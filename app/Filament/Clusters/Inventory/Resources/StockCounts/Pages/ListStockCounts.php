<?php

namespace App\Filament\Clusters\Inventory\Resources\StockCounts\Pages;

use App\Filament\Clusters\Inventory\Resources\StockCounts\Actions\AdjustStockAction;
use App\Filament\Clusters\Inventory\Resources\StockCounts\StockCountResource;
use Filament\Resources\Pages\ListRecords;

class ListStockCounts extends ListRecords
{
    protected static string $resource = StockCountResource::class;

    /**
     * **Adjust stock**, the one act a Merchant reaches for (ADR-0040). There
     * is no create page: an adjustment is one Product and one figure, which a
     * dialog over the list it lands in holds.
     */
    protected function getHeaderActions(): array
    {
        return [
            AdjustStockAction::make(),
        ];
    }
}
