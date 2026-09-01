<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use Filament\Resources\Pages\ListRecords;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    /**
     * No create action: an Order exists because a visitor placed one.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
