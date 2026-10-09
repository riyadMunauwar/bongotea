<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Support\Settings\StoreSettings;
use App\Support\Storefront\Catalogue;
use App\Support\Storefront\CatalogueProps;
use App\Support\Storefront\Seo;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * A Product at its own address.
 *
 * A published Product is shown whether or not it is Available — greyed when
 * it is not, because a regular buyer looks for the tea they always buy. A
 * draft is a 404, like a slug nobody holds. One taken out of the catalogue
 * keeps its address, because links to it outlive it: it answers 410 with a
 * page saying it is no longer sold, and other teas to go on to.
 */
class ProductController extends Controller
{
    public function show(Request $request, StoreSettings $store, Catalogue $catalogue, string $slug): Response|HttpResponse
    {
        $product = Product::withTrashed()->published()->where('slug', $slug)->firstOrFail();

        if ($product->trashed()) {
            return Inertia::render('product-gone', [
                'product' => ['name' => $product->name],
                'others' => CatalogueProps::cards($catalogue->products()->take(3)),
                'seo' => Seo::forProduct($product, $store, $request)->toArray(),
            ])->toResponse($request)->setStatusCode(HttpResponse::HTTP_GONE);
        }

        $product->load(Catalogue::CARD_RELATIONS);

        return Inertia::render('product', [
            'product' => CatalogueProps::detail($product),
            'others' => CatalogueProps::cards($catalogue->othersBeside($product)),
            'seo' => Seo::forProduct($product, $store, $request)->toArray(),
        ]);
    }
}
