<?php

namespace App\Filament\Clusters\Inventory\Resources\Suppliers\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SupplierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                /** One section across the full width, for the reason `StockCountForm`'s is. */
                Section::make('Who you buy from')
                    ->description('Recorded once, so that everything ever bought from them reads as one buying history.')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255),

                        /**
                         * Left as written, unlike a Customer's. A Customer
                         * *is* their number, so theirs is stored canonical to
                         * keep two spellings from forking into two people; a
                         * Supplier is identified by this record, and a
                         * supplier's number is as likely to be a landline or
                         * a shop's second line as anything a courier could
                         * ring.
                         */
                        TextInput::make('phone')
                            ->label('Phone')
                            ->tel()
                            ->maxLength(32),

                        Textarea::make('note')
                            ->label('Note')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
