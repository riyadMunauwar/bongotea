<?php

namespace App\Filament\Clusters\Inventory\Resources\StockCounts\Schemas;

use App\Enums\AdjustmentCause;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class StockCountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                /**
                 * One section across the full width, for the reason
                 * `ProductForm`'s is: a lone section left at Filament's
                 * default span sits in the left half of the window with the
                 * right half permanently blank.
                 */
                Section::make('What you counted')
                    ->description('Write down the number you are holding, not the difference. The ledger works out the rest.')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        /**
                         * Trashed Products are not offered: one taken off the
                         * catalogue is not one anybody is standing in front of
                         * counting. Said here rather than left to the
                         * relation, which is deliberately `withTrashed()` so
                         * that a past count of a since-deleted Product still
                         * reads on the list and in its history.
                         */
                        Select::make('product_id')
                            ->label('Product')
                            ->relationship('product', 'name', fn (Builder $query): Builder => $query->withoutTrashed())
                            ->searchable()
                            ->preload()
                            ->required(),

                        /**
                         * What is on the shelf, and the one number anybody
                         * types into this system about stock. It is not a
                         * difference and cannot be negative: nobody holds
                         * less than none of something, and a figure below
                         * nothing is something the ledger concludes rather
                         * than something a User asserts.
                         *
                         * What the ledger currently holds is deliberately not
                         * shown beside it. A counter who can see the expected
                         * answer is a counter being asked to agree with it,
                         * and the entire worth of a count is that it was
                         * arrived at independently.
                         */
                        TextInput::make('quantity')
                            ->label('Counted quantity')
                            ->required()
                            ->integer()
                            ->minValue(0),

                        /**
                         * Picked rather than typed, because "how much am I
                         * losing to damage" is a question asked of a year of
                         * Adjustments at once, and it only answers if the
                         * same thing is spelled the same way every time.
                         */
                        Select::make('cause')
                            ->label('Cause')
                            ->options(AdjustmentCause::class)
                            ->default(AdjustmentCause::OpeningCount)
                            ->required(),

                        Textarea::make('note')
                            ->label('Note')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
