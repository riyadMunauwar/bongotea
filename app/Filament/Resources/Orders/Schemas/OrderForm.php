<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Support\Money;
use App\Support\PhoneNumber;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;

/**
 * What may be changed about an Order after Capture, in the shape of the
 * document it edits.
 *
 * Block for block this is OrderInfolist, so opening the editor moves nothing on
 * screen except which cells accept typing. Everything fixed at Capture renders
 * as text rather than as a disabled input: a greyed-out box still looks like
 * something you might be able to fix, and the whole point of this screen is
 * that most of it cannot be.
 *
 * Filament hands you full-field editing by default, so everything agreed at
 * Capture has to be *actively* held shut here rather than assumed immutable.
 * What was agreed is what gets collected at the door: a price edited after the
 * fact means a fulfiller asking for a figure the customer never accepted, and
 * in COD that is a refused parcel and a courier fee.
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

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                ...OrderDocument::warnings(),

                Section::make('Order')
                    ->schema([
                        TextEntry::make('order_number')
                            ->label('Order number')
                            ->state(fn (?Order $record): ?string => $record?->order_number)
                            ->copyable()
                            ->weight('bold'),

                        Select::make('status')
                            ->options(OrderStatus::class)
                            ->required()
                            ->native(false)
                            ->hintIcon(
                                Heroicon::OutlinedQuestionMarkCircle,
                                tooltip: 'The confirmation call is the COD workflow — mark what happened so the same customer is not called twice, or missed. Cancelled means the order genuinely happened but will not be fulfilled.',
                            ),

                        TextEntry::make('created_at')
                            ->label('Taken')
                            ->state(fn (?Order $record) => $record?->created_at)
                            ->dateTime(),

                        /**
                         * The slug as captured, not the Page's current one. A
                         * renamed or deleted Page must not rewrite which
                         * campaign this order belongs to.
                         */
                        TextEntry::make('page_slug')
                            ->label('Ordered from')
                            ->state(fn (?Order $record): ?string => $record?->page_slug),
                    ]),

                /**
                 * Only the phone is compulsory here. Every other detail is a
                 * question the Page's Checkout may never have asked, and an
                 * editor that refused to save without one would make a
                 * perfectly good Order unworkable — the operator would have to
                 * invent an address to correct a quantity.
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
                         */
                        TextInput::make('customer_phone')
                            ->label('Phone')
                            ->required()
                            ->maxLength(32)
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
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
                            ->searchable()
                            ->native(false)
                            ->placeholder(OrderDocument::NOT_ASKED),

                        Textarea::make('customer_address')
                            ->label('Address')
                            ->rows(3)
                            ->maxLength(1000)
                            ->dehydrateStateUsing(self::nullWhenBlank())
                            ->placeholder(OrderDocument::NOT_ASKED),
                    ]),

                Section::make('Items')
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('lines')
                            ->relationship()
                            ->hiddenLabel()
                            /** An Order's shape was fixed at Capture. Only its numbers move. */
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->table([
                                TableColumn::make('Product'),
                                TableColumn::make('Unit price')
                                    ->alignment(Alignment::End),
                                TableColumn::make('Qty')
                                    ->alignment(Alignment::End)
                                    ->markAsRequired(),
                                TableColumn::make('Line total')
                                    ->alignment(Alignment::End),
                            ])
                            ->schema([
                                TextEntry::make('product_name')
                                    ->label('Product')
                                    ->hintIcon(
                                        Heroicon::OutlinedQuestionMarkCircle,
                                        tooltip: 'As sold. The product may have been renamed or removed since, which is why the name is fixed here.',
                                    ),

                                TextEntry::make('unit_price')
                                    ->label('Unit price')
                                    ->alignment(Alignment::End)
                                    ->formatStateUsing(fn (mixed $state): string => Money::format((int) $state))
                                    ->hintIcon(
                                        Heroicon::OutlinedLockClosed,
                                        tooltip: 'The price agreed at Capture. Totals recompute from this, never from the price the product carries today.',
                                    ),

                                TextInput::make('quantity')
                                    ->label('Qty')
                                    ->required()
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(config('giopages.checkout.max_quantity'))
                                    ->live(debounce: self::RECOMPUTE_DEBOUNCE),

                                /** Recomputed from state, so it moves as the quantity beside it is typed. */
                                TextEntry::make('line_total')
                                    ->label('Line total')
                                    ->alignment(Alignment::End)
                                    ->state(fn (Get $get): string => Money::format(self::lineTotal([
                                        'unit_price' => $get('unit_price'),
                                        'quantity' => $get('quantity'),
                                    ]))),
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
                            ->state(fn (Get $get): string => Money::format(self::subtotal($get))),

                        /**
                         * Hinted with the arrangement agreed at Capture, which
                         * is fixed the way a Product's name is: the amount can
                         * be corrected on the call, but what the Customer chose
                         * is history.
                         */
                        TextInput::make('delivery_charge')
                            ->label('Delivery')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->prefix(config('giopages.currency.symbol'))
                            ->hint(fn (?Order $record): ?string => $record?->delivery_label)
                            ->live(debounce: self::RECOMPUTE_DEBOUNCE)
                            ->hintIcon(
                                Heroicon::OutlinedQuestionMarkCircle,
                                tooltip: 'Editable, because what the courier charges is agreed on the call. The total follows automatically.',
                            )
                            ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : Money::toMajorString($state))
                            ->dehydrateStateUsing(fn (string $state): int => Money::toMinorUnits($state)),

                        /** Includes delivery, so nobody does arithmetic against a cash payment. */
                        TextEntry::make('total')
                            ->label('Total')
                            ->alignment(Alignment::End)
                            ->weight('bold')
                            ->size(TextSize::Large)
                            ->state(fn (Get $get): string => Money::format(
                                self::subtotal($get) + Money::toMinorUnits($get('delivery_charge') ?? 0)
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

    /**
     * What one line costs, from the price snapshotted at Capture.
     *
     * Mirrors OrderLineObserver::saving so the figure on screen is the figure
     * that will be stored — never the Product's current price, which is the bug
     * the snapshot exists to close.
     *
     * @param  array{unit_price?: mixed, quantity?: mixed}  $line
     */
    private static function lineTotal(array $line): int
    {
        return (int) ($line['unit_price'] ?? 0) * (int) ($line['quantity'] ?? 0);
    }

    /**
     * The subtotal as the form currently stands, before anything is saved.
     *
     * Read from form state rather than from the Order, because the Order's
     * stored subtotal is still the pre-edit figure until OrderLineObserver has
     * run — which is only after a save this screen has not performed yet.
     */
    private static function subtotal(Get $get): int
    {
        $lines = $get('lines');

        if (! is_array($lines)) {
            return 0;
        }

        return array_sum(array_map(
            fn (mixed $line): int => self::lineTotal(is_array($line) ? $line : []),
            $lines,
        ));
    }
}
