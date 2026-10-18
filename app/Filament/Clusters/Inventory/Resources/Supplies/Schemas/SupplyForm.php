<?php

namespace App\Filament\Clusters\Inventory\Resources\Supplies\Schemas;

use App\Models\Pack;
use App\Models\Product;
use App\Support\Inventory\Grams;
use App\Support\Money;
use App\Support\Settings\GeneralSettings;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\TextSize;
use Illuminate\Database\Eloquent\Builder;

/**
 * A delivery as the supplier's own document: a header naming who it came
 * from, when and under what reference, and a line per Product saying how many
 * arrived and what each one cost.
 *
 * Shaped like `OrderForm`'s items, and read the same way — the table of lines
 * with the money under its right edge, where an invoice puts it — so that
 * somebody entering a challan is typing down the page they are holding.
 *
 * A line is typed the way the challan says it: in KG, loose or in one of the
 * Product's Packs, or in PCS of a Pack. Either way it becomes grams and a cost
 * per kilogram (ADR-0035) through `Grams`, and the figure it was not typed in
 * is shown beside it as it is typed.
 */
class SupplyForm
{
    /**
     * How long typing pauses before the money redraws.
     *
     * Live for `OrderForm`'s reason: what a delivery cost is the figure being
     * checked against the invoice, and a quantity changed against no visible
     * total is a number you only learn after committing it.
     */
    private const RECOMPUTE_DEBOUNCE = 500;

    /** A line typed as so many kilograms, loose or in a Pack. */
    public const KILOGRAMS = 'kg';

    /** A line typed as so many packets of a Pack. */
    public const PACKETS = 'pcs';

    /**
     * A line's grid: two across on a phone, four on a tablet, and twelve on a
     * wide screen, where the seven fields share one row.
     *
     * @var array{default: int, md: int, '2xl': int}
     */
    private const LINE_COLUMNS = ['default' => 2, 'md' => 4, '2xl' => 12];

    /**
     * A field that is one column wherever a line wraps, and two of the twelve
     * once the whole line is on one row. Product takes the full width of a phone and half a tablet.
     *
     * @var array{default: int, '2xl': int}
     */
    private const LINE_FIELD_SPAN = ['default' => 1, '2xl' => 2];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('The delivery')
                    ->description('The supplier\'s document: who it came from, the day it came, and the reference it can be found under.')
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        /**
                         * A record rather than a spelling, which is the whole
                         * worth of a Supplier existing: buying history groups
                         * by the row this points at, and two ways of writing
                         * one name would be two histories that never meet.
                         *
                         * Trashed Suppliers are not offered — one taken off
                         * the list is not one anybody is buying from today —
                         * though the relation stays `withTrashed()` so past
                         * deliveries still read under their name.
                         */
                        Select::make('supplier_id')
                            ->label('Supplier')
                            ->relationship('supplier', 'name', fn (Builder $query): Builder => $query->withoutTrashed())
                            ->searchable()
                            ->preload()
                            ->required(),

                        /**
                         * The day the goods came, which is the day the buyer
                         * will look for and not necessarily the day somebody
                         * got round to typing it in. Today by default,
                         * because recording a delivery as it is unpacked is
                         * the ordinary case.
                         */
                        DatePicker::make('supplied_on')
                            ->label('Delivered on')
                            ->required()
                            ->default(today()),

