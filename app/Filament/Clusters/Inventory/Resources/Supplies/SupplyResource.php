<?php

namespace App\Filament\Clusters\Inventory\Resources\Supplies;

use App\Filament\Clusters\Inventory\InventoryCluster;
use App\Filament\Clusters\Inventory\Resources\Supplies\Pages\CreateSupply;
use App\Filament\Clusters\Inventory\Resources\Supplies\Pages\ListSupplies;
use App\Filament\Clusters\Inventory\Resources\Supplies\Pages\ViewSupply;
use App\Filament\Clusters\Inventory\Resources\Supplies\Schemas\SupplyForm;
use App\Filament\Clusters\Inventory\Resources\Supplies\Schemas\SupplyInfolist;
use App\Filament\Clusters\Inventory\Resources\Supplies\Tables\SuppliesTable;
use App\Models\Supplier;
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

    /**
     * A merchant calls goods coming in a **Purchase**, so the sidebar, the list
     * title and the record read **Purchases** / **Purchase**. A Filament label
     * override and nothing more: the `Supply` model, the `supplies` table, the
     * routes and the `supplies` slug underneath are untouched (#172).
     *
     * This label is a distinct sense from the tracking Moment **Purchase** (a
     * sale reported to ad platforms, worth an Order's total). The glossary
     * records the two so they are never conflated — this one is stock bought
     * in, that one is a sale sent out.
     */
    protected static ?string $navigationLabel = 'Purchases';

    protected static ?string $modelLabel = 'Purchase';

    protected static ?string $pluralModelLabel = 'Purchases';

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

    /**
     * Gated on the cluster's `count stock` Permission like every screen in
     * here, and closing the resource's own URL too so a User without it gets a
     * forbidden response rather than a screen they were never meant to reach.
     * Voiding a delivery is held back further, behind `void supplies`, on the
     * action itself rather than the resource — a User who counts reaches these
     * screens; only some of them may strike a delivery out.
     */
    public static function canAccess(): bool
    {
        return InventoryCluster::canAccess();
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
     * One Supplier's own buying history: this list with their filter already
     * on.
     *
     * Built here, beside the filter it turns on, rather than spelled out at
     * each of the two screens that link to it — the Suppliers list and the
     * Buying report. A filter name Filament does not recognise is ignored
     * silently, so a hand-written address would land a User on the whole list
     * with nothing on screen saying the scope was lost.
     *
     * The query-string key is `filters`, which is what `ListRecords` declares
     * its table filters under, and both a `SelectFilter` and a `TernaryFilter`
     * hold their state under `value` — the ternary as the string `'false'`,
     * which is what Filament converts back to the scalar.
     *
     * **Two filters, because both figures that link here count two.** Every
     * figure a Merchant follows to this list is over the deliveries that
     * stand — the Suppliers list counts `notVoided()` and the Buying report
     * excludes a Voided delivery from everything — so opening a list that
     * included them would show four rows under a count of three, with nothing
     * on the screen saying why. Turn the Voided filter on with the Supplier
     * one, and the list a figure opens is the rows that figure was counted
     * from. Somebody looking for what went wrong flips it themselves, on the
     * screen, where they can see they have.
     */
    public static function deliveriesFromUrl(Supplier $supplier): string
    {
        return static::getUrl('index', [
            'filters' => [
                SuppliesTable::SUPPLIER_FILTER => ['value' => $supplier->getKey()],
                SuppliesTable::VOIDED_FILTER => ['value' => 'false'],
            ],
        ]);
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
