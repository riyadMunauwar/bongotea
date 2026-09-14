<?php

namespace App\Filament\Clusters\Inventory\Resources\Supplies\Pages;

use App\Filament\Clusters\Inventory\Resources\Supplies\SupplyResource;
use Filament\Resources\Pages\ViewRecord;

class ViewSupply extends ViewRecord
{
    protected static string $resource = SupplyResource::class;

    /**
     * Nothing to edit and nothing to delete: every figure the ledger stamped
     * since was taken from what this document said, so correcting it quietly
     * would move a margin somebody has already read. Voiding it is the action
     * that belongs here, and it arrives with the ticket that writes the
     * reversal.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
