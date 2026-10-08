<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * Deleting a Product is taking it out of the catalogue, and is soft: its
     * address keeps answering with a "no longer sold" page and its slug stays
     * claimed. There is deliberately no force-delete — erasing the row would
     * free the slug and drop the ledger and Order Lines that name it.
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Take out of catalogue')
                ->modalHeading('Take this Product out of the catalogue?')
                ->modalDescription('Buyers following an old link will see that it is no longer sold. You can put it back later.')
                ->successNotificationTitle('Taken out of the catalogue'),
            RestoreAction::make()
                ->label('Put back in catalogue')
                ->successNotificationTitle('Back in the catalogue'),
        ];
    }
}
