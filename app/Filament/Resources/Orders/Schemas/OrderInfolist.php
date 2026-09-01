<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Support\Money;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;

/**
 * Everything a person needs to make the confirmation call and hand the parcel
 * to a courier, on one screen.
 */
class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Order')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('order_number')
                            ->label('Order number')
                            ->copyable()
                            ->weight('bold'),

                        TextEntry::make('status')
                            ->badge(),

                        TextEntry::make('created_at')
                            ->label('Taken')
                            ->dateTime(),

                        /**
                         * The slug as captured, not the Page's current one. A
                         * renamed or deleted Page must not rewrite which
                         * campaign this order belongs to.
                         */
                        TextEntry::make('page_slug')
                            ->label('Ordered from'),
                    ]),

                Section::make('Deliver to')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('customer_name')
                            ->label('Name'),

                        TextEntry::make('customer_phone')
                            ->label('Phone')
                            ->copyable()
                            ->helperText('The number to call to confirm this order.'),

                        TextEntry::make('customer_district')
                            ->label('District'),

                        TextEntry::make('customer_address')
                            ->label('Address')
                            ->columnSpanFull(),
                    ]),

                Section::make('Items')
                    ->schema([
                        RepeatableEntry::make('lines')
                            ->hiddenLabel()
                            ->columns(4)
                            ->schema([
                                TextEntry::make('product_name')
                                    ->label('Product')
                                    ->helperText('As sold. The product may have been renamed or removed since.'),

                                TextEntry::make('unit_price')
                                    ->label('Unit price')
                                    ->formatStateUsing(fn (int $state): string => Money::format($state)),

                                TextEntry::make('quantity')
                                    ->label('Qty'),

                                TextEntry::make('line_total')
                                    ->label('Line total')
                                    ->formatStateUsing(fn (int $state): string => Money::format($state)),
                            ]),
                    ]),

                Section::make('To collect at the door')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('subtotal')
                            ->formatStateUsing(fn (int $state): string => Money::format($state)),

                        TextEntry::make('delivery_charge')
                            ->label('Delivery')
                            ->formatStateUsing(fn (int $state): string => Money::format($state)),

                        /** Includes delivery, so nobody does arithmetic against a cash payment. */
                        TextEntry::make('total')
                            ->label('Total')
                            ->weight('bold')
                            ->size(TextSize::Large)
                            ->formatStateUsing(fn (int $state): string => Money::format($state)),
                    ]),
            ]);
    }
}
