<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Models\Customer;
use App\Models\Order;
use App\Support\Kilograms;
use App\Support\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Who a Customer has said they are, where their tea has gone, and what their
 * Orders come to.
 *
 * Every name, shop and address is listed rather than the latest alone, newest
 * first and each once, because that is what an order-taker on the phone is
 * asking: is this the same shop as before? The figures are the ones the list
 * draws, loaded by the resource's query.
 */
class CustomerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Who they are')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('phone')
                            ->copyable()
                            ->weight('bold'),

                        TextEntry::make('names')
                            ->label('Names given')
                            ->state(fn (Customer $record): array => self::usedOnOrders($record, 'customer_name'))
                            ->listWithLineBreaks()
                            ->placeholder('—'),

                        TextEntry::make('shops')
                            ->label('Shops')
                            ->state(fn (Customer $record): array => self::usedOnOrders($record, 'shop_name'))
                            ->listWithLineBreaks()
                            ->placeholder('—'),

                        TextEntry::make('addresses')
                            ->label('Delivered to')
                            ->state(fn (Customer $record): array => self::addressesDeliveredTo($record))
                            ->listWithLineBreaks()
                            ->placeholder('—'),
                    ]),

                Section::make('What they have ordered')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextEntry::make('orders_count')
                            ->label('Orders')
                            ->numeric(),

                        TextEntry::make('cancelled_count')
                            ->label('Cancelled')
                            ->numeric(),

                        TextEntry::make('kilograms')
                            ->label('Kilograms Sold')
                            ->state(fn (Customer $record): string => Kilograms::format(Kilograms::fromGrams((int) $record->getAttribute('grams')))),

                        TextEntry::make('sales')
                            ->state(fn (Customer $record): string => Money::format((int) $record->getAttribute('sales'))),

                        TextEntry::make('average_order_value')
                            ->label('Average Order Value')
                            ->state(fn (Customer $record): string => Money::format(self::averageOrderValue($record))),

                        TextEntry::make('first_ordered_at')
                            ->label('First ordered')
                            ->dateTime()
                            ->placeholder('—'),

                        TextEntry::make('last_ordered_at')
                            ->label('Last ordered')
                            ->dateTime()
                            ->placeholder('—'),
                    ]),
            ]);
    }

    /**
     * Each distinct value a column holds across the Customer's Orders, from
     * the Order captured last, blanks left out.
     *
     * @return list<string>
     */
    private static function usedOnOrders(Customer $record, string $column): array
    {
        return $record->orders()
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->pluck($column)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Each distinct address and District the Customer's Orders went to, from
     * the Order captured last.
     *
     * @return list<string>
     */
    private static function addressesDeliveredTo(Customer $record): array
    {
        return $record->orders()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['customer_address', 'customer_district'])
            ->map(fn (Order $order): string => collect([$order->customer_address, $order->customer_district])->filter()->implode(', '))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Sales over the Orders that count towards it, as the glossary reads
     * Average Order Value: zero, not undefined, when there are none.
     */
    private static function averageOrderValue(Customer $record): int
    {
        $counted = (int) $record->getAttribute('orders_count') - (int) $record->getAttribute('cancelled_count');

        return $counted === 0 ? 0 : (int) round((int) $record->getAttribute('sales') / $counted);
    }
}
