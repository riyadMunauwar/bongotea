<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Http\Requests\CheckoutRequest;
use App\Models\Order;
use App\Models\Pack;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use App\Support\Orders\OrderCapture;
use App\Support\Orders\Repricing;
use App\Support\Orders\RetailRepricing;
use App\Support\PhoneNumber;
use App\Support\Pricing\PricedLine;
use App\Support\Pricing\PricedPackLine;
use App\Support\Pricing\Tier;
use App\Support\Settings\GeneralSettings;
use Closure;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Component;
use WeakMap;

/**
 * An Order taken by phone, and what may be changed about one after Capture,
 * in the shape of the document it edits.
 *
 * Block for block this is OrderInfolist, so opening the editor moves nothing on
 * screen except which cells accept typing. Everything fixed at Capture renders
 * as text rather than as a disabled input: a greyed-out box still looks like
 * something you might be able to fix, and the whole point of this screen is
 * that most of it cannot be.
 *
 * No price is ever typed here except a Price Override. What an Order holds —
 * which Products and how many Kilograms — may change until it has a Shipment,
 * and every change reprices the whole Order through the pricing module
 * (`Repricing`), showing the old total beside the new one before it is saved.
 * What was agreed is what gets collected at the door, so the User reads the
 * new figure to the Customer before committing it.
 *
 * An Order is bulk or retail (ADR-0034), chosen first when one is taken by
 * phone and never changed after: a bulk Order's lines are Products and
 * Kilograms repriced by `Repricing`, a retail Order's are Packs and counts
 * repriced by `RetailRepricing`.
 */
class OrderForm
{
    /**
     * How long typing pauses before the totals redraw.
     *
     * Live rather than on-save, because the figures are the reason to open this
     * screen: a quantity changed against no visible total is a number you only
     * learn after committing it. Debounced rather than per-keystroke, because
     * "30" would otherwise cost two round-trips and show the total for "3" in
     * between.
     */
    private const RECOMPUTE_DEBOUNCE = 500;

    /**
     * The figures already worked out, per component and per set of rows.
     *
     * @var WeakMap<Component, array<string, array<string, mixed>>>|null
     */
    private static ?WeakMap $figures = null;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                ...OrderDocument::warnings(),

                Section::make('Order')
                    ->schema([
                        /** Chosen first, because it decides what a line is; fixed from Capture on. */
                        ToggleButtons::make('kind')
                            ->options(OrderKind::class)
                            ->default(OrderKind::Bulk->value)
                            ->inline()
                            ->required()
                            ->live()
                            /** A line of one kind means nothing as the other, so the rows start again; never on a captured Order, whose kind is fixed. */
                            ->afterStateUpdated(fn (callable $set, ?Order $record): mixed => $record === null ? $set('lines', [[]]) : null)
                            ->helperText('Bulk is sold by the kilogram at its Tiers; retail in Packs at their Pack Prices. It cannot be changed once the order is taken.')
                            ->visibleOn('create'),

                        TextEntry::make('kind')
                            ->badge()
                            ->state(fn (?Order $record): ?OrderKind => $record?->kind)
                            ->visibleOn('edit'),

                        TextEntry::make('order_number')
                            ->label('Order number')
                            ->state(fn (?Order $record): ?string => $record?->order_number)
                            ->copyable()
                            ->weight('bold')
                            ->visibleOn('edit'),

                        Select::make('status')
                            ->options(OrderStatus::class)
                            ->default(OrderStatus::New->value)
                            ->required()
                            ->hintIcon(
                                Heroicon::OutlinedQuestionMarkCircle,
                                tooltip: 'The confirmation call is the COD workflow — mark what happened so the same customer is not called twice, or missed. Cancelled means the order genuinely happened but will not be fulfilled.',
                            ),

                        TextEntry::make('created_at')
                            ->label('Taken')
                            ->state(fn (?Order $record) => $record?->created_at)
                            ->dateTime()
                            ->visibleOn('edit'),

                        OrderDocument::paymentMethod()
                            ->visibleOn('edit'),
                    ]),

