<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Support\Settings\StoreSettings;
use App\Support\Storefront\Catalogue;
use App\Support\Storefront\CatalogueProps;
use App\Support\Storefront\Seo;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A Category at its own address: its description, its "why" points and
 * its published Products, drawn in its Category Theme.
 */
class CategoryController extends Controller
{
    public function show(Request $request, StoreSettings $store, Catalogue $catalogue, string $slug): Response
    {
        $category = Category::query()->where('slug', $slug)->firstOrFail();
        $products = $catalogue->productsIn($category);

        return Inertia::render('category', [
            'category' => CatalogueProps::category($category, $products),
            'products' => CatalogueProps::cards($products),
            'seo' => Seo::forCategory($category, $store, $request)->toArray(),
        ]);
    }
}
