<?php

namespace App\Filament\Clusters\Inventory\Resources\Supplies\Schemas;

use App\Models\Product;
use App\Support\Money;
use App\Support\Settings\GeneralSettings;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
                            ->table([
                                TableColumn::make('Product')
                                    ->markAsRequired(),
                                TableColumn::make('Quantity')
                                    ->alignment(Alignment::End)
                                    ->markAsRequired(),
                                TableColumn::make('Unit cost')
                                    ->alignment(Alignment::End)
                                    ->markAsRequired(),
                                TableColumn::make('Line cost')
                                    ->alignment(Alignment::End),
                            ])
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
                                    ->required(),

                                TextInput::make('quantity')
                                    ->label('Quantity')
                                    ->required()
                                    ->integer()
                                    ->minValue(1)
                                    ->live(debounce: self::RECOMPUTE_DEBOUNCE),

                                /**
                                 * What was paid for one of them, typed in
                                 * whole currency and stored in minor units
                                 * like every other money figure
                                 * (`Product::price`, `Order::total`). This is
                                 * the number the weighted average rolls
                                 * forward from, and the only cost anybody
                                 * ever types: every other figure about cost
                                 * in this system is worked out from rows like
                                 * this one.
                                 */
                                TextInput::make('unit_cost')
                                    ->label('Unit cost')
                                    ->required()
                                    ->numeric()
                                    ->minValue(0)
                                    ->prefix(fn (): string => app(GeneralSettings::class)->currencySymbol)
                                    ->live(debounce: self::RECOMPUTE_DEBOUNCE)
                                    ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : Money::toMajorString($state))
                                    ->dehydrateStateUsing(fn (string $state): int => Money::toMinorUnits($state)),

                                /** Recomputed from state, so it moves as the figures beside it are typed. */
                                TextEntry::make('line_cost')
                                    ->label('Line cost')
                                    ->alignment(Alignment::End)
                                    ->state(fn (Get $get): string => Money::format(self::lineCost([
                                        'quantity' => $get('quantity'),
                                        'unit_cost' => $get('unit_cost'),
                                    ]))),
                            ]),
                    ]),

                /**
                 * Under the right edge of the lines, where an invoice puts
                 * it, for the reason `OrderForm`'s "To collect at the door"
                 * sits there: this is the figure being checked against the
                 * document in somebody's hand.
                 */
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
                            ->state(fn (Get $get): string => Money::format(self::totalCost($get))),
                    ]),
            ]);
    }

    /**
     * What one line costs, from the quantity and the unit cost as typed.
     *
     * The cost is read in whole currency here because that is what the field
     * holds before it is dehydrated, which is the same conversion
     * `OrderForm`'s delivery charge makes — one place converts, and it is the
     * one the figure is typed into.
     *
     * @param  array{quantity?: mixed, unit_cost?: mixed}  $line
     */
    private static function lineCost(array $line): int
    {
        return (int) ($line['quantity'] ?? 0) * Money::toMinorUnits($line['unit_cost'] ?? 0);
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
