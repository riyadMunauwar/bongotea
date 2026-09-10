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
     */
    public static function getNavigationBadge(): ?string
    {
        $waiting = static::getModel()::query()->where('status', OrderStatus::New)->count();

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
