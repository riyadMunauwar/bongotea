<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\ImageSlot;
use App\Enums\ProductStatus;
use App\Enums\Strength;
use App\Filament\Concerns\HasSeoFields;
use App\Filament\Concerns\HasSlugField;
use App\Models\Pack;
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
use Filament\Schemas\Components\Fieldset;
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
                    self::packsSection(),
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
                self::nameInput()->columnSpanFull(),

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
                        : 'Lowercase English letters, digits and hyphens. Fixed once the Product is first published.')
                    ->columnSpanFull(),

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
     * filled in. The Characteristics are grouped and ordered exactly as the
     * page draws them — the basics, the quality, the Merchant's own, then the
     * Storage tips — so a Merchant fills them in the order a Customer reads
     * them. The phrases, the brewing steps and the business tips follow, the
     * tips folded until there are some.
     */
    private static function teaProfileSection(): Section
    {
        return Section::make('Tea profile')
            ->description('All optional; the Product page shows only what is filled in.')
            ->columns(1)
            ->schema([
                Fieldset::make('Basics')
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        self::characteristicInput('package_weight', 'Weight / package'),
                        self::characteristicInput('tea_type', 'Tea type'),
                        self::characteristicInput('particle_size', 'Particle size')
                            ->helperText('How big the grain looks, e.g. "মাঝারি".'),
                        self::characteristicInput('cups_per_kilogram', 'Cups per kg')
                            ->helperText('As buyers should read it, e.g. "প্রায় ৪০০–৪৫০ কাপ".'),
                    ]),

                Fieldset::make('Quality')
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        Select::make('strength')
                            ->options(Strength::class)
                            ->placeholder('Not set')
                            ->helperText('৫+ is the strongest liquor, ২+ the mildest. Shown as সামগ্রিক রেটিং.'),
                        self::characteristicInput('grade', 'Grade')
                            ->helperText('The trade name, e.g. "লিফ", "BOP", "ডাস্ট".'),
                        self::characteristicInput('liquor_colour', 'Liquor colour'),
                        self::characteristicInput('aroma', 'Aroma'),
                        self::characteristicInput('liquor_body', 'Liquor body'),
                        self::characteristicInput('infusion', 'Infusion')
                            ->helperText('e.g. "৩–৪ মিনিট".'),
                    ]),

                Fieldset::make('Other')
                    ->columns(1)
                    ->schema([
                        Repeater::make('custom_characteristics')
                            ->hiddenLabel()
                            ->helperText('Anything else about this tea alone, shown under অন্যান্য.')
                            ->schema([
                                TextInput::make('label')->required()->maxLength(60),
                                TextInput::make('value')->required()->maxLength(255),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->reorderable()
                            ->addActionLabel('Add a characteristic'),
                    ]),

                Fieldset::make('Storage')
                    ->columns(1)
                    ->schema([
                        Repeater::make('storage_tips')
                            ->hiddenLabel()
                            ->helperText('How to keep this tea, shown in this order. A new Product starts with the four common tips.')
                            ->schema([
                                TextInput::make('heading')->required()->maxLength(60),
                                TextInput::make('explanation')->required()->maxLength(255),
                            ])
                            ->columns(2)
                            ->default(Product::commonStorageTips())
                            ->reorderable()
                            ->addActionLabel('Add a storage tip'),
                    ]),

                TagsInput::make('best_for')
                    ->label('Best for')
                    ->helperText('Each phrase is one chip on the Product page. These narrow nothing; Tags do.')
                    ->columnSpanFull(),

                TextInput::make('not_best_for')
                    ->label('Not best for')
                    ->maxLength(255)
                    ->columnSpanFull(),

                Repeater::make('brewing_steps')
                    ->label('Brewing guide')
                    ->helperText('One step per line, numbered on the Product page in this order.')
                    ->simple(TextInput::make('step')->required()->maxLength(255))
                    ->defaultItems(0)
                    ->reorderable()
                    ->addActionLabel('Add a step')
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
                            ->reorderable()
                            ->addActionLabel('Add a tip'),
                    ]),
            ]);
    }

    private static function characteristicInput(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->maxLength(255);
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
                    ->rules([self::availableCanBeSaved()])
                    ->helperText('Off keeps it on show, greyed, saying it cannot be had right now. Needs a Pack first.'),

                /**
                 * How it is ever sold, as against Available, which is for
                 * now (ADR-0036). Turning পাইকারি off clears its Tiers.
                 */
                Toggle::make('sold_bulk')
                    ->label('Sold পাইকারি')
                    ->default(true)
                    ->live()
                    ->rules([self::switchesCanBeSaved()])
                    ->helperText('By the kilogram, at its Tiers, on the bulk order form, calculator and price list.'),

                Toggle::make('sold_retail')
                    ->label('Sold খুচরা')
                    ->default(false)
                    ->live()
                    ->helperText('In Packs, at their Pack Prices, from the খুচরা কার্ট.'),

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
     * at the boundary. Only a Product sold পাইকারি has Tiers, so the section is
     * gone, and nothing in it saved or checked, while that switch is off.
     */
    private static function pricesSection(): Section
    {
        return Section::make('Prices')
            ->description('What a kilogram of this tea costs at each weight of the whole cart, across every tea in it. One row alone is a flat price. Every discount a buyer sees is measured from the MRP.')
            ->visible(fn (Get $get): bool => (bool) $get('sold_bulk'))
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

    /**
     * The Product's Packs (ADR-0036): the sealed sizes it reaches a Customer
     * in, each ticked for the ways it serves. A tick shows only for a way the
     * Product is sold, and is saved off whenever its switch is, so a Pack
     * never serves a way its Product is not sold. Only a Pack ticked খুচরা
     * shows and needs an MRP and a Pack Price, the Pack Price never above the
     * MRP; a price typed on a Pack opens it to nobody. A Pack is named by its
     * weight, so the weight is all that tells two apart and no two may share
     * one. Money is typed in whole taka and stored in minor units, as on the
     * Tiers.
     */
    private static function packsSection(): Section
    {
        return Section::make('Packs')
            ->description('The sealed sizes this tea is sent in. Tick each for পাইকারি, খুচরা or both; every way it is sold needs at least one Pack.')
            ->columnSpanFull()
            ->schema([
                Repeater::make('packs')
                    ->hiddenLabel()
                    ->relationship()
                    ->orderColumn('sort')
                    ->reorderable()
                    ->defaultItems(0)
                    ->columns(['default' => 1, 'sm' => 3])
                    ->addActionLabel('Add a Pack')
                    ->rules([self::packsCanBeSaved()])
                    ->schema([
                        TextInput::make('grams')
                            ->label('Weight')
                            ->required()
                            ->integer()
                            ->minValue(1)
                            ->distinct()
                            ->live(onBlur: true)
                            ->suffix('g'),

                        self::packTick('serves_bulk', 'পাইকারি', 'sold_bulk')
                            ->rules([self::packTicksCanBeSaved()]),

                        /** Raised here only where the পাইকারি tick, which carries it otherwise, is hidden. */
                        self::packTick('serves_retail', 'খুচরা', 'sold_retail')
                            ->rules([fn (Get $get): ?Closure => $get('../../sold_bulk') ? null : self::packTicksCanBeSaved()($get)]),

                        TextInput::make('mrp')
                            ->label('MRP')
                            ->visible(fn (Get $get): bool => self::servesRetail($get))
                            ->required()
                            ->integer()
                            ->minValue(1)
                            ->prefix(fn (): string => app(GeneralSettings::class)->currencySymbol)
                            ->formatStateUsing(fn (mixed $state): mixed => is_numeric($state) ? Money::toWholeMajorString((int) $state) : $state)
                            ->dehydrateStateUsing(fn (mixed $state): int => Money::toMinorUnits((int) $state)),

                        TextInput::make('price')
                            ->label('Pack Price')
                            ->visible(fn (Get $get): bool => self::servesRetail($get))
                            ->required()
                            ->integer()
                            ->minValue(1)
                            ->rules([self::packPriceCanBeSaved()])
                            ->prefix(fn (): string => app(GeneralSettings::class)->currencySymbol)
                            ->formatStateUsing(fn (mixed $state): mixed => is_numeric($state) ? Money::toWholeMajorString((int) $state) : $state)
                            ->dehydrateStateUsing(fn (mixed $state): int => Money::toMinorUnits((int) $state)),

                        Toggle::make('is_available')
                            ->label('Available')
                            ->default(true)
                            ->inline(false),
                    ]),

                self::defaultBulkPackSelect(),
            ]);
    }

    /**
     * The পাইকারি Pack a bulk buyer starts from (ADR-0036), chosen by weight
     * among the Pack rows ticked পাইকারি, since a Pack not yet saved has no id
     * to choose it by. Required while the Product is sold পাইকারি and has a
     * Pack. Saved after the Packs, which are above it in the form, so the
     * weight is turned into the Pack it names only once that Pack exists.
     */
    private static function defaultBulkPackSelect(): Select
    {
        return Select::make('default_bulk_pack')
            ->label('Default পাইকারি Pack')
            ->helperText('The size a bulk buyer starts from; they can choose another পাইকারি Pack.')
            ->visible(fn (Get $get): bool => (bool) $get('sold_bulk'))
            ->options(fn (Get $get): array => collect(is_array($get('packs')) ? $get('packs') : [])
                ->filter(fn (mixed $pack): bool => is_array($pack) && (bool) ($pack['serves_bulk'] ?? false) && is_numeric($pack['grams'] ?? null) && (int) $pack['grams'] > 0)
                ->mapWithKeys(fn (array $pack): array => [(int) $pack['grams'] => Pack::nameFor((int) $pack['grams'])])
                ->all())
            ->required(fn (Get $get): bool => is_array($get('packs')) && $get('packs') !== [])
            ->afterStateHydrated(fn (Select $component, ?Product $record): mixed => $component->state($record?->defaultBulkPack?->grams))
            ->dehydrated(false)
            ->saveRelationshipsUsing(fn (Product $record, mixed $state): bool => $record->update([
                'default_bulk_pack_id' => is_numeric($state) ? $record->packs()->where('grams', (int) $state)->where('serves_bulk', true)->value('id') : null,
            ]));
    }

    /**
     * A Pack's tick for one way of selling: shown only while its Product's
     * switch for that way is on, and saved off whenever that switch is.
     */
    private static function packTick(string $name, string $label, string $productSwitch): Toggle
    {
        return Toggle::make($name)
            ->label($label)
            ->default(fn (Get $get): bool => (bool) $get("../../{$productSwitch}"))
            ->inline(false)
            ->live()
            ->visible(fn (Get $get): bool => (bool) $get("../../{$productSwitch}"))
            ->dehydratedWhenHidden()
            ->dehydrateStateUsing(fn (mixed $state, Get $get): bool => (bool) $state && (bool) $get("../../{$productSwitch}"));
    }

    /** Whether a Pack row, as it stands, is ticked পাইকারি for a Product sold পাইকারি. */
    private static function servesBulk(Get $get): bool
    {
        return (bool) $get('serves_bulk') && (bool) $get('../../sold_bulk');
    }

    /** Whether a Pack row, as it stands, is ticked খুচরা for a Product sold খুচরা. */
    private static function servesRetail(Get $get): bool
    {
        return (bool) $get('serves_retail') && (bool) $get('../../sold_retail');
    }

    /** A Pack serves at least one of the ways its Product is sold; a tick hidden for a way not sold counts as off. */
    private static function packTicksCanBeSaved(): Closure
    {
        return fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            if (! self::servesBulk($get) && ! self::servesRetail($get)) {
                $fail('Tick পাইকারি, খুচরা or both.');
            }
        };
    }

    /**
     * Every way the Product is sold has a Pack serving it, once it has any
     * Pack at all; a Product with none is refused only at Available.
     */
    private static function packsCanBeSaved(): Closure
    {
        return fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            $packs = is_array($value) ? $value : [];

            if ($packs === []) {
                return;
            }

            foreach (['sold_bulk' => ['serves_bulk', 'পাইকারি'], 'sold_retail' => ['serves_retail', 'খুচরা']] as $switch => [$tick, $way]) {
                if ($get($switch) && ! collect($packs)->contains(fn (mixed $pack): bool => is_array($pack) && (bool) ($pack[$tick] ?? false))) {
                    $fail("Sold {$way}, so at least one Pack must be ticked {$way}.");
                }
            }
        };
    }

    /** A Product is sold one way at least; the error sits on the first switch. */
    private static function switchesCanBeSaved(): Closure
    {
        return fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            if (! $get('sold_bulk') && ! $get('sold_retail')) {
                $fail('Sell it পাইকারি, খুচরা or both.');
            }
        };
    }

    /** Nothing goes on sale that cannot be packed, so an Available Product has a Pack. */
    private static function availableCanBeSaved(): Closure
    {
        return fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            $packs = $get('packs');

            if ($value && (! is_array($packs) || $packs === [])) {
                $fail('Give it a Pack before it can be Available.');
            }
        };
    }

    /** Both are still in whole taka here, as typed, so they compare directly. */
    private static function packPriceCanBeSaved(): Closure
    {
        return fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            if (is_numeric($value) && is_numeric($get('mrp')) && $value > $get('mrp')) {
                $fail('A Pack Price cannot be above its MRP; nothing is sold above it.');
            }
        };
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
