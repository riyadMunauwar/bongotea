<?php

namespace App\Filament\Clusters\Inventory\Resources\AdjustmentCauses\Schemas;

use App\Enums\AdjustmentDirection;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Schema;

class AdjustmentCauseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                /** Renaming fixes the word on every Adjustment that carries it: it is the same reason, spelled right. */
                TextInput::make('name')
                    ->label('Reason')
                    ->required()
                    ->maxLength(100)
                    ->unique(ignoreRecord: true),

                /** Which changes it is offered for in the Adjust stock dialog. */
                ToggleButtons::make('direction')
                    ->options(AdjustmentDirection::class)
                    ->inline()
                    ->required(),
            ]);
    }
}
