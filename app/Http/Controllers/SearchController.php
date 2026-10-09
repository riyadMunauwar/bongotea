<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Support\Storefront\CatalogueProps;
use App\Support\Storefront\ProductSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Search: every match on a page of its own, and up to five as the header's
 * dropdown fills while the buyer types. A search that finds nothing is
 * still a page — it shows the hotline.
 */
class SearchController extends Controller
{
    public function index(Request $request, ProductSearch $search): Response
    {
        $query = trim($request->string('q')->toString());

        return Inertia::render('search', [
            'query' => $query,
            'products' => CatalogueProps::cards($search->matching($query)),
        ]);
    }

    public function suggestions(Request $request, ProductSearch $search): JsonResponse
    {
        $matches = $search->matching($request->string('q')->toString(), ProductSearch::SUGGESTIONS);

        return response()->json([
            'products' => $matches->map(fn (Product $product): array => CatalogueProps::suggestion($product))->all(),
        ]);
    }
}
