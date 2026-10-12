<?php

namespace App\Filament\Resources\Customers;

use App\Enums\Permission;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\Customers\Schemas\CustomerInfolist;
use App\Filament\Resources\Customers\Tables\CustomersTable;
use App\Models\Customer;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The people who ordered, each known by a phone number and read through the
 * Orders they placed: who they said they were, where the tea went, what they
 * bought, and every Order of theirs below.
 *
 * Read-only on purpose: no create page and no edit page. A Customer exists
 * because an Order named their number, and the number is the whole of them —
 * a different number is a different Customer, not an edit. Nothing here can
 * delete one either, since every Order they placed points at them.
 */
class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $recordTitleAttribute = 'phone';

    /**
     * Inside Orders, after Shipments: the people the queue is worked for,
     * looked up while one of them is on the phone.
     */
    protected static ?string $navigationParentItem = 'Orders';

    protected static ?int $navigationSort = 1;

    /**
     * Behind the same floor as the Orders they are read through: `work
     * orders` (ADR-0025). The people calling Customers back are the ones who
     * need to know whether a number has ordered before.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::WorkOrders) ?? false;
    }

    /**
     * Every Customer with their Orders' figures loaded beside them, so the
     * list and the Customer's own page read the same figures.
     *
     * @return Builder<Customer>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withOrderFigures();
    }

    public static function infolist(Schema $schema): Schema
    {
        return CustomerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            OrdersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'view' => ViewCustomer::route('/{record}'),
        ];
    }
}
