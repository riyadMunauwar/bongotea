<?php

namespace App\Filament\Clusters\Inventory\Resources\Supplies\Pages;

use App\Filament\Clusters\Inventory\Resources\Supplies\Actions\VoidSupplyAction;
use App\Filament\Clusters\Inventory\Resources\Supplies\SupplyResource;
use Filament\Resources\Pages\ViewRecord;

class ViewSupply extends ViewRecord
{
    protected static string $resource = SupplyResource::class;

    /**
     * Nothing to edit and nothing to delete: every figure the ledger stamped
     * since was taken from what this document said, so correcting it quietly
     * would move a margin somebody has already read. Voiding is the one thing
     * a User may do to it, and it belongs here rather than on the list —
     * beside the lines it is about to take back out.
     */
    protected function getHeaderActions(): array
    {
        return [
            VoidSupplyAction::make(),
        ];
    }
}
