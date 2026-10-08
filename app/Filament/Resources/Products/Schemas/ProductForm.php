<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\DiscountType;
use App\Enums\ProductStatus;
use App\Filament\Concerns\HasSeoFields;
use App\Filament\Concerns\HasSlugField;
use App\Models\Product;
use App\Support\Money;
use App\Support\Pricing\PriceBook;
use App\Support\Pricing\ProductPrices;
use App\Support\Pricing\Tier;
use App\Support\Pricing\TierPrice;
use App\Support\Settings\GeneralSettings;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Everything a Product's page and card can show, and only the name, slug,
 * Category, Brand and price required: every characteristic is optional, so a
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
     * The Regular Price, the discount kind, and one Tier Discount per Tier
     * after the first, with the Tier Prices they come to drawn as they are
     * typed. A Tier left blank charges the Tier below's price. Money is
     * typed in whole taka and stored in minor units, converted here at the
     * boundary; a percentage is stored as typed.
     */
    private static function pricesSection(): Section
    {
        /** Read once here only because the Tiers decide which fields exist; anything drawn reads the book in its own closure. */
        $book = PriceBook::current();

        $discountFields = array_map(
            fn (Tier $tier): TextInput => TextInput::make('tier_discounts.'.$tier->key)
                ->label($tier->label())
                ->integer()
                ->minValue(0)
                ->maxValue(fn (Get $get): ?int => self::isFixed($get('discount_type')) ? self::wholeNumber($get('price')) : 100)
                ->suffix(fn (Get $get): string => self::isFixed($get('discount_type')) ? app(GeneralSettings::class)->currencySymbol.' off per kg' : '% off')
                ->placeholder('Same as the Tier below')
                ->live(onBlur: true)
                ->formatStateUsing(fn (mixed $state, Get $get): mixed => is_numeric($state) && self::isFixed($get('discount_type')) ? Money::toWholeMajorString((int) $state) : $state)
                ->dehydrateStateUsing(fn (mixed $state, Get $get): ?int => self::discountToStore($state, $get('discount_type'))),
            array_slice($book->tiers, 1),
        );

        return Section::make('Prices')
            ->description(fn (): string => 'The Regular Price is what a kilogram costs at '.PriceBook::current()->tiers[0]->label().'. Each Tier after it takes its discount off that price; the Tier is chosen by the whole cart\'s kilograms.')
            ->columnSpanFull()
            ->columns(2)
            ->schema([
                TextInput::make('price')
                    ->label(fn (): string => 'Regular Price ('.app(GeneralSettings::class)->currencyCode.' per kg)')
                    ->required()
                    ->integer()
                    ->minValue(0)
                    ->prefix(fn (): string => app(GeneralSettings::class)->currencySymbol)
                    ->live(onBlur: true)
                    ->formatStateUsing(fn (mixed $state): mixed => is_numeric($state) ? Money::toWholeMajorString((int) $state) : $state)
                    ->dehydrateStateUsing(fn (mixed $state): int => Money::toMinorUnits((int) $state)),

                Select::make('discount_type')
                    ->label('Discount')
                    ->options(DiscountType::class)
                    ->default(DiscountType::Percent->value)
                    ->required()
                    ->live()
                    ->helperText('"10% off" and "৳30 off" are different promises; one kind for the whole Product.'),

                Fieldset::make('Tier Discounts')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema($discountFields),

                TextEntry::make('tier_prices_preview')
                    ->label('Tier Prices')
                    ->state(fn (Get $get): array => self::preview(PriceBook::current(), $get))
                    ->listWithLineBreaks()
                    ->placeholder('Enter a Regular Price to see the Tier Prices.')
                    ->columnSpanFull(),
            ]);
    }

    /**
     * What a kilogram costs in each Tier as the form stands, worked out by
     * the pricing module itself so the preview can never disagree with a Cart.
     *
     * @return list<string>
     */
    private static function preview(PriceBook $book, Get $get): array
    {
        $regularPrice = self::wholeNumber($get('price'));

        if ($regularPrice === null) {
            return [];
        }

        $discountType = $get('discount_type');
        $discounts = [];

        foreach (array_slice($book->tiers, 1) as $tier) {
            $discounts[$tier->key] = self::discountToStore($get('tier_discounts.'.$tier->key), $discountType);
        }

        $product = new ProductPrices(
            id: 0,
            regularPrice: Money::toMinorUnits($regularPrice),
            discountType: self::isFixed($discountType) ? DiscountType::Fixed : DiscountType::Percent,
            tierDiscounts: $discounts,
        );

        return array_map(
            fn (TierPrice $step): string => $step->tier->label().': '.Money::format($step->price),
            $book->ladder($product),
        );
    }

    /** A typed discount as it is stored: minor units for a fixed amount, the percentage as it is, null when blank. */
    private static function discountToStore(mixed $state, mixed $discountType): ?int
    {
        $value = self::wholeNumber($state);

        if ($value === null) {
            return null;
        }

        return self::isFixed($discountType) ? Money::toMinorUnits($value) : $value;
    }

    private static function isFixed(mixed $discountType): bool
    {
        return ($discountType instanceof DiscountType ? $discountType : DiscountType::tryFrom((string) $discountType)) === DiscountType::Fixed;
    }

    private static function wholeNumber(mixed $state): ?int
    {
        $value = filter_var($state, FILTER_VALIDATE_INT);

        return $value === false || $value < 0 ? null : $value;
    }
}
