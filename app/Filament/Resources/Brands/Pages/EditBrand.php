<?php

namespace App\Filament\Resources\Brands\Pages;

use App\Filament\Resources\Brands\BrandResource;
use App\Models\Brand;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBrand extends EditRecord
{
    protected static string $resource = BrandResource::class;

    /** Only for a Brand no Product carries, for the reason `EditCategory` gives. */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (Brand $record): bool => ! $record->products()->exists()),
        ];
    }
}
