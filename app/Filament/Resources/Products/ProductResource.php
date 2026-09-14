<?php

namespace App\Filament\Resources\Products;

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

    /** Fourth, under Shipments. See `Pages\Dashboard` for the whole order. */
    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
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
