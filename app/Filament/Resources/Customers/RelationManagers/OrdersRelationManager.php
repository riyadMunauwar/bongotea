<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\OrderLine;
use App\Support\Kilograms;
use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every Order a Customer placed, newest first, Cancelled ones included — the
 * status column tells them apart. A deleted Order is gone here as it is
 * everywhere else.
 *
 * The columns are the Orders queue's, less the ones that would say the same
 * Customer on every row, and with the Order's Kilograms beside its money. A
 * row opens the Order, which is where anything is done to it: nothing is
 * created, attached or deleted from here, since a new Order is taken on the
 * Orders screen and the number on it is what finds this Customer.
 */
class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('order_number')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withSum('lines as grams', OrderLine::grams())
                ->withPaidFlag()
                ->with('activeShipment'))
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('order_number')
                    ->label('Order')
                    ->weight('bold'),

                TextColumn::make('created_at')
                    ->label('Taken')
                    ->dateTime()
                    ->sortable()
                    ->description(fn (Order $record): string => $record->created_at->diffForHumans()),

                TextColumn::make('status')
                    ->badge(),

                TextColumn::make('payment_method')
                    ->label('Payment')
                    ->badge()
                    ->formatStateUsing(fn (Order $record): string => $record->paymentBadgeLabel())
                    ->color(fn (Order $record): string => $record->paymentBadgeColor()),

                TextColumn::make('activeShipment.status')
                    ->label('Delivery')
                    ->badge()
                    ->placeholder('—')
                    ->description(fn (Order $record): ?string => $record->activeShipment?->courier->getLabel()),

                TextColumn::make('kilograms')
                    ->state(fn (Order $record): int|float => Kilograms::fromGrams((int) $record->getAttribute('grams')))
                    ->formatStateUsing(fn (int|float $state): string => Kilograms::format($state))
                    ->alignEnd(),

                TextColumn::make('total')
                    ->label('To collect')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->alignEnd(),
            ])
            ->emptyStateHeading('No Orders')
            ->emptyStateDescription('Every Order this number placed has been deleted.');
    }
}
