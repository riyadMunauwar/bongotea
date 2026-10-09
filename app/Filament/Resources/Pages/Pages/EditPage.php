<?php

namespace App\Filament\Resources\Pages\Pages;

use App\Enums\Permission;
use App\Filament\Resources\Pages\PageResource;
use App\Models\Page;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPage extends EditRecord
{
    protected static string $resource = PageResource::class;

    /**
     * A System Page draws no delete at all, whoever is looking: the model
     * would refuse it anyway.
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->hidden(fn (Page $record): bool => $record->isSystemPage())
                ->authorize(fn (): bool => auth()->user()?->can(Permission::DeletePages) ?? false),
        ];
    }
}
