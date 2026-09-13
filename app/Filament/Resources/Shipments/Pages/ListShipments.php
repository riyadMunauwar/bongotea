<?php

namespace App\Filament\Resources\Shipments\Pages;

use App\Filament\Resources\Shipments\ShipmentResource;
use Filament\Resources\Pages\ListRecords;

class ListShipments extends ListRecords
{
    protected static string $resource = ShipmentResource::class;

    /**
     * No create action: a Shipment exists because a Courier accepted a
     * parcel. One is started from the Order it is for, with "Send with
     * courier".
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
