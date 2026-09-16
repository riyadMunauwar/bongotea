<?php

namespace App\Filament\Clusters\Inventory\Resources\Suppliers;

use App\Filament\Clusters\Inventory\InventoryCluster;
use App\Filament\Clusters\Inventory\Resources\Suppliers\Pages\CreateSupplier;
use App\Filament\Clusters\Inventory\Resources\Suppliers\Pages\EditSupplier;
use App\Filament\Clusters\Inventory\Resources\Suppliers\Pages\ListSuppliers;
use App\Filament\Clusters\Inventory\Resources\Suppliers\Schemas\SupplierForm;
use App\Filament\Clusters\Inventory\Resources\Suppliers\Tables\SuppliersTable;
use App\Models\Supplier;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Who the Merchant buys from, recorded once and pointed at.
 *
 * The whole worth of this screen is that a Supply names a record rather than
 * a spelling: buying history groups by the Supplier it was bought from, and
 * two ways of writing the same name would be two histories that never meet.
 *
 * Editable, unlike everything else in Inventory. A name spelled wrong or a
 * number that has changed is a correction to a reference, not a rewrite of
 * something that happened — what happened is the Supply, and that is
 * immutable. Deletion is soft, so a Supplier taken off the list keeps naming
 * every delivery they ever made.
 */
class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    /** A shopfront: the place goods come from, facing the tag that means a Product and the lorry that means a parcel. */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $cluster = InventoryCluster::class;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Last inside the cluster: Stock Counts 0, Supplies 1, Suppliers 2. A
     * Supplier is a reference the other two screens point at rather than
     * something a User comes here to do, so it sits after the work.
     */
    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return SupplierForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SuppliersTable::configure($table);
    }

    /**
     * Gated on the cluster's `count stock` Permission like every screen in
     * here, and closing the resource's own URL too so a User without it gets a
     * forbidden response rather than a screen they were never meant to reach.
     */
    public static function canAccess(): bool
    {
        return InventoryCluster::canAccess();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSuppliers::route('/'),
            'create' => CreateSupplier::route('/create'),
            'edit' => EditSupplier::route('/{record}/edit'),
        ];
    }
}
