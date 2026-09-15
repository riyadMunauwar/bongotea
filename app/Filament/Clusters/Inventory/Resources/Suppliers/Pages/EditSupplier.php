<?php

namespace App\Filament\Clusters\Inventory\Resources\Suppliers\Pages;

use App\Filament\Clusters\Inventory\Resources\Suppliers\SupplierResource;
use App\Filament\Clusters\Inventory\Resources\Supplies\SupplyResource;
use App\Models\Supplier;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditSupplier extends EditRecord
{
    protected static string $resource = SupplierResource::class;

    /**
     * Taken off the list and put back, and never erased: the deliveries a
     * Supplier made are read under their name for as long as the ledger
     * holds them, so there is no `ForceDeleteAction` here.
     *
     * What is here instead is the way out to those deliveries. A Supplier is
     * a record rather than a name typed onto each delivery precisely so that
     * everything ever bought from them reads as one buying history, and a
     * User who has this screen open to correct a phone number is one keystroke
     * from the question that brought them — what have we had from these
     * people, and what did they charge. The address is `SupplyResource`'s, so
     * this screen and the Deliveries count on the list open the same rows.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('deliveries')
                ->label('Deliveries')
                ->icon(Heroicon::OutlinedInboxArrowDown)
                ->color('gray')
                ->url(fn (Supplier $record): string => SupplyResource::deliveriesFromUrl($record)),
            DeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
