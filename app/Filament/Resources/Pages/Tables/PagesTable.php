<?php

namespace App\Filament\Resources\Pages\Tables;

use App\Enums\PageStatus;
use App\Models\Page;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class PagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            /**
             * Counted in the list query rather than per row: the lost-product
             * mark would otherwise be a query per Page on a screen that shows
             * a page of them.
             */
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('deletedProducts'))
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('slug')
                    ->prefix('/')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (PageStatus $state): string => match ($state) {
                        PageStatus::Published => 'success',
                        PageStatus::Draft => 'gray',
                    }),

                TextColumn::make('products.name')
                    ->label('Products')
                    ->listWithLineBreaks()
                    ->placeholder('None'),

                /**
                 * A published Page selling fewer Products than it was linked
                 * with, said out loud.
                 *
                 * The Checkout drops a soft-deleted Product and keeps selling
                 * the rest, which is the cheap failure to recover from and an
                 * entirely silent one — the merchant would otherwise meet it as
                 * a dip in a conversion graph days later. A draft is not marked:
                 * nothing is being spent on it.
                 *
                 * No header: the badge is the whole message, and a column title
                 * over a cell that is empty on every healthy Page reads as a
                 * missing value rather than as good news.
                 *
                 * Whether to mark is the Page's answer; this is only its
                 * wording. The tooltip is a closure for the same reason the
                 * state is — an unconditional one hangs off the empty cell of
                 * every healthy Page too.
                 */
                TextColumn::make('deleted_products_count')
                    ->label('')
                    ->badge()
                    ->color('danger')
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->state(fn (Page $record): ?string => $record->hasLostProducts()
                        ? $record->lostProductCount().' '.Str::plural('product', $record->lostProductCount()).' deleted'
                        : null)
                    /**
                     * Names only what a merchant can actually do. The link cannot
                     * be re-picked while its Product is in the trash — a deleted
                     * Product is not offered by the Checkout tab's select — so
                     * restoring it and deleting it for good are the two exits,
                     * and the second is what clears a loss the merchant accepts.
                     */
                    ->tooltip(fn (Page $record): ?string => $record->hasLostProducts()
                        ? 'The checkout is still selling this page\'s remaining products. Restore the deleted product, or delete it permanently, to clear this.'
                        : null)
                    ->placeholder(''),

                TextColumn::make('published_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),
            ])
            /**
             * Sorts on a column the table no longer shows. Deliberate: this is an
             * authoring list, so "what did I touch last" is the useful order, and
             * published_at would sink every draft below every published page.
             */
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(PageStatus::class),
                TrashedFilter::make(),
            ])
            ->recordActions([
                /**
                 * Preview runs the same expansion pipeline behind admin auth,
                 * so the merchant can look at a page without publishing it —
                 * publishing being the act that makes it reachable to ad
                 * traffic.
                 */
                Action::make('preview')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Page $record): string => route('pages.preview', $record))
                    ->openUrlInNewTab(),

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
