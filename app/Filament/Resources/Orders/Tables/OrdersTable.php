<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Support\Money;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            /**
             * Newest first: this is a work queue, not an archive. Filament adds
             * the key as a tie-breaker of its own, which is what keeps two
             * orders taken in the same second from swapping places between
             * pages.
             */
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('order_number')
                    ->label('Order')
                    ->searchable()
                    ->copyable()
                    ->weight('bold'),

                TextColumn::make('created_at')
                    ->label('Taken')
                    ->dateTime()
                    ->sortable()
                    ->description(fn ($record): string => $record->created_at->diffForHumans()),

                /**
                 * Blank where the Page never asked for a name. The phone beside
                 * it is what identifies a Customer, so a nameless row is still
                 * a workable order rather than a broken one.
                 */
                TextColumn::make('customer_name')
                    ->label('Customer')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('customer_phone')
                    ->label('Phone')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('customer_district')
                    ->label('District')
                    ->searchable()
                    ->toggleable()
                    ->placeholder('—'),

                /**
                 * Off by default in a new Checkout, so most installations have
                 * an empty column here — hidden until a merchant who asks for
                 * one turns it on.
                 */
                TextColumn::make('customer_email')
                    ->label('Email')
                    ->searchable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),

                /**
                 * The snapshot, not the relation: this column must keep saying
                 * which campaign an order came from after the Page is renamed
                 * or deleted.
                 */
                TextColumn::make('page_slug')
                    ->label('Page')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('total')
                    ->label('To collect')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->sortable(),

                /**
                 * The payment axis, beside the fulfilment one and never folded
                 * into it (ADR-0013). The Order words and colours its own
                 * badge, so this column and the Order page cannot disagree.
                 */
                TextColumn::make('payment_method')
                    ->label('Payment')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (Order $record): string => $record->paymentBadgeLabel())
                    ->color(fn (Order $record): string => $record->paymentBadgeColor()),

                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
            ])
            /** One query answers "is it Paid" for the whole page, not one per row. */
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withPaidFlag())
            ->filters([
                SelectFilter::make('status')
                    ->options(OrderStatus::class),

                /**
                 * The two lists the payment axis is opened for: the Unpaid
                 * online Orders to chase, and the Orders both Paid and
                 * Cancelled to refund. Each is a toggle rather than a choice
                 * because neither has an opposite anyone would ask for.
                 */
                Filter::make('unpaid')
                    ->label('Unpaid online orders')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->unpaid()),

                Filter::make('paid_but_cancelled')
                    ->label('Paid but cancelled')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->paidButCancelled()),

                /**
                 * "What came in today" is the question this screen is opened
                 * to answer, so the date range is the filter that matters.
                 * Bounds are inclusive of both days a human would name.
                 */
                Filter::make('taken')
                    ->schema([
                        DatePicker::make('from')->label('Taken from'),
                        DatePicker::make('until')->label('Taken until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['from'] ?? null) {
                            $indicators[] = 'Taken from '.$data['from'];
                        }

                        if ($data['until'] ?? null) {
                            $indicators[] = 'Taken until '.$data['until'];
                        }

                        return $indicators;
                    }),

                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
