<?php

namespace App\Filament\Clusters\Inventory\Resources\StockCounts;

use App\Filament\Clusters\Inventory\InventoryCluster;
use App\Filament\Clusters\Inventory\Resources\StockCounts\Pages\CreateStockCount;
use App\Filament\Clusters\Inventory\Resources\StockCounts\Pages\ListStockCounts;
use App\Filament\Clusters\Inventory\Resources\StockCounts\Schemas\StockCountForm;
use App\Filament\Clusters\Inventory\Resources\StockCounts\Tables\StockCountsTable;
use App\Models\StockCount;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Where a User writes down what they counted, and the only way anything gets
 * into the ledger today.
 *
 * Two screens and no third: a list of what has been counted, and a form to
 * count something. There is no edit page and no delete action, because a
 * Stock Count is a thing that happened — a wrong one is answered by counting
 * again, which is what somebody would physically do at the shelf anyway, and
 * the second count is measured against the ledger the first one left.
 *
 * That immutability is stated three times on purpose: here, so the panel
 * offers nothing; on `StockCount`, so nothing else in the application can do
 * it either; and in the routes, which simply do not exist.
 */
class StockCountResource extends Resource
{
    protected static ?string $model = StockCount::class;

    /**
     * A clipboard with a list on it: somebody walking the shelves writing
     * numbers down, which is exactly what this records. Inventory carries the
     * sidebar entry and its own glyph; this is the tab inside it.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $cluster = InventoryCluster::class;

    /**
     * First inside the cluster, which numbers separately from the top-level
     * sidebar — the same way the Settings and Reports pages do.
     */
    protected static ?int $navigationSort = 0;

    public static function form(Schema $schema): Schema
    {
        return StockCountForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StockCountsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockCounts::route('/'),
            'create' => CreateStockCount::route('/create'),
        ];
    }

    /**
     * Nothing edits a count and nothing deletes one.
     *
     * Stated even though no edit route exists, because Filament asks these
     * before drawing an action rather than before serving a page: an
     * `EditAction` or a `DeleteBulkAction` dropped into the table by a later
     * hand would otherwise draw, and answer, on a record that must never
     * change.
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
