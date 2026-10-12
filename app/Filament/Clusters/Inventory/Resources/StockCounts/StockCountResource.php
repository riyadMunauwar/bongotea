<?php

namespace App\Filament\Clusters\Inventory\Resources\StockCounts;

use App\Filament\Clusters\Inventory\InventoryCluster;
use App\Filament\Clusters\Inventory\Resources\StockCounts\Pages\ListStockCounts;
use App\Filament\Clusters\Inventory\Resources\StockCounts\Tables\StockCountsTable;
use App\Models\StockCount;
use BackedEnum;
use Filament\Resources\Resource;
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
     * A merchant does not reach for "Stock Count" — they reach for a stock
     * adjustment. So the sidebar, the list title and the record read **Stock
     * Adjustments** / **Stock Adjustment**, a Filament label override and
     * nothing more: the model, the `stock_counts` table, the routes and the
     * `stock-counts` slug underneath are untouched (#172). The domain term is
     * still **Stock Count** — the glossary keeps it; only the merchant-facing
     * word changed.
     */
    protected static ?string $navigationLabel = 'Stock Adjustments';

    protected static ?string $modelLabel = 'Stock Adjustment';

    protected static ?string $pluralModelLabel = 'Stock Adjustments';

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

    public static function table(Table $table): Table
    {
        return StockCountsTable::configure($table);
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

    /**
     * The list alone: a count is taken in a dialog over it (`AdjustStockAction`),
     * never on a page of its own.
     */
    public static function getPages(): array
    {
        return [
            'index' => ListStockCounts::route('/'),
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