                /**
                 * Only the phone and the District are compulsory here, as
                 * they are at Capture. An editor that refused to save without
                 * an address would make an otherwise good Order unworkable —
                 * the operator would have to invent one to correct a quantity.
                 */
                Section::make('Deliver to')
                    ->schema([
                        TextInput::make('customer_name')
                            ->label('Name')
                            ->maxLength(255)
                            ->dehydrateStateUsing(self::nullWhenBlank())
                            ->placeholder(OrderDocument::NOT_ASKED),

                        /**
                         * Editable, and the one field on this screen that
                         * changes what the Order *is* rather than what it says.
                         *
                         * A Customer is their phone number, so a corrected
                         * number is a different Customer: EditOrder re-points
                         * the Order at whoever owns the new one rather than
                         * leaving the snapshot disagreeing with the identity it
                         * is linked to. Stored canonical, never as typed, so
                         * two spellings cannot fork into two Customers.
                         *
                         * Only a changed number is judged. The stored one was
                         * valid under the phone rules of its day, and a
                         * Merchant who has since changed those rules in
                         * Settings must still be able to save an old Order.
                         */
                        TextInput::make('customer_phone')
                            ->label('Phone')
                            ->required()
                            ->maxLength(32)
                            ->rule(fn (?Order $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                                if ($record !== null && $value === $record->customer_phone) {
                                    return;
                                }

                                if (! is_string($value) || ! PhoneNumber::isValid($value)) {
                                    $fail('That is not a number a courier could call.');
                                }
                            })
                            ->hintIcon(
                                Heroicon::OutlinedPhone,
                                tooltip: 'A customer is their phone number, so changing this moves the order to a different customer. Correct a wrong number here; cancel the order if no number reaches anyone.',
                            ),

                        TextInput::make('customer_email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255)
                            ->dehydrateStateUsing(self::nullWhenBlank())
                            ->placeholder(OrderDocument::NOT_ASKED),

                        Select::make('customer_district')
                            ->label('District')
                            ->options(fn (): array => array_combine(
                                config('giopages.districts'),
                                config('giopages.districts'),
                            ))
                            ->required()
                            ->searchable(),

                        Textarea::make('customer_address')
                            ->label('Address')
                            ->rows(3)
                            ->maxLength(1000)
                            ->dehydrateStateUsing(self::nullWhenBlank())
                            ->placeholder(OrderDocument::NOT_ASKED),

                        TextInput::make('shop_name')
                            ->label('Shop name')
                            ->maxLength(255)
                            ->dehydrateStateUsing(self::nullWhenBlank())
                            ->placeholder(OrderDocument::NOT_ASKED),

                        Textarea::make('note')
                            ->label('Note')
                            ->rows(2)
                            ->maxLength(1000)
                            ->dehydrateStateUsing(self::nullWhenBlank())
                            ->placeholder(OrderDocument::NOT_ASKED),
                    ]),
                ...OrderDocument::payments(),

                ...OrderDocument::shipments(),

                /**
                 * The lines are priced by the pricing module and nothing else:
                 * a User chooses Products and Kilograms, and every price on
                 * screen and on the saved Order is today's Tier Price for the
                 * whole Order's Kilograms. Changing any line reprices every
                 * line (`Repricing`), which is why the figures follow the
                 * form rather than the stored Order the moment a row moves.
                 *
                 * Not bound to the relationship: `Repricing` writes the lines,
                 * so no figure on them is ever taken from what the form sent.
                 */
                Section::make('Items')
                    ->columnSpanFull()
                    ->description(fn (?Order $record): ?string => $record?->hasAnyShipment()
                        ? 'Locked: this order has been handed to a courier, so what it holds can no longer change.'
                        : null)
                    ->schema([
                        Repeater::make('lines')
                            ->hiddenLabel()
                            ->required()
                            ->minItems(1)
                            ->defaultItems(1)
                            ->reorderable(false)
                            ->addActionLabel(fn (Get $get, ?Order $record): string => self::isRetail($get('kind'), $record) ? 'Add a pack' : 'Add a tea')
                            ->disabled(fn (?Order $record): bool => $record?->hasAnyShipment() ?? false)
                            ->columns(4)
                            ->schema([
                                Hidden::make('id'),

                                Select::make('product_id')
                                    ->label('Product')
                                    ->options(fn (?Order $record): array => self::productOptions($record))
                                    ->required()
                                    ->searchable()
                                    ->distinct()
                                    ->live()
                                    /** Another tea starts again from its own default পাইকারি Pack. */
                                    ->afterStateUpdated(fn (callable $set): mixed => $set('pack_id', null))
                                    ->visible(fn (Get $get, ?Order $record): bool => ! self::isRetail($get('../../kind'), $record))
                                    ->rule(fn (Get $get, ?Order $record): Closure => self::overrideWithinMrp($get, $record))
                                    ->columnSpan(2),

                                TextInput::make('kilograms')
                                    ->label('Kilograms')
                                    ->required()
                                    ->integer()
                                    ->minValue(1)
                                    ->maxValue(CheckoutRequest::MAX_KILOGRAMS)
                                    ->suffix('kg')
                                    ->visible(fn (Get $get, ?Order $record): bool => ! self::isRetail($get('../../kind'), $record))
                                    ->rule(fn (Get $get, ?Order $record): Closure => self::bulkRowRule($get, $record, 'kilograms', Repricing::packingProblem(...)))
                                    ->live(debounce: self::RECOMPUTE_DEBOUNCE),

                                /**
                                 * A retail line: one of the Packs a Customer could order now,
                                 * or one the Order already holds. A bulk line (ADR-0036): one
                                 * of its tea's পাইকারি Packs on offer now, or the one the line
                                 * already holds; left blank, the tea's default.
                                 */
                                Select::make('pack_id')
                                    ->label('Pack')
                                    ->options(fn (Get $get, ?Order $record): array => self::isRetail($get('../../kind'), $record)
                                        ? self::packOptions($record)
                                        : self::bulkPackOptions($get('product_id'), $record))
                                    ->placeholder(fn (Get $get, ?Order $record): ?string => self::isRetail($get('../../kind'), $record) ? null : self::defaultPackPlaceholder($get('product_id')))
                                    ->required(fn (Get $get, ?Order $record): bool => self::isRetail($get('../../kind'), $record))
                                    ->searchable(fn (Get $get, ?Order $record): bool => self::isRetail($get('../../kind'), $record))
                                    ->distinct()
                                    ->live()
                                    ->rule(
                                        fn (Get $get, ?Order $record): Closure => self::overrideWithinMrp($get, $record),
                                        fn (Get $get, ?Order $record): bool => self::isRetail($get('../../kind'), $record),
                                    )
                                    ->rule(
                                        fn (Get $get, ?Order $record): Closure => self::bulkRowRule($get, $record, 'pack_id', Repricing::packProblem(...)),
                                        fn (Get $get, ?Order $record): bool => ! self::isRetail($get('../../kind'), $record),
                                    )
                                    ->columnSpan(fn (Get $get, ?Order $record): int => self::isRetail($get('../../kind'), $record) ? 2 : 1),

                                TextInput::make('count')
                                    ->label('How many')
                                    ->required()
                                    ->integer()
                                    ->minValue(1)
                                    ->maxValue(CheckoutRequest::MAX_PACKS)
                                    ->visible(fn (Get $get, ?Order $record): bool => self::isRetail($get('../../kind'), $record))
                                    ->live(debounce: self::RECOMPUTE_DEBOUNCE),

                                TextEntry::make('price_per_kilogram')
                                    ->label(fn (Get $get, ?Order $record): string => self::isRetail($get('../../kind'), $record) ? 'Pack Price' : 'Price per kg')
                                    ->state(fn (Get $get, Component $livewire, ?Order $record, TextEntry $component): string => self::linePrice(
                                        self::figures($livewire, $get('../../lines'), $record, $get('../../kind'))['lines'][self::itemKey($component)] ?? null,
                                    )),

                                /**
                                 * A Price Override, for a User who may set one:
                                 * a deal agreed with a buyer. Kept through any
                                 * later Repricing, and still counted toward the
                                 * Order's Kilograms. Hidden from everyone else,
                                 * whose saves leave every override as it was.
                                 */
                                TextInput::make('override_price')
                                    ->label('Price set by hand')
                                    ->integer()
                                    ->minValue(0)
                                    ->prefix(fn (): string => app(GeneralSettings::class)->currencySymbol)
                                    ->suffix(fn (Get $get, ?Order $record): string => self::isRetail($get('../../kind'), $record) ? 'per pack' : 'per kg')
                                    ->visible(fn (): bool => self::canOverridePrices())
                                    ->rule(fn (Get $get, ?Order $record): Closure => self::overrideWithinMrp($get, $record))
                                    ->live(debounce: self::RECOMPUTE_DEBOUNCE)
                                    ->hintIcon(
                                        Heroicon::OutlinedQuestionMarkCircle,
                                        tooltip: 'Leave blank to charge the price the store works out. A price set here stays through later changes to the order.',
                                    ),

                                TextInput::make('override_reason')
                                    ->label('Why')
                                    ->maxLength(500)
                                    ->required(fn (Get $get): bool => filled($get('override_price')))
                                    ->visible(fn (): bool => self::canOverridePrices())
                                    ->columnSpan(2),

                                TextEntry::make('line_total')
                                    ->label('Line total')
                                    ->state(fn (Get $get, Component $livewire, ?Order $record, TextEntry $component): string => self::money(
                                        self::figures($livewire, $get('../../lines'), $record, $get('../../kind'))['lines'][self::itemKey($component)]['total'] ?? null,
                                    )),
                            ]),
                    ]),

                /**
                 * Starts in the second column so the money sits under the right
                 * edge of the items table, where an invoice puts it, rather than
                 * spread across the page as a row of unrelated figures.
                 */
                Section::make('To collect at the door')
                    ->columnStart(2)
                    ->columns(1)
                    ->inlineLabel()
                    ->schema([
                        TextEntry::make('subtotal')
                            ->alignment(Alignment::End)
                            ->state(fn (Get $get, Component $livewire, ?Order $record): string => self::money(self::figures($livewire, $get('lines'), $record, $get('kind'))['subtotal'])),

                        /**
                         * Never typed: the Delivery Band the Order's total
                         * Kilograms fall in decides it, read afresh whenever
                         * the Order is repriced.
                         */
                        TextEntry::make('delivery_charge')
                            ->label('Delivery')
                            ->alignment(Alignment::End)
                            ->state(fn (Get $get, Component $livewire, ?Order $record): string => self::money(self::figures($livewire, $get('lines'), $record, $get('kind'))['delivery']))
                            ->hint(fn (Get $get, Component $livewire, ?Order $record): ?string => self::figures($livewire, $get('lines'), $record, $get('kind'))['band']),

                        /** Includes delivery, so nobody does arithmetic against a cash payment. */
                        TextEntry::make('total')
                            ->label('Total')
                            ->alignment(Alignment::End)
                            ->weight('bold')
                            ->size(TextSize::Large)
                            ->state(fn (Get $get, Component $livewire, ?Order $record): string => self::money(self::figures($livewire, $get('lines'), $record, $get('kind'))['total'])),

                        /**
                         * What a User reads out on the confirmation call
                         * before saving, because the next thing they do is
                         * tell the Customer.
                         */
                        TextEntry::make('repricing')
                            ->label('Repriced')
                            ->alignment(Alignment::End)
                            ->color('warning')
                            ->weight('bold')
                            ->visible(fn (Get $get, Component $livewire, ?Order $record): bool => $record !== null && self::figures($livewire, $get('lines'), $record, $get('kind'))['repriced'])
                            ->state(fn (Get $get, Component $livewire, ?Order $record): ?string => $record === null ? null : sprintf(
                                'was %s → now %s',
                                Money::format($record->total),
                                self::money(self::figures($livewire, $get('lines'), $record, $get('kind'))['total']),
                            )),
                    ]),
            ]);
    }

    /**
     * Store nothing rather than a blank.
     *
     * An emptied box means the detail is gone, and gone is a null — the same
     * value Capture writes for a question the Checkout never asked. A blank
     * saved here would put a third state on a column that has two.
     */
    private static function nullWhenBlank(): Closure
    {
        return fn (?string $state): ?string => filled($state) ? $state : null;
    }

    /** Whether the Order is retail: as it was captured, or as the User has chosen while taking one. */
    private static function isRetail(mixed $kind, ?Order $record): bool
    {
        return $record?->isRetail() ?? ($kind === OrderKind::Retail || $kind === OrderKind::Retail->value);
    }

    private static function canOverridePrices(): bool
    {
        return auth()->user()?->can(Permission::OverridePrices) ?? false;
    }

    /**
     * Refuses a row whose price set by hand would be above its tea's MRP,
     * since nothing is sold above it. Raised on the override for a User who
     * may set one, and on the Product for everyone else, who cannot see the
     * override a dropped MRP has left above it. Only asked of a save that
     * reprices: one that changes only the Order's details writes no price.
     */
    private static function overrideWithinMrp(Get $get, ?Order $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
            $user = auth()->user();
            $lines = $get('../../lines');
            $isOverrideField = str_ends_with($attribute, '.override_price');

            if (! $user instanceof User || $isOverrideField !== self::canOverridePrices()) {
                return;
            }

            $retail = self::isRetail($get('../../kind'), $record);
            $lines = is_array($lines) ? $lines : [];

            if ($record !== null && ! ($retail ? RetailRepricing::changes($record, $lines) : Repricing::changes($record, $lines))) {
                return;
            }

            $row = $retail
                ? ['id' => $get('id'), 'pack_id' => $get('pack_id'), 'count' => $get('count')]
                : ['id' => $get('id'), 'product_id' => $get('product_id'), 'kilograms' => $get('kilograms')];

            if ($isOverrideField) {
                $row += ['override_price' => $value, 'override_reason' => $get('override_reason')];
            }

            $above = $retail ? RetailRepricing::overrideAboveMrp($record, $row, $user) : Repricing::overrideAboveMrp($record, $row, $user);

            if ($above !== null) {
                $fail($isOverrideField
                    ? 'Above its MRP of '.Money::format($above['mrp']).'; nothing is sold above it.'
                    : 'Its price set by hand, '.Money::format($above['override']).', is above the MRP of '.Money::format($above['mrp']).'. Someone who may set prices must change it first.');
            }
        };
    }

    /**
     * Refuses a bulk row on one of its fields with what `Repricing` would
     * refuse it for (ADR-0036): its Pack no longer offered পাইকারি on the
     * Pack, Kilograms the Pack cannot make — naming the weights it can — on
     * the Kilograms. Only asked of a save that reprices, as an untouched line
     * keeps what it was captured with.
     *
     * @param  callable(?Order, array<string, mixed>): ?string  $problemOf
     */
    private static function bulkRowRule(Get $get, ?Order $record, string $field, callable $problemOf): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get, $record, $field, $problemOf): void {
            $lines = $get('../../lines');

            if ($record !== null && ! Repricing::changes($record, is_array($lines) ? $lines : [])) {
                return;
            }

            $problem = $problemOf($record, [
                'id' => $get('id'),
                'product_id' => $get('product_id'),
                'kilograms' => $get('kilograms'),
                'pack_id' => $get('pack_id'),
                $field => $value,
            ]);

            if ($problem !== null) {
                $fail($problem);
            }
        };
    }

    /**
     * The Packs a bulk line of this tea may be supplied in: its পাইকারি Packs
     * switched on, and whichever this Order's lines already hold, named by
     * weight.
     *
     * @return array<int, string>
     */
    private static function bulkPackOptions(mixed $productId, ?Order $record): array
    {
        $product = is_numeric($productId) ? Product::withTrashed()->find((int) $productId) : null;

        if ($product === null) {
            return [];
        }

        $held = $record === null ? collect() : $record->lines()->where('product_id', $product->getKey())->pluck('pack_id')->filter();

        return $product->packs
            ->filter(fn (Pack $pack): bool => $held->contains($pack->getKey()) || ($pack->serves_bulk && $pack->is_available))
            ->mapWithKeys(fn (Pack $pack): array => [$pack->getKey() => $pack->name().($pack->getKey() === $product->default_bulk_pack_id ? ' (default)' : '')])
            ->all();
    }

    /** What a blank Pack means: the tea's default পাইকারি Pack, named. */
    private static function defaultPackPlaceholder(mixed $productId): string
    {
        $pack = is_numeric($productId) ? Product::withTrashed()->find((int) $productId)?->defaultBulkPack : null;

        return $pack === null ? 'Its default Pack' : "Its default, {$pack->name()}";
    }

    /**
     * The Products a line may hold: what the storefront sells now, and
     * whatever this Order's lines already hold, so a line whose Product has
     * since been taken out of the catalogue still shows what it is.
     *
     * @return array<int, string>
     */
    private static function productOptions(?Order $record): array
    {
        $onSale = Product::query()
            ->published()
            ->soldAs(OrderKind::Bulk)
            ->where('is_available', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();

        $held = $record === null ? [] : Product::withTrashed()
            ->whereKey($record->lines()->pluck('product_id')->filter())
            ->pluck('name', 'id')
            ->all();

        return $onSale + $held;
    }

    /**
     * The Packs a retail line may hold: every Pack a Customer could order now,
     * and whatever this Order's lines already hold, named by tea and weight.
     *
     * @return array<int, string>
     */
    private static function packOptions(?Order $record): array
    {
        $held = $record === null ? collect() : $record->lines()->pluck('pack_id')->filter();

        return Pack::query()
            ->with('product')
            ->where(fn (Builder $query): Builder => $query->whereKey($held)->orWhere('is_available', true))
            ->get()
            ->filter(fn (Pack $pack): bool => $held->contains($pack->getKey()) || $pack->isOrderableAs(OrderKind::Retail))
            ->sortBy(fn (Pack $pack): string => $pack->product->name.sprintf('%08d', $pack->grams))
            ->mapWithKeys(fn (Pack $pack): array => [$pack->getKey() => "{$pack->product->name} — {$pack->name()}"])
            ->all();
    }

    /**
     * The figures the form shows, as it currently stands. An Order whose
     * lines are untouched shows what was agreed at Capture, whatever its
     * Products cost today; a new Order, or one whose lines have moved, shows
     * the whole of it priced afresh — which is what saving will write.
     *
     * Asked by every figure on the screen, so it is worked out once per
     * request for the rows as they stand: the component is a fresh object on
     * every Livewire request, so nothing outlives the render it was for.
     *
     * @return array{repriced: bool, subtotal: int|null, delivery: int|null, band: string|null, total: int|null, lines: array<array-key, array{price: int, total: int, note: string|null}>}
     */
    private static function figures(Component $livewire, mixed $lines, ?Order $record, mixed $kind = null): array
    {
        $lines = is_array($lines) ? $lines : [];
        $retail = self::isRetail($kind, $record);
        self::$figures ??= new WeakMap;
        $worked = self::$figures[$livewire] ?? [];
        $key = md5(serialize([$retail, $lines]));

        if (! array_key_exists($key, $worked)) {
            $worked[$key] = $retail ? self::workOutRetailFigures($lines, $record) : self::workOutFigures($lines, $record);
            self::$figures[$livewire] = $worked;
        }

        return $worked[$key];
    }

    /**
     * @param  array<array-key, mixed>  $lines
     * @return array{repriced: bool, subtotal: int|null, delivery: int|null, band: string|null, total: int|null, lines: array<array-key, array{price: int, total: int, note: string|null}>}
     */
    private static function workOutFigures(array $lines, ?Order $record): array
    {
        if ($record !== null && ! Repricing::changes($record, $lines)) {
            $stored = $record->lines()->get()->keyBy('id');
            $byKey = [];

            foreach ($lines as $key => $row) {
                $line = $stored->get($row['id'] ?? null);

                if ($line !== null) {
                    $byKey[$key] = [
                        'price' => $line->unit_price,
                        'total' => $line->line_total,
                        'note' => self::lineNote($line->isPriceOverridden(), $line->tier_min === null ? null : new Tier($line->tier_min, $line->tier_max, $line->unit_price)),
                    ];
                }
            }

            return [
                'repriced' => false,
                'subtotal' => $record->subtotal,
                'delivery' => $record->delivery_charge,
                'band' => $record->delivery_label,
                'total' => $record->total,
                'lines' => $byKey,
            ];
        }

        $preview = app(Repricing::class)->preview($lines);

        if ($preview === null) {
            return ['repriced' => true, 'subtotal' => null, 'delivery' => null, 'band' => null, 'total' => null, 'lines' => []];
        }

        return [
            'repriced' => true,
            'subtotal' => $preview['cart']->subtotal,
            'delivery' => $preview['cart']->deliveryCharge,
            'band' => $preview['cart']->deliveryBand?->label(),
            'total' => $preview['cart']->total,
            'lines' => array_map(fn (PricedLine $line): array => [
                'price' => $line->tierPrice,
                'total' => $line->lineTotal,
                'note' => self::lineNote($line->overridden, $line->tier),
            ], $preview['lines']),
        ];
    }

    /**
     * The retail Order's figures: as captured while its lines are untouched,
     * else priced afresh at today's Pack Prices and retail Delivery Charge.
     *
     * @param  array<array-key, mixed>  $lines
     * @return array{repriced: bool, subtotal: int|null, delivery: int|null, band: string|null, total: int|null, lines: array<array-key, array{price: int, total: int, note: string|null}>}
     */
    private static function workOutRetailFigures(array $lines, ?Order $record): array
    {
        if ($record !== null && ! RetailRepricing::changes($record, $lines)) {
            $stored = $record->lines()->get()->keyBy('id');
            $byKey = [];

            foreach ($lines as $key => $row) {
                $line = $stored->get($row['id'] ?? null);

                if ($line !== null) {
                    $byKey[$key] = ['price' => $line->unit_price, 'total' => $line->line_total, 'note' => $line->isPriceOverridden() ? 'set by hand' : null];
                }
            }

            return ['repriced' => false, 'subtotal' => $record->subtotal, 'delivery' => $record->delivery_charge, 'band' => $record->delivery_label, 'total' => $record->total, 'lines' => $byKey];
        }

        $preview = app(RetailRepricing::class)->preview($lines);

        if ($preview === null) {
            return ['repriced' => true, 'subtotal' => null, 'delivery' => null, 'band' => null, 'total' => null, 'lines' => []];
        }

        return [
            'repriced' => true,
            'subtotal' => $preview['cart']->subtotal,
            'delivery' => $preview['cart']->deliveryCharge,
            'band' => OrderCapture::RETAIL_DELIVERY_LABEL,
            'total' => $preview['cart']->total,
            'lines' => array_map(fn (PricedPackLine $line): array => [
                'price' => $line->packPrice,
                'total' => $line->lineTotal,
                'note' => $line->overridden ? 'set by hand' : null,
            ], $preview['lines']),
        ];
    }

    /** Where a line's price came from: the Tier it fell in, or a User's hand. */
    private static function lineNote(bool $overridden, ?Tier $tier): ?string
    {
        return match (true) {
            $overridden => 'set by hand',
            $tier === null => null,
            default => $tier->label().' tier',
        };
    }

    /**
     * @param  array{price: int, total: int, note: string|null}|null  $line
     */
    private static function linePrice(?array $line): string
    {
        if ($line === null) {
            return '—';
        }

        return Money::format($line['price']).($line['note'] === null ? '' : " · {$line['note']}");
    }

    private static function money(?int $minorUnits): string
    {
        return $minorUnits === null ? '—' : Money::format($minorUnits);
    }

    /** The key a repeater row is held under, read off the row's own state path. */
    private static function itemKey(TextEntry $component): string
    {
        return Str::afterLast($component->getContainer()->getStatePath(), '.');
    }
}
