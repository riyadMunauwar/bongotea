<?php

namespace App\Filament\Resources\Shipments\Tables;

use App\Enums\Courier;
use App\Enums\ShipmentStatus;
use App\Filament\Resources\Shipments\Schemas\ShipmentInfolist;
use App\Models\Shipment;
use App\Support\Money;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every parcel, with the four names any of them is ever looked up by: ours,
 * the Courier's, the code the Customer follows, and the number of the Order
 * it came from.
 *
 * No bulk actions and no delete. A Shipment is the record that an attempt was
 * made and cost money; it outlives the parcel, and the only thing a User may
 * say about a dead one is that it is dead — which is Abandoned, a time and a
 * name written from the Order (ADR-0018).
 */
class ShipmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            /**
             * Newest first, like the Orders queue: the parcels worth looking
             * at are the ones that just went out and the ones that just went
             * wrong, and both are at this end of the list.
             */
            ->defaultSort('booked_at', 'desc')
            ->columns([
                /**
                 * Our reference, which carries the Order's number inside it:
                 * `{order number}-{attempt}`. So a User holding an order
                 * number finds every attempt made on it by typing that number
                 * here, and the column need not be joined to the Order to be
                 * searched by one.
                 */
                TextColumn::make('invoice_reference')
                    ->label('Reference')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('bold')
                    ->description(fn (Shipment $record): string => $record->order?->order_number ?? '—'),

                TextColumn::make('courier')
                    ->badge()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('State')
                    ->badge()
                    ->sortable()
                    /**
                     * The Courier's own word under our own, on every row: a
                     * list narrowed to On Hold still has to say which of four
                     * different things a given Courier means by it.
                     */
                    ->description(fn (Shipment $record): ?string => $record->raw_status),

                /** What a courier's support team will quote back down the phone, and what a webhook arrives carrying. */
                TextColumn::make('consignment_reference')
                    ->label('Consignment')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),

                TextColumn::make('tracking_code')
                    ->label('Tracking code')
                    ->searchable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),

                TextColumn::make('recipient_name')
                    ->label('Sent to')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Shipment $record): string => $record->recipient_phone),

                TextColumn::make('recipient_phone')
                    ->label('Phone')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('recipient_district')
                    ->label('District')
                    ->searchable()
                    ->toggleable()
                    ->placeholder('—'),

                TextColumn::make('collect_amount')
                    ->label('To collect')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->sortable(),

                TextColumn::make('courier_charge')
                    ->label('Charge')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder(ShipmentInfolist::CHARGE_NOT_SAID),

                TextColumn::make('booked_at')
                    ->label('Handed over')
                    ->dateTime()
                    ->sortable()
                    ->description(fn (Shipment $record): ?string => $record->booked_at?->diffForHumans()),

                /**
                 * A fact that is neither a status nor an outcome, so it cannot
                 * be read off the State column: a parcel nobody has been able
                 * to account for in a week. Off by default, because a healthy
                 * installation has none.
                 */
                TextColumn::make('stuck_at')
                    ->label('Stuck since')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->color('warning')
                    ->placeholder('—'),
            ])
            /** The Order is read for every row's reference line, so it is loaded once for the page rather than once per row. */
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('order'))
            ->filters([
                /**
                 * The two axes a parcel is ever narrowed by, and both are
                 * choices rather than toggles: "which of RedEx's are on hold"
                 * is the question this screen exists for, and it needs one of
                 * each. The Courier's own words are deliberately not a filter
                 * — there are forty of them across three vocabularies, which
                 * is exactly why the canonical seven exist.
                 */
                SelectFilter::make('courier')
                    ->label('Courier')
                    ->options(Courier::class),

                SelectFilter::make('status')
                    ->label('State')
                    ->options(ShipmentStatus::class),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
