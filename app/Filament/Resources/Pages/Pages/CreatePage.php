<?php

namespace App\Filament\Resources\Pages\Pages;

use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Pages\Pages\Concerns\FinishesPageSave;
use Filament\Resources\Pages\CreateRecord;

class CreatePage extends CreateRecord
{
    use FinishesPageSave;

    protected static string $resource = PageResource::class;

    protected function afterCreate(): void
    {
        $this->invalidateAndWarn();
    }
}
