<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\ImageSlot;
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
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Everything a Product's page and card can show, and only the name, slug,
 * Category, Brand and Tiers required: every characteristic is optional, so a
 * Product can be published bare and enriched later.
 *
 * Drawn as the content on the left (what it is, its photos, its prices, its
 * tea profile) and, beside it, the small cards a Merchant glances at: whether
 * it is live, where it is filed, and what it says to search engines. On a
 * phone the cards follow the content.
 */
class ProductForm
{
    use HasSeoFields, HasSlugField;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 3])
            ->components([
                Group::make([
                    self::basicsSection(),
                    self::imagesSection(),
                    self::pricesSection(),
                    self::teaProfileSection(),
                ])->columnSpan(['lg' => 2]),

                Group::make([
                    self::publishingSection(),
                    self::organisationSection(),
                    self::seoSection(),
                ])->columnSpan(['lg' => 1]),
            ]);
    }

    private static function basicsSection(): Section
    {
        return Section::make('Basics')
            ->columns(2)
            ->schema([
                self::nameInput(),

                /**
                 * Locked the first time the Product went live: a link to it
                 * may already be in a post or a chat. A disabled field is not
                 * dehydrated, so the save leaves the slug as it is; the model
                 * refuses a change from anywhere else.
                 */
                self::slugInput(url('/tea').'/')
                    ->disabled(fn (?Product $record): bool => $record?->isSlugLocked() ?? false)
                    ->helperText(fn (?Product $record): string => $record?->isSlugLocked()
                        ? 'Fixed since this Product first went live, so links to it never break. The name can still change.'
                        : 'Lowercase English letters, digits and hyphens. Fixed once the Product is first published.'),

                Textarea::make('short_description')
                    ->label('Short description')
                    ->helperText('One or two lines, shown on the card.')
                    ->rows(2)
                    ->maxLength(500)
                    ->columnSpanFull(),

                RichEditor::make('description')
                    ->label('Long description')
                    ->toolbarButtons([['bold', 'italic', 'link'], ['h2', 'h3'], ['bulletList', 'orderedList'], ['undo', 'redo']])
                    ->columnSpanFull(),
            ]);
    }

    private static function imagesSection(): Section
    {
        return Section::make('Images')
            ->schema([
                ImageSlot::ProductPhoto->fit(SpatieMediaLibraryFileUpload::make('gallery'))
                    ->hiddenLabel()
                    ->collection(Product::GALLERY_COLLECTION)
                    ->multiple()
                    ->reorderable()
                    ->image()
                    ->panelLayout('grid')
                    ->helperText('Drag to reorder; the first image is the cover. '.ImageSlot::ProductPhoto->advice()),
            ]);
    }

    /**
     * What the tea is like, all optional; the Product page shows only what is
     * filled in. The three short facts share a row, the phrases and the guide
     * run the width, and the business tips stay folded until there are some.
     */
    private static function teaProfileSection(): Section
    {
        return Section::make('Tea profile')
            ->description('All optional; the Product page shows only what is filled in.')
            ->columns(['default' => 1, 'sm' => 3])
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

                TextInput::make('not_best_for')
                    ->label('Not best for')
                    ->maxLength(255)
                    ->columnSpan(['sm' => 2]),

                TagsInput::make('best_for')
                    ->label('Best for')
                    ->helperText('Each phrase is one chip on the Product page. These narrow nothing; Tags do.')
                    ->columnSpanFull(),

                Textarea::make('brewing_guide')
                    ->label('Brewing guide')
                    ->rows(2)
                    ->columnSpanFull(),

                Section::make('Business tips')
                    ->compact()
                    ->collapsible()
                    ->collapsed(fn (?Product $record): bool => blank($record?->business_tips))
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('business_tips')
                            ->hiddenLabel()
                            ->simple(Textarea::make('tip')->required()->rows(2)->maxLength(500))
                            ->defaultItems(0)
                            ->maxItems(Product::BUSINESS_TIPS_MAX)
                            ->reorderable()
                            ->addActionLabel('Add a tip'),
                    ]),
            ]);
    }

    private static function publishingSection(): Section
    {
        return Section::make('Publishing')
            ->schema([
                Select::make('status')
                    ->options(ProductStatus::class)
                    ->default(ProductStatus::Draft->value)
                    ->required()
                    ->helperText('A draft is seen by nobody.'),

                Toggle::make('is_available')
                    ->label('Available')
                    ->default(true)
                    ->helperText('Off keeps it on show, greyed, saying it cannot be had right now.'),

                TextInput::make('badge')
                    ->helperText('A short ribbon on the card, e.g. "সবচেয়ে জনপ্রিয়". Blank shows none.')
                    ->maxLength(60),
            ]);
    }

    /** Where the Product is filed and how buyers find it: each list grows, so each can be searched. */
    private static function organisationSection(): Section
    {
        return Section::make('Organisation')
            ->schema([
                Select::make('category_id')
                    ->label('Category')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                Select::make('brand_id')
                    ->label('Brand')
                    ->relationship('brand', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                Select::make('tags')
                    ->relationship('tags', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload(),

                TagsInput::make('search_keywords')
                    ->label('Search keywords')
                    ->separator(',')
                    ->helperText('Other words buyers might type for this tea, in Bangla or English.'),
            ]);
    }

    /**
     * The Product's MRP and its own Tiers (ADR-0031): each range of the whole
     * Cart's Kilograms and what a Kilogram of this tea costs in it. The Tiers
     * are refused as a whole unless they cover every kilogram from one once
     * and never rise in price, raised on the list because a gap is between two
     * rows and belongs to neither; a Tier above the MRP is raised on the MRP.
     * Money is typed in whole taka and stored in minor units, converted here
     * at the boundary.
     */
    private static function pricesSection(): Section
    {
        return Section::make('Prices')
            ->description('What a kilogram of this tea costs at each weight of the whole cart, across every tea in it. One row alone is a flat price. Every discount a buyer sees is measured from the MRP.')
            ->columnSpanFull()
            ->schema([
                TextInput::make('mrp')
                    ->label(fn (): string => 'MRP ('.app(GeneralSettings::class)->currencyCode.' per kg)')
                    ->helperText('The maximum retail price of a kilogram. No row below may cost more.')
                    ->required()
                    ->integer()
                    ->minValue(1)
                    ->prefix(fn (): string => app(GeneralSettings::class)->currencySymbol)
                    ->rules([self::mrpCanBeSaved()])
                    ->formatStateUsing(fn (mixed $state): mixed => is_numeric($state) ? Money::toWholeMajorString((int) $state) : $state)
                    ->dehydrateStateUsing(fn (mixed $state): int => Money::toMinorUnits((int) $state)),

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

    /** Both are still in whole taka here, as typed, so they compare directly. */
    private static function mrpCanBeSaved(): Closure
    {
        return fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            $tiers = $get('tiers');
            $problem = ProductTiers::mrpProblem(is_array($tiers) ? $tiers : [], $value);

            if ($problem !== null) {
                $fail($problem);
            }
        };
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
