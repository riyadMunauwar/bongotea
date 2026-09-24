<?php

namespace App\Filament\Clusters\Inventory\Resources\StockCounts\Pages;

use App\Filament\Clusters\Inventory\Resources\StockCounts\Actions\AdjustStockAction;
use App\Filament\Clusters\Inventory\Resources\StockCounts\StockCountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStockCounts extends ListRecords
{
    protected static string $resource = StockCountResource::class;

    /**
     * Three ways to reach an Adjustment, and they read as one thing from
     * across: the everyday **Increase (+)** and **Decrease (−)** that do the
     * arithmetic for the Merchant, and **Set exact count** for a stocktake or
     * for adopting a Product into the ledger. The exact-count path is the
     * relabelled create button — "Count stock" was the developer's word, and a
     * Merchant reaches for setting the count they just took.
     */
    protected function getHeaderActions(): array
    {
        return [
            AdjustStockAction::increase(),
            AdjustStockAction::decrease(),
            CreateAction::make()->label('Set exact count'),
        ];
    }
}
