<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderStatus;
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

                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(OrderStatus::class),

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
