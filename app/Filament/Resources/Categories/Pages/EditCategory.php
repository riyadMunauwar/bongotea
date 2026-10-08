<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Resources\Categories\CategoryResource;
use App\Models\Category;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCategory extends EditRecord
{
    protected static string $resource = CategoryResource::class;

    /**
     * Deleting is offered only for a Category no Product names, those taken
     * out of the catalogue included: every Product is in exactly one, and
     * the database refuses to leave one without.
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (Category $record): bool => ! $record->products()->exists()),
        ];
    }
}
