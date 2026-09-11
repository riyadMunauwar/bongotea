<?php

namespace App\Filament\Resources\Orders;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\Schemas\OrderForm;
use App\Filament\Resources\Orders\Schemas\OrderInfolist;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * The fulfilment surface.
 *
 * There is deliberately no create page: an Order comes into existence at
 * Capture, when a visitor submits a Checkout. An Order typed in here would have
 * no Page behind it and no Customer resolved from a phone number, so it would
 * be a different thing wearing the same name.
 */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    /**
     * A bag, which is a purchase that already happened — not a cart, which is
     * a thing this domain refuses to have. It replaced a clipboard that was
     * indistinguishable from the Pages document at the 1.5rem the sidebar
     * draws an icon at, which is the whole of what a User sees in the
     * collapsed rail.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?string $recordTitleAttribute = 'order_number';

    /** Second, under the Dashboard. See `Pages\Dashboard` for the whole order. */
    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return OrderForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return OrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    /**
     * How many orders are still waiting on a confirmation call — the one number
     * that says whether anyone needs to do anything today.
     *
     * Counted in one place because it is drawn in two: here in the sidebar and
     * again on the queue's New tab, about two hundred pixels apart. They are
     * the same number to an operator, so a second copy of this query would only
     * be a way for them to start disagreeing. Equality on `status`, so
     * `orders_status_created_at_index` serves it.
     *
     * Soft-deleted Orders are out of it. They are also out of every tab until
     * TrashedFilter says otherwise, which is the one case where the New tab
     * lists more rows than its own badge counts — a deleted Order is not work
     * anybody has to do, so the badge is right to keep ignoring it.
     */
    public static function newOrderCount(): int
    {
        return static::getModel()::query()->where('status', OrderStatus::New)->count();
    }

    public static function getNavigationBadge(): ?string
    {
        $waiting = static::newOrderCount();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
            'edit' => EditOrder::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
