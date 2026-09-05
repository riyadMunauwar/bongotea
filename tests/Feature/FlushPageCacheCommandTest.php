<?php

use Illuminate\Filesystem\Filesystem;

it('empties a populated cache and reports what it removed', function () {
    $this->cache->put('promo', '<h1>Winter</h1>');
    $this->cache->put('promo-two', '<h1>Spring</h1>');

    $this->artisan('page-cache:flush')
        ->expectsOutputToContain('Removed 2 cached Pages.')
        ->assertSuccessful();

    expect($this->cache->has('promo'))->toBeFalse()
        ->and($this->cache->has('promo-two'))->toBeFalse();
});

it('removes the gzip siblings along with the html', function () {
    $this->cache->put('promo', '<h1>Winter</h1>');

    $this->artisan('page-cache:flush')->assertSuccessful();

    /**
     * A `.gz` outliving its `.html` is served as stale content with no error
     * anywhere, so the command has to clear the pair.
     */
    expect(glob($this->cacheDirectory.DIRECTORY_SEPARATOR.'*'))->toBe([]);
});

it('counts Pages rather than the files they are stored as', function () {
    $this->cache->put('promo', '<h1>Winter</h1>');

    $this->artisan('page-cache:flush')
        ->expectsOutputToContain('Removed 1 cached Page.')
        ->assertSuccessful();
});

it('succeeds against an already empty cache', function () {
    $this->artisan('page-cache:flush')
        ->expectsOutputToContain('Removed 0 cached Pages.')
        ->assertSuccessful();
});

it('succeeds when the cache directory was never created', function () {
    /**
     * A release can run before the first page was ever rendered, and on a
     * fresh checkout public/page-cache does not exist yet.
     */
    (new Filesystem)->deleteDirectory($this->cacheDirectory);

    $this->artisan('page-cache:flush')
        ->expectsOutputToContain('Removed 0 cached Pages.')
        ->assertSuccessful();
});
