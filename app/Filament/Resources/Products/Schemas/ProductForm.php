<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Models\Product;
use App\Support\Money;
use App\Support\Settings\GeneralSettings;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                /**
                 * The fields sit in a section for the same reason the Page and
                 * Order forms' do: this was the one screen in the panel whose
                 * fields floated loose with no card around them, which read as
                 * a different application from the two beside it.
                 *
                 * The span is the part worth explaining, because it differs
                 * from those two on purpose. Filament gives a resource form a
                 * two-column grid and a section a single column of it, so the
                 * Page and Order forms' sections pair up across a row. This
                 * form holds one section, and a lone section left at one column
                 * would sit in the left half of the window with the right half
                 * permanently blank, its two fields squeezed into a quarter of
                 * the screen each. Taking the full width and handing the two
                 * columns on to the fields leaves them exactly where they
                 * already were, which is the whole of the intent here.
                 */
                Section::make('Product')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),

                        /**
                         * Money is stored as integer minor units. The conversion happens
                         * here, at the boundary, so nothing between this field and the
                         * database has to know what a minor unit is worth.
                         */
                        TextInput::make('price')
                            ->label(fn (): string => 'Price ('.app(GeneralSettings::class)->currencyCode.')')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->prefix(fn (): string => app(GeneralSettings::class)->currencySymbol)
                            ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : Money::toMajorString($state))
                            ->dehydrateStateUsing(fn (string $state): int => Money::toMinorUnits($state)),

                        Textarea::make('description')
                            ->rows(3)
                            ->columnSpanFull(),

                        SpatieMediaLibraryFileUpload::make('image')
                            ->collection(Product::IMAGE_COLLECTION)
                            ->image()
                            ->imageEditor()
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
