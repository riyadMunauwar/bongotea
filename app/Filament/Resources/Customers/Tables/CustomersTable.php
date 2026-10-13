<?php

namespace App\Filament\Resources\Customers\Tables;

use App\Models\Customer;
use App\Support\Kilograms;
use App\Support\Money;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every Customer, the one who ordered last first, with what their Orders come
 * to. The figures are `Customer::withOrderFigures()`'s, loaded by the
 * resource's query; nothing here counts.
 *
 * A name and a shop are found through the Orders that carry them, because
 * that is where they live: one number may have ordered as "Rahim" and as
 * "Rahim Store", and either finds it.
 */
class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            /**
             * Nulls last said out loud: PostgreSQL puts them first on a
             * descending sort and SQLite last, and a Customer whose Orders
             * were all deleted is the one least worth the top of the list.
             */
            ->defaultSort(fn (Builder $query): Builder => $query->orderByRaw('last_ordered_at desc nulls last'))
            ->columns([
                TextColumn::make('phone')
                    ->searchable()
                    ->copyable()
                    ->weight('bold'),

                TextColumn::make('latest_name')
                    ->label('Name')
                    ->placeholder('—')
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::orderedAs($query, 'customer_name', $search)),

                TextColumn::make('latest_shop')
                    ->label('Shop')
                    ->placeholder('—')
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::orderedAs($query, 'shop_name', $search)),

                TextColumn::make('latest_district')
                    ->label('District')
                    ->placeholder('—'),

                TextColumn::make('orders_count')
                    ->label('Orders')
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('kilograms')
                    ->label('Kilograms Sold')
                    ->state(fn (Customer $record): int|float => Kilograms::fromGrams((int) $record->getAttribute('grams')))
                    ->formatStateUsing(fn (int|float $state): string => Kilograms::format($state))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('grams', $direction))
                    ->alignEnd(),

                TextColumn::make('sales')
                    ->state(fn (Customer $record): int => (int) $record->getAttribute('sales'))
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('first_ordered_at')
                    ->label('First ordered')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('last_ordered_at')
                    ->label('Last ordered')
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('No Customers yet')
            ->emptyStateDescription('A Customer appears here the first time an Order names their phone number.');
    }

    /**
     * The Customers who gave something like the search on any Order of theirs.
     *
     * Lower-cased on both sides, as Filament's own search is, because LIKE is
     * case-sensitive on PostgreSQL where it is not on SQLite.
     *
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    private static function orderedAs(Builder $query, string $column, string $search): Builder
    {
        return $query->whereHas('orders', fn (Builder $orders): Builder => $orders->whereRaw("lower({$column}) like ?", ['%'.mb_strtolower($search).'%']));
    }
}