                        TextInput::make('reference')
                            ->label('Reference')
                            ->maxLength(255)
                            ->placeholder('Their invoice or challan number'),
                    ]),

                Section::make('What arrived')
                    ->columnSpanFull()
                    ->schema([
                        /**
                         * Not `->relationship()`: `StockLedger` writes the
                         * lines, because each one has to raise stock in the
                         * same transaction that records it. A repeater saving
                         * rows of its own would be a delivery on the record
                         * that the shelf never heard about.
                         */
                        Repeater::make('lines')
                            ->hiddenLabel()
                            ->addActionLabel('Add another product')
                            ->defaultItems(1)
                            /** A delivery of nothing is not a delivery, and the ledger refuses one too. */
                            ->minItems(1)
                            ->reorderable(false)
                            /**
                             * A card per line rather than a table, because a
                             * seven-column table has nowhere to go on a phone
                             * and a challan is often typed in at the door.
                             * Two fields across on a phone, four on a tablet,
                             * and the whole line on one row on a wide screen,
                             * where it still reads down the page like the
                             * challan does.
                             */
                            ->columns(self::LINE_COLUMNS)
                            /**
                             * Named for its Product, so a scrolled list of
                             * cards can be checked line by line against the
                             * paper. `$state` is the line's snapshot, which
                             * is not validated but is dehydrated — so every
                             * dehydrator on a line has to take a half-typed
                             * one, or the page stops drawing.
                             */
                            ->itemLabel(fn (array $state): string => self::productName($state['product_id'] ?? null) ?? 'New product')
                            ->schema([
                                /**
                                 * The same Product may be listed twice, and
                                 * deliberately: a challan that names one
                                 * Product on two lines at two prices is two
                                 * arrivals of it, and the ledger records it
                                 * as two movements.
                                 */
                                Select::make('product_id')
                                    ->label('Product')
                                    ->options(fn (): array => Product::query()->orderBy('name')->pluck('name', 'id')->all())
                                    ->searchable()
                                    ->required()
                                    ->live()
                                    /** Another Product's Pack means nothing here. */
                                    ->afterStateUpdated(fn (Set $set): mixed => $set('pack_id', null))
                                    ->columnSpan(2),

                                ToggleButtons::make('unit')
                                    ->label('In')
                                    ->options([self::KILOGRAMS => 'KG', self::PACKETS => 'PCS'])
                                    ->default(self::KILOGRAMS)
                                    ->inline()
                                    ->grouped()
                                    ->columnSpan(self::LINE_FIELD_SPAN)
                                    ->live(),

                                /**
                                 * Only this Product's Packs, whichever ways
                                 * they are sold: a Pack is a weight a
                                 * delivery can come in. Needed to count
                                 * packets; optional on KG, where none means
                                 * the tea came loose.
                                 */
                                Select::make('pack_id')
                                    ->label('Pack')
                                    ->placeholder('Loose')
                                    ->options(fn (Get $get): array => Pack::query()
                                        ->where('product_id', $get('product_id'))
                                        ->orderBy('grams')
                                        ->get()
                                        ->mapWithKeys(fn (Pack $pack): array => [$pack->getKey() => $pack->name()])
                                        ->all())
                                    ->required(fn (Get $get): bool => self::isInPackets($get('unit')))
                                    ->columnSpan(self::LINE_FIELD_SPAN)
                                    ->live(),

                                /**
                                 * Kilograms or packets, as the challan says
                                 * them. The ledger counts grams (ADR-0035),
                                 * so a part of a kilogram is welcome to the
                                 * gram and `CreateSupply` converts. Kilograms
                                 * in a Pack must make whole packets — refused,
                                 * never rounded.
                                 */
                                TextInput::make('quantity')
                                    ->label('Quantity')
                                    ->required()
                                    ->numeric()
                                    ->step(fn (Get $get): int|float => self::isInPackets($get('unit')) ? 1 : 0.001)
                                    ->minValue(fn (Get $get): int|float => self::isInPackets($get('unit')) ? 1 : 0.001)
                                    ->rule(fn (Get $get): string => self::isInPackets($get('unit')) ? 'integer' : 'decimal:0,3')
                                    ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                        $packGrams = self::packGrams($get('pack_id'));

                                        if (self::isInPackets($get('unit')) || $packGrams === null || ! is_numeric($value)) {
                                            return;
                                        }

                                        if (Grams::packetsIn(Grams::fromKilograms($value), $packGrams) === null) {
                                            $fail("{$value} kg does not make whole ".Pack::nameFor($packGrams).' packets.');
                                        }
                                    })
                                    ->suffix(fn (Get $get): string => self::isInPackets($get('unit')) ? 'pcs' : 'kg')
                                    ->columnSpan(self::LINE_FIELD_SPAN)
                                    ->live(debounce: self::RECOMPUTE_DEBOUNCE),

                                /** The figure the line was not typed in, worked out as it is typed. */
                                TextEntry::make('makes')
                                    ->label('Makes')
                                    ->alignment(Alignment::End)
                                    ->state(fn (Get $get): string => self::makes([
                                        'unit' => $get('unit'),
                                        'pack_id' => $get('pack_id'),
                                        'quantity' => $get('quantity'),
                                    ])),

                                /**
                                 * What was paid for a kilogram, or for one
                                 * packet on a line typed in PCS, typed in
                                 * whole currency and stored in minor units
                                 * like every other money figure. A cost per
                                 * packet is stored as the cost per kilogram
                                 * it comes to: that is the number the
                                 * weighted average rolls forward from, and
                                 * the only cost anybody ever types.
                                 */
                                TextInput::make('unit_cost')
                                    ->label('Unit cost')
                                    ->required()
                                    ->numeric()
                                    ->minValue(0)
                                    ->prefix(fn (): string => app(GeneralSettings::class)->currencySymbol)
                                    ->suffix(fn (Get $get): string => self::isInPackets($get('unit')) ? '/ packet' : '/ kg')
                                    ->columnSpan(self::LINE_FIELD_SPAN)
                                    ->live(debounce: self::RECOMPUTE_DEBOUNCE)
                                    ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : Money::toMajorString($state))
                                    /** Null only on a line still being typed, which the card header snapshots; `required()` keeps it from being saved. */
                                    ->dehydrateStateUsing(fn (?string $state): ?int => $state === null ? null : Money::toMinorUnits($state)),

                                /** Recomputed from state, so it moves as the figures beside it are typed. */
                                TextEntry::make('line_cost')
                                    ->label('Line cost')
                                    ->alignment(Alignment::End)
                                    ->state(fn (Get $get): string => Money::format(self::lineCost([
                                        'unit' => $get('unit'),
                                        'pack_id' => $get('pack_id'),
                                        'quantity' => $get('quantity'),
                                        'unit_cost' => $get('unit_cost'),
                                    ]))),
                            ]),

                        /**
                         * Under the lines, at their right edge, where an
                         * invoice puts it, in the same card as the lines it
                         * adds up: this is the figure being checked against
                         * the document in somebody's hand. `gp-total` keeps
                         * the label beside the figure on a phone too, where
                         * Filament would stack it above.
                         */
                        Grid::make(['default' => 1, 'md' => 4])->schema([
                            TextEntry::make('total_cost')
                                ->label('Total')
                                ->inlineLabel()
                                ->extraEntryWrapperAttributes(['class' => 'gp-total'])
                                ->alignment(Alignment::End)
                                ->weight('bold')
                                ->size(TextSize::Large)
                                ->columnStart(['md' => 3])
                                ->columnSpan(['md' => 2])
                                ->state(fn (Get $get): string => Money::format(self::totalCost($get))),
                        ]),
                    ]),
            ]);
    }

    /**
     * The grams a line as typed comes to, or null where it does not yet say:
     * kilograms × 1000, or packets × its Pack's weight.
     *
     * @param  array{unit?: mixed, pack_id?: mixed, quantity?: mixed}  $line
     */
    public static function grams(array $line): ?int
    {
        $quantity = $line['quantity'] ?? null;

        if (! is_numeric($quantity)) {
            return null;
        }

        if (! self::isInPackets($line['unit'] ?? null)) {
            return Grams::fromKilograms($quantity);
        }

        $packGrams = self::packGrams($line['pack_id'] ?? null);
        $packets = filter_var($quantity, FILTER_VALIDATE_INT);

        return $packGrams === null || $packets === false ? null : Grams::ofPackets($packets, $packGrams);
    }

    /**
     * The cost per kilogram the ledger stamps for a line, from the cost typed
     * on it in minor units: as typed on KG, converted from a packet's on PCS.
     *
     * @param  array{unit?: mixed, pack_id?: mixed}  $line
     */
    public static function costPerKilogram(array $line, int $typedCost): int
    {
        $packGrams = self::packGrams($line['pack_id'] ?? null);

        return self::isInPackets($line['unit'] ?? null) && $packGrams !== null
            ? Grams::costPerKilogram($typedCost, $packGrams)
            : $typedCost;
    }

    /** Whether a line is typed as packets; anything else is kilograms, KG being the default. */
    private static function isInPackets(mixed $unit): bool
    {
        return $unit === self::PACKETS;
    }

    private static function productName(mixed $productId): ?string
    {
        return blank($productId) ? null : Product::query()->whereKey($productId)->value('name');
    }

    private static function packGrams(mixed $packId): ?int
    {
        return blank($packId) ? null : Pack::query()->whereKey($packId)->value('grams');
    }

    /**
     * The counterpart of what a line was typed in: the packets kilograms
     * make in a Pack ("4 PCS"), the weight of so many packets
     * ("2,500 g · 2.5 kg"), or "Loose" for kilograms in no Pack.
     *
     * @param  array{unit?: mixed, pack_id?: mixed, quantity?: mixed}  $line
     */
    private static function makes(array $line): string
    {
        $grams = self::grams($line);
        $packGrams = self::packGrams($line['pack_id'] ?? null);

        if (self::isInPackets($line['unit'] ?? null)) {
            return $grams === null ? '—' : number_format($grams).' g · '.Grams::format($grams);
        }

        if ($packGrams === null) {
            return 'Loose';
        }

        $packets = $grams === null ? null : Grams::packetsIn($grams, $packGrams);

        return $packets === null ? '—' : "{$packets} PCS";
    }

    /**
     * What one line costs, from the quantity and the unit cost as typed,
     * converted the way `CreateSupply` records it so the figure here is the
     * one the Supply will read.
     *
     * The cost is read in whole currency here because that is what the field
     * holds before it is dehydrated, which is the same conversion
     * `OrderForm`'s delivery charge makes — one place converts, and it is the
     * one the figure is typed into.
     *
     * @param  array{unit?: mixed, pack_id?: mixed, quantity?: mixed, unit_cost?: mixed}  $line
     */
    private static function lineCost(array $line): int
    {
        return Grams::cost(
            self::grams($line) ?? 0,
            self::costPerKilogram($line, Money::toMinorUnits($line['unit_cost'] ?? 0)),
        );
    }

    /**
     * What the whole delivery costs as the form currently stands, before
     * anything is saved.
     */
    private static function totalCost(Get $get): int
    {
        $lines = $get('lines');

        if (! is_array($lines)) {
            return 0;
        }

        return array_sum(array_map(
            fn (mixed $line): int => self::lineCost(is_array($line) ? $line : []),
            $lines,
        ));
    }
}
