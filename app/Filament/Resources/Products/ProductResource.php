<?php

namespace App\Filament\Resources\Products;

use App\Enums\Permission;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Pages\ProductStockHistory;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Filament\Resources\Products\Tables\ProductsTable;
use App\Models\Product;
use BackedEnum;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    /**
     * A price tag: a Product carries a single price and no variations, which
     * is the whole of what separates one from anything else in the panel. The
     * bag it used to wear went to Orders, where a completed purchase is what
     * a bag actually means.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    /** Third, under Orders and the Shipments and Customers inside it. See `Pages\Dashboard` for the whole order. */
    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
    }

    /**
     * The catalogue is one working area held under one Permission
     * (`Permission::ManageCatalogue`), and this is the whole of its gate. It
     * closes every page the resource owns at once — the list, the editor and
     * the Stock History — because each resource Page aborts unless
     * `canAccess()` passes, and it drops the Products entry from the sidebar
     * for a User who lacks it rather than leaving a link that only 403s.
     * Categories, Brands and Tags ask this same question, so the four open
     * and close together.
     *
     * There is no gate finer than this on purpose (ADR-0026): taking a
     * Product out of the catalogue and putting it back ride on the same
     * Permission, with no policy and no row-level verb, because the resource
     * is one unit of trust and a Permission names a working area, never a
     * verb on a row.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::ManageCatalogue) ?? false;
    }

    /**
     * Tabs across the top of the record rather than a strip down its side,
     * for the reason `ReportsCluster` gives: Filament's default stacks a
     * second column of navigation inside the page, on top of the panel's own
     * 14rem sidebar, and the stock history is a wide table that would rather
     * have the width than the wayfinding.
     */
    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    /**
     * A Product's own screens: what it is, and what has happened to its
     * stock. The history is a page of the Product rather than a screen in
     * Inventory because it is asked about a Product somebody is already
     * looking at.
     *
     * @return array<NavigationItem | NavigationGroup>
     */
    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([
            EditProduct::class,
            ProductStockHistory::class,
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
            'stock' => ProductStockHistory::route('/{record}/stock'),
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
