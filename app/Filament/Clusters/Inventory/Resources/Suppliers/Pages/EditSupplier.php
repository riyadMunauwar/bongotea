<?php

namespace App\Filament\Clusters\Inventory\Resources\Suppliers\Pages;

use App\Filament\Clusters\Inventory\Resources\Suppliers\SupplierResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditSupplier extends EditRecord
{
    protected static string $resource = SupplierResource::class;

    /**
     * Taken off the list and put back, and never erased: the deliveries a
     * Supplier made are read under their name for as long as the ledger
     * holds them, so there is no `ForceDeleteAction` here.
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
