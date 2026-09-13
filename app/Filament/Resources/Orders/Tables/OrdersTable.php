<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Filament\Resources\Orders\Actions\BookBatchWithCourierAction;
use App\Filament\Resources\Orders\Actions\BookWithCourierAction;
use App\Models\Order;
use App\Support\Money;
use App\Support\Settings\GeneralSettings;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
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

                /**
                 * The delivery axis, and the third one on this row: an Order
                 * is New, Unpaid and On Hold at once, and none of the three
                 * ever writes another (ADR-0018). Drawn from the Active
                 * Shipment, so it is blank for an Order nobody has sent and
                 * blank again once a parcel is over — a Returned Order is one
                 * to send again, not one still out.
                 *
                 * Neither sortable nor searchable: both would mean a join or
                 * an index for a column an operator scans rather than looks
                 * something up by, and the Shipments list is where a parcel is
                 * found by name.
                 */
                TextColumn::make('activeShipment.status')
                    ->label('Delivery')
                    ->badge()
                    ->placeholder('—')
                    /** The Courier under its own word: "on hold" is a different phone call depending on who is holding it. */
                    ->description(fn (Order $record): ?string => $record->activeShipment?->courier->getLabel()),
            ])
            /**
             * One query answers "is it Paid" for the whole page, not one per
             * row — one more answers where every parcel is, which the Delivery
             * column draws and the send action asks again, and one more
             * answers whether a parcel of the Order's ever arrived, which is
             * the other half of what the send action asks and the half no
             * loaded relation can answer.
             */
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withPaidFlag()->withArrivedFlag()->with('activeShipment'))
            ->filters([
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
                 * The gap parcels fall through silently: an Order captured
                 * three days ago that nobody ever sent reads exactly like one
                 * booked this morning. A toggle like the payment pair, and
                 * cross-status like them too — an Order is unsent and New, or
                 * unsent and Confirmed, and the question is the same one from
                 * every tab, which is why it is a filter rather than a fourth
                 * tab.
                 */
                Filter::make('not_yet_sent')
                    ->label('Not yet sent to a courier')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->notYetSent()),

                /**
                 * "What came in today" is the question this screen is opened
                 * to answer, so the date range is the filter that matters.
                 * Bounds are inclusive of both days a human would name, and
                 * a day is the Merchant's: it starts at midnight in the
                 * timezone Setting, not at UTC midnight, so the list agrees
                 * with the capture times it shows and with every report.
                 */
                Filter::make('taken')
                    ->schema([
                        DatePicker::make('from')->label('Taken from'),
                        DatePicker::make('until')->label('Taken until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->where('created_at', '>=', self::merchantMidnight($date)))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->where('created_at', '<', self::merchantMidnight($date, daysLater: 1))))
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
            /**
             * The escape from a tab that has narrowed the list twice over.
             *
             * `ListOrders` opens on New, so an operator searching for an order
             * a colleague confirmed yesterday gets an empty table and no reason
             * for it — which reads as "no such order", the one thing this
             * screen must never say about an order it holds. The same goes for
             * the payment filters, which are cross-status: "Paid but cancelled"
             * is a Cancelled-only question and can never answer from New.
             *
             * One mechanism covers both, and anything cross-status added later:
             * name the tab that is doing the narrowing, and offer the tab that
             * can answer. Only `activeTab` moves, so the search and filters
             * carry across untouched.
             */
            ->emptyStateHeading(function (Table $table, ListRecords $livewire): ?string {
                $tab = self::narrowedTabLabel($table, $livewire);

                if ($tab === null) {
                    return null;
                }

                $search = $livewire->getTableSearch();

                /** Both can be narrowing at once, and naming only one sends the operator back for the other. */
                return match (true) {
                    filled($search) && self::isNarrowedAcrossStatuses($table) => "No {$tab} orders match {$search} with the filters you have set",
                    filled($search) => "No {$tab} orders match {$search}",
                    default => "No {$tab} orders match the filters you have set",
                };
            })
            ->emptyStateDescription(fn (Table $table, ListRecords $livewire): ?string => self::narrowedTabLabel($table, $livewire) === null
                ? null
                : 'The order you are looking for may have another status.')
            ->emptyStateActions([
                Action::make('showInAllOrders')
                    ->label('Show in all orders')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->visible(fn (Table $table, ListRecords $livewire): bool => self::narrowedTabLabel($table, $livewire) !== null)
                    ->action(function (ListRecords $livewire): void {
                        $livewire->activeTab = 'all';
                        $livewire->updatedActiveTab();
                    }),
            ])
            ->recordActions([
                /**
                 * The same dialog the Order's own screens open, reached from
                 * the row: a User working down a day's Orders sends each
                 * parcel without opening any of them, and reads back exactly
                 * what the Courier will be told before it goes. The action
                 * hides itself on an Order already out, here as there.
                 */
                BookWithCourierAction::make(),

                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                /**
                 * A morning's Orders out in one action, off the same list and
                 * the same selection the User is looking at. Its own button
                 * rather than a line in the group below: it is the reason a
                 * User ticks rows on this screen at all, where deleting and
                 * restoring are the housekeeping every resource has.
                 */
                BookBatchWithCourierAction::make(),

                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    /**
     * The filters that ask something the status tabs cannot answer from where
     * they stand: "Paid but cancelled" is a Cancelled-only question, and
     * "Unpaid online orders" and "Not yet sent to a courier" span all three.
     * Deliberately not every filter — the date range and TrashedFilter say
     * nothing about status, so an empty table under one of those must not be
     * blamed on the tab, and offering to move to All would only trade a named
     * cause for Filament's silent one.
     *
     * @var list<string>
     */
    private const CROSS_STATUS_FILTERS = ['unpaid', 'paid_but_cancelled', 'not_yet_sent'];

    private static function isNarrowedAcrossStatuses(Table $table): bool
    {
        foreach (self::CROSS_STATUS_FILTERS as $name) {
            if (($table->getFilter($name)?->getActiveCount() ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The label of the active tab when it is not the whole list and something
     * that could be answered elsewhere is narrowing too. Null everywhere else,
     * which leaves Filament's own empty state to say what it already says
     * correctly: an empty New queue with nothing set is the good state, not a
     * dead end.
     *
     * The label is read off the Tab rather than written twice, so renaming one
     * renames it here.
     */
    private static function narrowedTabLabel(Table $table, ListRecords $livewire): ?string
    {
        if (blank($livewire->activeTab) || $livewire->activeTab === 'all') {
            return null;
        }

        if (! $livewire->hasTableSearch() && ! self::isNarrowedAcrossStatuses($table)) {
            return null;
        }

        $label = ($livewire->getCachedTabs()[$livewire->activeTab] ?? null)?->getLabel();

        return filled($label) ? (string) $label : null;
    }

    /**
     * Midnight beginning the named day in the Merchant timezone, as the UTC
     * instant a stored capture time is compared against. Whole days are
     * stepped in local time before the conversion, so a daylight-saving
     * change never lands a boundary an hour off midnight.
     */
    private static function merchantMidnight(string $date, int $daysLater = 0): CarbonImmutable
    {
        return CarbonImmutable::parse($date, app(GeneralSettings::class)->timezone)
            ->startOfDay()
            ->addDays($daysLater)
            ->utc();
    }
}
