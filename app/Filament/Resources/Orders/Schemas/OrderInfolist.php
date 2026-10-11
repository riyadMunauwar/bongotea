<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Models\Order;
use App\Models\OrderLine;
use App\Support\Money;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;

/**
 * Everything a person needs to make the confirmation call and hand the parcel
 * to a courier, on one screen, laid out as the invoice it stands in for.
 *
 * The shape is the document's: who and what at the top, the goods in a ruled
 * table down the middle, the money in a ladder at the bottom right. Prose that
 * would break that shape hides behind hint icons — nothing here is editable, so
 * a warning has no action attached to it and does not need to be read before
 * you can act.
 *
 * OrderForm mirrors this block for block. Changing the shape here means
 * changing it there.
 */
class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                ...OrderDocument::warnings(),

                Section::make('Order')
                    ->schema([
                        TextEntry::make('order_number')
                            ->label('Order number')
                            ->copyable()
                            ->weight('bold'),

                        TextEntry::make('status')
                            ->badge()
                            ->hintIcon(
                                Heroicon::OutlinedQuestionMarkCircle,
                                tooltip: 'Cancelled means the order genuinely happened but will not be fulfilled — an unreachable number, a changed mind, a refused parcel.',
                            ),

                        TextEntry::make('created_at')
                            ->label('Taken')
                            ->dateTime(),

                        OrderDocument::paymentMethod(),
                    ]),

                /**
                 * An optional detail the Customer left blank says so in its
                 * own place rather than reading as an Order someone filled in
                 * badly.
                 */
                Section::make('Deliver to')
                    ->schema([
                        TextEntry::make('customer_name')
                            ->label('Name')
                            ->placeholder(OrderDocument::NOT_ASKED),

                        TextEntry::make('customer_phone')
                            ->label('Phone')
                            ->copyable()
                            ->hintIcon(
                                Heroicon::OutlinedPhone,
                                tooltip: 'The number to call to confirm this order, in its canonical form. A customer is their phone number, so correcting it on the edit screen moves this order to a different customer.',
                            ),

                        TextEntry::make('customer_email')
                            ->label('Email')
                            ->copyable()
                            ->placeholder(OrderDocument::NOT_ASKED),

                        TextEntry::make('customer_district')
                            ->label('District'),

                        TextEntry::make('customer_address')
                            ->label('Address')
                            ->placeholder(OrderDocument::NOT_ASKED),

                        TextEntry::make('shop_name')
                            ->label('Shop name')
                            ->placeholder(OrderDocument::NOT_ASKED),

                        TextEntry::make('note')
                            ->label('Note')
                            ->placeholder(OrderDocument::NOT_ASKED),
                    ]),
                ...OrderDocument::payments(),

                ...OrderDocument::shipments(),

                Section::make('Items')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('lines')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Product'),
                                TableColumn::make('Unit price')
                                    ->alignment(Alignment::End),
                                TableColumn::make('Qty')
                                    ->alignment(Alignment::End),
                                TableColumn::make('Line total')
                                    ->alignment(Alignment::End),
                            ])
                            /**
                             * Labels repeat the column headings on purpose.
                             * Filament hides them once the table is wide enough
                             * to show its header row, and falls back to them
                             * when it stacks on a narrow screen.
                             */
                            ->schema([
                                TextEntry::make('product_name')
                                    ->label('Product')
                                    ->hintIcon(
                                        Heroicon::OutlinedQuestionMarkCircle,
                                        tooltip: 'As sold. The product may have been renamed or removed since.',
                                    ),

                                /** A Price Override says so, with its reason and who gave it. */
                                TextEntry::make('unit_price')
                                    ->label('Unit price')
                                    ->alignment(Alignment::End)
                                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                                    ->helperText(fn (OrderLine $record): ?string => $record->isPriceOverridden()
                                        ? 'Set by hand'.($record->priceOverriddenBy ? " by {$record->priceOverriddenBy->name}" : '').": {$record->price_override_reason}"
                                        : null),

                                TextEntry::make('quantity')
                                    ->label('Qty')
                                    ->alignment(Alignment::End),

                                TextEntry::make('line_total')
                                    ->label('Line total')
                                    ->alignment(Alignment::End)
                                    ->formatStateUsing(fn (int $state): string => Money::format($state)),
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
                            ->formatStateUsing(fn (int $state): string => Money::format($state)),

                        /**
                         * Hinted with the arrangement agreed at Capture, in the
                         * words the Customer chose it by. Whoever makes the
                         * confirmation call has to be able to say what the
                         * figure is for, and "Inside Dhaka" is an answer where
                         * a bare amount is not.
                         */
                        TextEntry::make('delivery_charge')
                            ->label('Delivery')
                            ->alignment(Alignment::End)
                            ->hint(fn (?Order $record): ?string => $record?->delivery_label)
                            ->formatStateUsing(fn (int $state): string => Money::format($state)),

                        /** Includes delivery, so nobody does arithmetic against a cash payment. */
                        TextEntry::make('total')
                            ->label('Total')
                            ->alignment(Alignment::End)
                            ->weight('bold')
                            ->size(TextSize::Large)
                            ->formatStateUsing(fn (int $state): string => Money::format($state)),

                        /** What the Customer was told they save against the MRP, so the call can repeat it. */
                        TextEntry::make('saving')
                            ->alignment(Alignment::End)
                            ->color('success')
                            ->state(fn (Order $record): int => $record->savingShown())
                            ->visible(fn (Order $record): bool => $record->savingShown() > 0)
                            ->formatStateUsing(fn (int $state): string => Money::format($state)),
                    ]),
            ]);
    }
}
