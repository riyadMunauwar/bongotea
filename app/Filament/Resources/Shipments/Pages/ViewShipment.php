<?php

namespace App\Filament\Resources\Shipments\Pages;

use App\Filament\Resources\Shipments\ShipmentResource;
use Filament\Resources\Pages\ViewRecord;

class ViewShipment extends ViewRecord
{
    protected static string $resource = ShipmentResource::class;

    /**
     * Nothing to edit: every field is either what a Courier was told or what
     * it has since said, and neither is a thing a User may retype. What a
     * User can still do about a parcel — write it off, send the Order again —
     * is done from the Order, which is where the decision belongs.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
