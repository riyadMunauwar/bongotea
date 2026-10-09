<?php

namespace App\Filament\Resources\Orders;

use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Filament\Resources\Orders\Pages\CreateOrder;
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
 * An Order comes into existence at Capture: when a visitor submits the
 * Checkout, or when a User saves an Order taken by phone on the create page,
 * which prices it through the pricing module (`Repricing`).
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

    /**
     * The fulfilment surface is the working area of whoever holds `work orders`
     * (ADR-0025): a User without it is refused the queue, an Order and the
     * sidebar entry alike, rather than shown a screen they cannot use. Closing
     * the resource's own URL too gives a forbidden response rather than a
     * redirect to nowhere.
     *
     * The everyday sending verbs — send with a courier, send a batch, check
     * with the gateway — sit inside this floor and need nothing finer; the acts
     * somebody would regret carry their own Permission, gated on the Action.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::WorkOrders) ?? false;
    }

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

    /**
     * How many Orders are still waiting to be handed to a Courier, and the
     * address of the queue holding exactly those.
     *
     * Counted in one place because it is drawn in one and clicked into
     * another: the Dashboard tile and the queue it opens are the same
     * question asked twice, and a count that disagrees with the list it leads
     * to is worse than no count at all. The rule itself is neither restated
     * here nor there — `Order::notYetSent()` is the scope the filter runs, so
     * both ends answer it by asking the model.
     *
     * Soft-deleted Orders are out of both: the scope is on the model and the
     * queue only lifts it when a User asks for the trash.
     *
     * It costs more than `newOrderCount()` does and cannot be made to cost
     * less: the scope is a `whereDoesntHave`, so the Orders are walked and
     * each one asks the Shipments index whether anything of its own is out or
     * arrived. That is one correlated subquery per Dashboard render, not per
     * row drawn anywhere, and the widget is lazy — but a figure counted this
     * way does not belong on a screen that draws it per record.
     */
    public static function notYetSentCount(): int
    {
        return static::getModel()::query()->notYetSent()->count();
    }

    /**
     * The queue with the not-yet-sent filter already on and the tab set to
     * All, which is the whole of what makes the count one click to act on.
     *
     * Both halves are load-bearing. `ListOrders` opens on New
     * (`getDefaultActiveTab()`), so an address that named only the filter
     * would land a User on the New tab and show them a subset of what they
     * clicked — an unsent Order somebody confirmed yesterday would be missing
     * with nothing on screen saying so. And the toggle is written as the
     * string `'true'` rather than a boolean, because Laravel renders `true`
     * into the query string as `1`: Filament reads `'true'` back as a boolean
     * (`normalizeTableFilterValuesFromQueryString()`) and leaves `'1'` alone,
     * which fills the Toggle with something that is not a boolean.
     */
    public static function notYetSentUrl(): string
    {
        return static::getUrl('index', [
            'tab' => 'all',
            'filters' => [OrdersTable::NOT_YET_SENT_FILTER => ['isActive' => 'true']],
        ]);
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
            'create' => CreateOrder::route('/create'),
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
