<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\ProductStatus;
use App\Filament\Concerns\HasSeoFields;
use App\Filament\Concerns\HasSlugField;
use App\Models\Product;
use App\Support\Money;
use App\Support\Pricing\ProductTiers;
use App\Support\Settings\GeneralSettings;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Everything a Product's page and card can show, and only the name, slug,
 * Category, Brand and Tiers required: every characteristic is optional, so a
 * Product can be published bare and enriched later.
 */
class ProductForm
{
    use HasSeoFields, HasSlugField;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Product')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        self::nameInput(),

                        /**
                         * Locked the first time the Product went live: a link
                         * to it may already be in a post or a chat. A
                         * disabled field is not dehydrated, so the save
                         * leaves the slug as it is; the model refuses a
                         * change from anywhere else.
                         */
                        self::slugInput(url('/tea').'/')
                            ->disabled(fn (?Product $record): bool => $record?->isSlugLocked() ?? false)
                            ->helperText(fn (?Product $record): string => $record?->isSlugLocked()
                                ? 'Fixed since this Product first went live, so links to it never break. The name can still change.'
                                : 'Lowercase English letters, digits and hyphens. Fixed once the Product is first published.'),

                        Select::make('category_id')
                            ->label('Category')
                            ->relationship('category', 'name')
                            ->preload()
                            ->required(),

                        Select::make('brand_id')
                            ->label('Brand')
                            ->relationship('brand', 'name')
                            ->preload()
                            ->required(),

                        Select::make('tags')
                            ->relationship('tags', 'name')
                            ->multiple()
                            ->preload(),

                        TextInput::make('badge')
                            ->helperText('A short ribbon on the card, e.g. "সবচেয়ে জনপ্রিয়". Blank shows none.')
                            ->maxLength(60),

                        Select::make('status')
                            ->options(ProductStatus::class)
                            ->default(ProductStatus::Draft->value)
                            ->required()
                            ->helperText('A draft is seen by nobody.'),

                        Toggle::make('is_available')
                            ->label('Available')
                            ->default(true)
                            ->helperText('Off keeps it on show, greyed, saying it cannot be had right now.'),
                    ]),

                self::pricesSection(),

                Section::make('Description')
                    ->columnSpanFull()
                    ->schema([
                        Textarea::make('short_description')
                            ->label('Short description')
                            ->helperText('One or two lines, shown on the card.')
                            ->rows(2)
                            ->maxLength(500),

                        RichEditor::make('description')
                            ->label('Long description')
                            ->toolbarButtons([['bold', 'italic', 'link'], ['h2', 'h3'], ['bulletList', 'orderedList'], ['undo', 'redo']]),
                    ]),

                Section::make('Characteristics')
                    ->description('All optional; the Product page shows only what is filled in.')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        Select::make('strength')
                            ->options(array_combine(range(1, Product::STRENGTH_MAX), range(1, Product::STRENGTH_MAX)))
                            ->placeholder('Not set')
                            ->helperText('1 is mild, 5 is the strongest liquor.'),

                        TextInput::make('liquor_colour')
                            ->label('Liquor colour')
                            ->maxLength(255),

                        TextInput::make('aroma')
                            ->maxLength(255),

                        TextInput::make('cups_per_kilogram')
                            ->label('Cups per kg')
                            ->helperText('As buyers should read it, e.g. "প্রায় ৪০০–৪৫০ কাপ".')
                            ->maxLength(255),

                        TagsInput::make('best_for')
                            ->label('Best for')
                            ->helperText('Each phrase is one chip on the Product page. These narrow nothing; Tags do.'),

                        TextInput::make('not_best_for')
                            ->label('Not best for')
                            ->maxLength(255),

                        Textarea::make('brewing_guide')
                            ->label('Brewing guide')
                            ->rows(2)
                            ->columnSpanFull(),

                        Repeater::make('business_tips')
                            ->label('Business tips')
                            ->simple(Textarea::make('tip')->required()->rows(2)->maxLength(500))
                            ->defaultItems(0)
                            ->maxItems(Product::BUSINESS_TIPS_MAX)
                            ->reorderable()
                            ->columnSpanFull(),
                    ]),

                Section::make('Gallery')
                    ->columnSpanFull()
                    ->schema([
                        SpatieMediaLibraryFileUpload::make('gallery')
                            ->hiddenLabel()
                            ->collection(Product::GALLERY_COLLECTION)
                            ->multiple()
                            ->reorderable()
                            ->image()
                            ->imageEditor()
                            ->panelLayout('grid')
                            ->helperText('Drag to reorder. The first image is the cover.'),
                    ]),

                Section::make('Search')
                    ->columnSpanFull()
                    ->schema([
                        TagsInput::make('search_keywords')
                            ->label('Search keywords')
                            ->separator(',')
                            ->helperText('Other words buyers might type for this tea, in Bangla or English.'),
                    ]),

                self::seoSection(),
            ]);
    }

    /**
     * The Product's own Tiers (ADR-0031): each range of the whole Cart's
     * Kilograms and what a Kilogram of this tea costs in it. Refused as a
     * whole unless they cover every kilogram from one once and never rise in
     * price, raised on the list because a gap is between two rows and belongs
     * to neither. Money is typed in whole taka and stored in minor units,
     * converted here at the boundary; the model writes the Regular Price from
     * the first row.
     */
    private static function pricesSection(): Section
    {
        return Section::make('Prices')
            ->description('What a kilogram of this tea costs at each weight of the whole cart, across every tea in it. The first row is the Regular Price; one row alone is a flat price.')
            ->columnSpanFull()
            ->schema([
                Repeater::make('tiers')
                    ->hiddenLabel()
                    ->addActionLabel('Add a Tier')
                    ->default([['min' => 1, 'max' => null, 'price' => null]])
                    ->minItems(1)
                    ->columns(3)
                    ->rules([self::tiersCanBeSaved()])
                    ->schema([
                        TextInput::make('min')
                            ->label('From')
                            ->required()
                            ->integer()
                            ->minValue(1)
                            ->suffix('kg'),

                        TextInput::make('max')
                            ->label('To')
                            ->integer()
                            ->minValue(1)
                            ->suffix('kg')
                            ->helperText('Blank on the last one, for "and over".'),

                        TextInput::make('price')
                            ->label(fn (): string => 'Price ('.app(GeneralSettings::class)->currencyCode.' per kg)')
                            ->required()
                            ->integer()
                            ->minValue(0)
                            ->prefix(fn (): string => app(GeneralSettings::class)->currencySymbol)
                            ->formatStateUsing(fn (mixed $state): mixed => is_numeric($state) ? Money::toWholeMajorString((int) $state) : $state)
                            ->dehydrateStateUsing(fn (mixed $state): int => Money::toMinorUnits((int) $state)),
                    ]),
            ]);
    }

    private static function tiersCanBeSaved(): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            $problem = ProductTiers::problem(is_array($value) ? $value : []);

            if ($problem !== null) {
                $fail($problem);
            }
        };
    }
}
