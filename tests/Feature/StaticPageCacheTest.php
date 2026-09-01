<?php

use App\Models\Page;
use App\Models\Product;

it('writes the html and a matching pre-compressed sibling', function () {
    $this->cache->put('promo', '<h1>Winter</h1>');

    $html = $this->cacheDirectory.DIRECTORY_SEPARATOR.'promo.html';
    $gzip = $html.'.gz';

    expect($html)->toBeFile()
        ->and($gzip)->toBeFile()
        ->and(file_get_contents($html))->toBe('<h1>Winter</h1>')
        ->and(gzdecode(file_get_contents($gzip)))->toBe('<h1>Winter</h1>');

    /**
     * nginx's gzip_static serves the .gz whatever its timestamp, so a drift
     * here is silent wrong content rather than an error anywhere.
     */
    expect(filemtime($gzip))->toBe(filemtime($html));
});

it('replaces both representations when a page is rewritten', function () {
    $this->cache->put('promo', '<h1>Version one</h1>');
    $this->cache->put('promo', '<h1>Version two</h1>');

    $html = $this->cacheDirectory.DIRECTORY_SEPARATOR.'promo.html';

    expect(file_get_contents($html))->toBe('<h1>Version two</h1>')
        ->and(gzdecode(file_get_contents($html.'.gz')))->toBe('<h1>Version two</h1>');
});

it('forgets the html and the gzip together', function () {
    $this->cache->put('promo', '<h1>Winter</h1>');

    $this->cache->forget('promo');

    $html = $this->cacheDirectory.DIRECTORY_SEPARATOR.'promo.html';

    expect(file_exists($html))->toBeFalse()
        ->and(file_exists($html.'.gz'))->toBeFalse();
});

it('stores the site root under a key no slug can claim', function () {
    $this->cache->put('', '<h1>Home</h1>');

    expect($this->cacheDirectory.DIRECTORY_SEPARATOR.'__root__.html')->toBeFile();
});

it('refuses slugs that could escape the cache directory', function (string $slug) {
    expect(fn () => $this->cache->put($slug, '<h1>Nope</h1>'))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'traversal' => '../../../etc/passwd',
    'nested' => 'blog/post',
    'backslash' => '..\windows',
    'uppercase' => 'Promo',
    'trailing hyphen' => 'promo-',
]);

it('invalidates only the saved page, leaving its neighbours cached', function () {
    $promo = Page::factory()->create(['slug' => 'promo']);
    Page::factory()->create(['slug' => 'other-page']);

    $this->cache->put('promo', '<h1>cached promo</h1>');
    $this->cache->put('other-page', '<h1>cached neighbour</h1>');

    $promo->update(['body' => '<h1>edited</h1>']);

    expect($this->cache->has('promo'))->toBeFalse()
        ->and($this->cache->has('other-page'))->toBeTrue();
});

it('invalidates the old url when a page changes slug', function () {
    $page = Page::factory()->create(['slug' => 'promo']);

    $this->cache->put('promo', '<h1>cached</h1>');

    $page->update(['slug' => 'promo-v2']);

    /**
     * Without this the old file survives with no model pointing at it, and
     * nginx serves the retired URL forever.
     */
    expect($this->cache->has('promo'))->toBeFalse();
});

it('invalidates when a page is deleted', function () {
    $page = Page::factory()->create(['slug' => 'promo']);

    $this->cache->put('promo', '<h1>cached</h1>');

    $page->delete();

    expect($this->cache->has('promo'))->toBeFalse();
});

it('invalidates every page a product is linked to when the product changes', function () {
    $product = Product::factory()->create(['price' => 99000]);

    $first = Page::factory()->create(['slug' => 'promo']);
    $second = Page::factory()->create(['slug' => 'promo-two']);
    $unrelated = Page::factory()->create(['slug' => 'unrelated']);

    $first->products()->attach($product);
    $second->products()->attach($product);

    $this->cache->put('promo', '<h1>cached</h1>');
    $this->cache->put('promo-two', '<h1>cached</h1>');
    $this->cache->put('unrelated', '<h1>cached</h1>');

    /**
     * The pivot is the dependency index. There are no cache tags on the file
     * or database drivers, so a price change has to name the URLs it kills.
     */
    $product->update(['price' => 129000]);

    expect($this->cache->has('promo'))->toBeFalse()
        ->and($this->cache->has('promo-two'))->toBeFalse()
        ->and($this->cache->has('unrelated'))->toBeTrue();
});

it('invalidates linked pages when a product is soft deleted', function () {
    $product = Product::factory()->create();
    $page = Page::factory()->create(['slug' => 'promo']);
    $page->products()->attach($product);

    $this->cache->put('promo', '<h1>cached with checkout</h1>');

    $product->delete();

    /**
     * The page keeps rendering with its checkout gone — which only happens if
     * the copy holding the old checkout is cleared.
     */
    expect($this->cache->has('promo'))->toBeFalse();
});

it('forgets an entry that was never written without raising a warning', function () {
    /**
     * Invalidation fires on every save, and most saves are of pages nothing
     * ever requested — so the absent entry is the common case, not the edge.
     * Laravel's error handler turns an unlink warning into an ErrorException,
     * which would surface as a failed save in the admin.
     */
    $raised = null;

    set_error_handler(function (int $level, string $message) use (&$raised): bool {
        $raised = $message;

        return true;
    });

    try {
        $this->cache->forget('never-cached');
    } finally {
        restore_error_handler();
    }

    expect($raised)->toBeNull()
        ->and($this->cache->has('never-cached'))->toBeFalse();
});
