<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    /**
     * One way in by hand: an Order taken by phone.
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Take a phone order'),
        ];
    }

    /**
     * The fulfilment axis, and the only control for it: the `status`
     * SelectFilter was removed when these arrived, because two controls for one
     * axis can contradict each other — tab Confirmed, filter Cancelled, an
     * empty table and no way to see why.
     *
     * New leads, and `getDefaultActiveTab()` takes the first key, so the screen
     * opens on the confirmation calls nobody has made yet: this is a work
     * queue, not an archive. The rest read as the lifecycle does, with All
     * parked at the end as the catch-all. Keys are what `#[Url(as: 'tab')]`
     * writes into the address bar, so they stay lowercase and stable.
     *
     * What queue-first costs is a search from New that misses a Confirmed
     * Order, and the payment filters — which are cross-status and can only be
     * fully answered on All. `OrdersTable`'s empty state answers for both.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'new' => Tab::make('New')
                ->badge(fn (): ?int => OrderResource::newOrderCount() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', OrderStatus::New)),

            'confirmed' => Tab::make('Confirmed')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', OrderStatus::Confirmed)),

            'cancelled' => Tab::make('Cancelled')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', OrderStatus::Cancelled)),

            'all' => Tab::make('All'),
        ];
    }
}
