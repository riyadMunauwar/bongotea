<?php

namespace App\Filament\Clusters\Inventory\Resources\Supplies;

use App\Filament\Clusters\Inventory\InventoryCluster;
use App\Filament\Clusters\Inventory\Resources\Supplies\Pages\CreateSupply;
use App\Filament\Clusters\Inventory\Resources\Supplies\Pages\ListSupplies;
use App\Filament\Clusters\Inventory\Resources\Supplies\Pages\ViewSupply;
use App\Filament\Clusters\Inventory\Resources\Supplies\Schemas\SupplyForm;
use App\Filament\Clusters\Inventory\Resources\Supplies\Schemas\SupplyInfolist;
use App\Filament\Clusters\Inventory\Resources\Supplies\Tables\SuppliesTable;
use App\Models\Supply;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * What has been bought, recorded as the document it came on.
 *
 * Three screens: the deliveries, a page to record one, and the document
 * itself. All of them are full pages rather than modals, because a delivery
 * is a supplier's invoice with a line per Product and a modal is a box to
 * type one thing into — an eight-line challan entered through one would be
 * entered badly.
 *
 * There is no edit page and no delete action, and the model refuses both as
 * well. A Supply is where cost enters the system (ADR-0022), and every figure
 * downstream of it was stamped from what it said: a Supply quietly corrected
 * afterwards would silently move a margin somebody has already read. A wrong
 * one is Voided, which writes the reversal and leaves both on the record.
 */
class SupplyResource extends Resource
{
    protected static ?string $model = Supply::class;

    /** Goods arriving: the tray a delivery lands in, against the lorry that takes parcels away. */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $cluster = InventoryCluster::class;

    /** Second inside the cluster, between counting a shelf and the Suppliers it points at. */
    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return SupplyForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SupplyInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SuppliesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupplies::route('/'),
            'create' => CreateSupply::route('/create'),
            'view' => ViewSupply::route('/{record}'),
        ];
    }

    /**
     * Nothing edits a delivery and nothing deletes one.
     *
     * Stated even though no edit route exists, because Filament asks these
     * before drawing an action rather than before serving a page: an
     * `EditAction` or a `DeleteBulkAction` dropped into the table by a later
     * hand would otherwise draw, and answer, on a record every stock figure
     * behind it was stamped from.
     */
    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
