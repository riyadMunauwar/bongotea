<?php

namespace App\Filament\Clusters\Inventory\Resources\StockCounts\Pages;

use App\Filament\Clusters\Inventory\Resources\StockCounts\StockCountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStockCounts extends ListRecords
{
    protected static string $resource = StockCountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Count stock'),
        ];
    }
}
