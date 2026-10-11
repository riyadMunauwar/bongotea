<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tag;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Search, as the page and as the header's live dropdown: a buyer's words
 * matched against everything a Product is known by, whatever the case.
 */
it('finds Products by name, short description, Category, Brand, Tag and search keywords, ignoring case', function (string $query) {
    $category = Category::factory()->create(['name' => 'প্রিমিয়াম']);
    $brand = Brand::factory()->create(['name' => 'Bongotea']);
    $tag = Tag::factory()->create(['name' => 'দুধ চায়ের জন্য']);

    $match = Product::factory()->for($category)->for($brand)->hasAttached($tag)->create([
        'name' => 'Royal Leaf',
        'short_description' => 'গাঢ় লিকার, তীব্র ঘ্রাণ',
        'search_keywords' => 'primium,royel',
    ]);
    Product::factory()->create(['name' => 'Other', 'short_description' => 'হালকা']);

    $this->get('/search?q='.urlencode($query))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('search')
            ->where('query', $query)
            ->has('products', 1)
            ->where('products.0.id', $match->id)
        );
})->with([
    'name, in another case' => ['royal LEAF'],
    'short description' => ['তীব্র ঘ্রাণ'],
    'Category' => ['প্রিমিয়াম'],
    'Brand, in another case' => ['BONGOTEA'],
    'Tag, as two words' => ['দুধ চা'],
    'search keyword' => ['Primium'],
]);

it('requires every word to match somewhere', function () {
    Product::factory()->create(['name' => 'Royal Leaf', 'short_description' => 'strong']);
    Product::factory()->create(['name' => 'Royal Dust', 'short_description' => 'mild']);

    $this->get('/search?q=royal+strong')
        ->assertInertia(fn (Assert $page) => $page->has('products', 1)->where('products.0.name', 'Royal Leaf'));
});

it('ignores LIKE wildcards typed into the query rather than letting them match everything', function () {
    Product::factory()->create(['name' => 'Royal Leaf']);

    $this->get('/search?q=%25')->assertInertia(fn (Assert $page) => $page->where('products', []));
    $this->get('/search?q=_')->assertInertia(fn (Assert $page) => $page->where('products', []));
});

it('finds no draft and no Product taken out of the catalogue, but shows an unavailable one', function () {
    Product::factory()->draft()->create(['name' => 'Royal Draft']);
    Product::factory()->create(['name' => 'Royal Gone'])->delete();
    $resting = Product::factory()->unavailable()->create(['name' => 'Royal Resting']);

    $this->get('/search?q=royal')
        ->assertInertia(fn (Assert $page) => $page
            ->has('products', 1)
            ->where('products.0.id', $resting->id)
            ->where('products.0.isAvailable', false)
        );
});

it('shows no products for an empty query, and none for one that finds nothing', function () {
    Product::factory()->create(['name' => 'Royal Leaf']);

    $this->get('/search')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('query', '')->where('products', []));

    $this->get('/search?q=coffee')
        ->assertInertia(fn (Assert $page) => $page
            ->where('query', 'coffee')
            ->where('products', [])
            ->where('store.hotline.number', '01700000000')
        );
});

it('suggests up to five matches for the live dropdown', function () {
    $category = Category::factory()->create(['name' => 'গোল্ড']);
    Product::factory()->for($category)->create(['name' => 'Gold Leaf', 'slug' => 'gold-leaf', 'price' => 55000]);
    Product::factory()->count(5)->create(['name' => 'Gold Dust']);

    $this->getJson('/search/suggestions?q=gold')
        ->assertOk()
        ->assertJsonCount(5, 'products')
        ->assertJsonPath('products.0', [
            'name' => 'Gold Leaf',
            'href' => '/tea/gold-leaf',
            'imageUrl' => null,
            'categoryName' => 'গোল্ড',
            'firstTierPrice' => 55000,
        ]);
});

it('suggests nothing for a blank query', function () {
    Product::factory()->create();

    $this->getJson('/search/suggestions?q=%20')->assertOk()->assertExactJson(['products' => []]);
});
