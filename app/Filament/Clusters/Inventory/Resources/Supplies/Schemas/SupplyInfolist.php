<?php

namespace App\Filament\Clusters\Inventory\Resources\Supplies\Schemas;

use App\Models\Supply;
use App\Models\SupplyLine;
use App\Support\Money;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\TextSize;

/**
 * One delivery, read as the document it was recorded from: who it came from,
 * when, under what reference, and a line per Product with what each one cost.
 *
 * Everything here is what was recorded and nothing is what is true today — a
 * Supplier renamed since still reads under the name on the row it points at,
 * and a unit cost is what was paid rather than what the Product is selling
 * for. That is the whole reason a Supply is immutable: every figure the
 * ledger stamped afterwards was taken from these numbers.
 */
class SupplyInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                /**
                 * Struck out, said before anything it says is read. The
                 * figures below are exactly what was recorded and are
                 * deliberately not crossed through — what is not true is that
                 * this delivery counts, and the ledger has already taken
                 * every unit of it back out.
                 */
                Section::make('This delivery was voided')
                    ->columnSpanFull()
                    ->columns(2)
                    ->visible(fn (Supply $record): bool => $record->isVoided())
                    ->schema([
                        TextEntry::make('voided_at')
                            ->label('Voided')
                            ->dateTime(),

                        TextEntry::make('voidedBy.name')
                            ->label('Voided by')
                            ->placeholder('—'),

                        TextEntry::make('what_voiding_did')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->state('Every unit on it came back out at what it came in at. Nothing here was changed — the delivery reads as it was recorded, and both sets of movements are in each Product\'s history.'),
                    ]),

                Section::make('The delivery')
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('supplier.name')
                            ->label('Supplier')
                            ->weight('bold'),

                        TextEntry::make('supplied_on')
                            ->label('Delivered')
                            ->date(),

                        TextEntry::make('reference')
                            ->label('Reference')
                            ->placeholder('—'),

                        TextEntry::make('user.name')
                            ->label('Recorded by')
                            ->placeholder('—'),

                        TextEntry::make('created_at')
                            ->label('Recorded at')
                            ->dateTime(),
                    ]),

                Section::make('What arrived')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('lines')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Product'),
                                TableColumn::make('Quantity')
                                    ->alignment(Alignment::End),
                                TableColumn::make('Unit cost')
                                    ->alignment(Alignment::End),
                                TableColumn::make('Line cost')
                                    ->alignment(Alignment::End),
                            ])
                            ->schema([
                                TextEntry::make('product.name')
                                    ->label('Product'),

                                TextEntry::make('quantity')
                                    ->label('Quantity')
                                    ->alignment(Alignment::End)
                                    ->numeric(),

                                TextEntry::make('unit_cost')
                                    ->label('Unit cost')
                                    ->alignment(Alignment::End)
                                    ->formatStateUsing(fn (int $state): string => Money::format($state)),

                                TextEntry::make('line_cost')
                                    ->label('Line cost')
                                    ->alignment(Alignment::End)
                                    ->state(fn (SupplyLine $record): string => Money::format($record->lineCost())),
                            ]),
                    ]),

                /** Under the right edge of the lines, where the invoice it was copied from puts it. */
                Section::make('What this delivery cost')
                    ->columnStart(2)
                    ->columns(1)
                    ->inlineLabel()
                    ->schema([
                        TextEntry::make('total_cost')
                            ->label('Total')
                            ->alignment(Alignment::End)
                            ->weight('bold')
                            ->size(TextSize::Large)
                            ->state(fn (Supply $record): string => Money::format($record->totalCost())),
                    ]),
            ]);
    }
}
