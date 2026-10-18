<?php

namespace App\Filament\Clusters\Inventory\Resources\AdjustmentCauses\Pages;

use App\Filament\Clusters\Inventory\Resources\AdjustmentCauses\AdjustmentCauseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Width;

/**
 * A reason is a name and a direction, made and changed in a dialog over the
 * list.
 */
class ManageAdjustmentCauses extends ManageRecords
{
    protected static string $resource = AdjustmentCauseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add reason')
                ->modalWidth(Width::Medium),
        ];
    }
}
