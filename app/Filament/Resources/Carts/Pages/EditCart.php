<?php

namespace App\Filament\Resources\Carts\Pages;

use App\Filament\Resources\Carts\CartResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditCart extends EditRecord
{
    protected static string $resource = CartResource::class;

    /**
     * Deleting is soft: the Orders captured from the Cart, and the Contents
     * Customers still hold in it, keep reading its name, and a deleted Cart
     * can be brought back. Install never remakes a deleted starting Cart, so
     * this is the only way back for one.
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
