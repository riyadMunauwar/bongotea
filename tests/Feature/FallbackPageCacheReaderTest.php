<?php

use App\Support\PageCache\FallbackPageCacheReader;

/**
 * The reader is driven directly rather than over HTTP: it runs before the
 * Composer autoloader, so the framework's test client — which enters through
 * the kernel — can never reach it.
 *
 * It is pointed at the same scratch directory the suite already builds for
 * `StaticPageCache`. public/page-cache is served publish-blind, so a file left
 * there by the suite would be real content on a real site.
 */
beforeEach(function (): void {
    $this->reader = new FallbackPageCacheReader($this->cacheDirectory);

    /**
     * A file the writer never put in the cache, sitting where a traversal that
     * escaped the directory would land.
     */
    $this->fileOutsideTheCache = dirname($this->cacheDirectory).DIRECTORY_SEPARATOR.'escaped.html';

    file_put_contents($this->fileOutsideTheCache, '<h1>Not yours</h1>');
});

afterEach(function (): void {
    if (file_exists($this->fileOutsideTheCache)) {
        unlink($this->fileOutsideTheCache);
    }
});

it('resolves a cached slug to the file the writer left there', function () {
    $this->cache->put('promo', '<h1>Winter</h1>');

    expect($this->reader->resolve('/promo', 'GET')['path'])
        ->toBe(realpath($this->cache->htmlPath('promo')));
});

it('tells the client the page came from the fallback', function () {
    $this->cache->put('promo', '<h1>Winter</h1>');

    /**
     * One header names the three serving paths and the fast path sets none of
     * them, because Apache cannot reliably stamp a header onto a static file.
     * Asserted as the wire format rather than through the constants: this
     * string is what the panel's indicator reads, and what a merchant sees in
     * their own browser.
     */
    expect($this->reader->resolve('/promo', 'GET')['headers'])->toBe([
        'X-GioPages-Cache: fallback',
        'Content-Type: text/html; charset=UTF-8',
        'Content-Length: 15',
        'Vary: Accept-Encoding',
    ]);
});

it('resolves a slug with nothing cached to nothing', function () {
    $this->cache->put('promo', '<h1>Winter</h1>');

    expect($this->reader->resolve('/other-page', 'GET'))->toBeNull();
});

it('resolves to nothing when nothing has ever been cached', function () {
    expect($this->reader->resolve('/promo', 'GET'))->toBeNull();
});

it('resolves a slug carrying a trailing slash', function () {
    $this->cache->put('promo', '<h1>Winter</h1>');

    /**
     * The fast path canonicalises the trailing slash away before its own
     * lookup, so the fallback has to answer at the same addresses or a
     * merchant's links would work on one host and 404 on another.
     */
    expect($this->reader->resolve('/promo/', 'GET')['path'])
        ->toBe(realpath($this->cache->htmlPath('promo')));
});

it('ignores the query string every ad click arrives with', function () {
    $this->cache->put('promo', '<h1>Winter</h1>');

    /**
     * The cache key is the slug and nothing else. A query-sensitive lookup
     * would miss on `?fbclid=...` — which is the only traffic the cache exists
     * for.
     */
    expect($this->reader->resolve('/promo?fbclid=IwAR0abc', 'GET')['path'])
        ->toBe(realpath($this->cache->htmlPath('promo')));
});

it('refuses a method a static file cannot honestly answer', function (string $method) {
    $this->cache->put('checkout', '<h1>Checkout</h1>');

    /**
     * A checkout submission must reach the application. Answering POST
     * /checkout from disk would swallow the order silently.
     */
    expect($this->reader->resolve('/checkout', $method))->toBeNull();
})->with(['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']);

it('answers a HEAD request from the cache like a GET', function () {
    $this->cache->put('promo', '<h1>Winter</h1>');

    expect($this->reader->resolve('/promo', 'HEAD')['path'])
        ->toBe(realpath($this->cache->htmlPath('promo')));
});

it('sends a body to a GET and none to a HEAD', function () {
    $this->cache->put('promo', '<h1>Winter</h1>');

    /**
     * A webserver strips the body from a HEAD response; PHP does not. Both
     * still report the length the GET would have returned.
     */
    expect($this->reader->resolve('/promo', 'GET')['sendsBody'])->toBeTrue()
        ->and($this->reader->resolve('/promo', 'HEAD')['sendsBody'])->toBeFalse()
        ->and($this->reader->resolve('/promo', 'HEAD')['headers'])->toContain('Content-Length: 15');
});

it('resolves a percent-encoded traversal attempt to nothing', function (string $uri) {
    $this->cache->put('promo', '<h1>Winter</h1>');

    /**
     * The URI is deliberately never decoded: a legal slug is lowercase
     * alphanumeric and hyphens and never needs it, so the escapes survive into
     * a filename that cannot exist.
     */
    expect($this->reader->resolve($uri, 'GET'))->toBeNull();
})->with([
    'encoded dot segments' => '/%2e%2e%2fescaped',
    'encoded slash' => '/..%2fescaped',
    'double encoded' => '/%252e%252e%252fescaped',
]);

it('fails containment for a path that would escape the cache directory', function () {
    $this->cache->put('promo', '<h1>Winter</h1>');

    /**
     * The second lock. Nothing outside the cache directory is served, whatever
     * path arithmetic reached it.
     */
    expect($this->reader->resolve('/../escaped', 'GET'))->toBeNull();
});

it('serves the pre-compressed representation to a client that accepts it', function () {
    $this->cache->put('promo', '<h1>Winter</h1>');

    $resolution = $this->reader->resolve('/promo', 'GET', 'gzip, deflate, br');

    expect($resolution['path'])->toBe(realpath($this->cache->gzipPath('promo')))
        ->and($resolution['headers'])->toContain('Content-Encoding: gzip');
});

it('serves the plain representation to a client that does not accept gzip', function () {
    $this->cache->put('promo', '<h1>Winter</h1>');

    $resolution = $this->reader->resolve('/promo', 'GET', 'br');

    expect($resolution['path'])->toBe(realpath($this->cache->htmlPath('promo')))
        ->and($resolution['headers'])->not->toContain('Content-Encoding: gzip');
});

it('serves the plain representation to a client that refuses gzip outright', function () {
    $this->cache->put('promo', '<h1>Winter</h1>');

    /**
     * `q=0` is a refusal, not a preference. Reading it as acceptance hands
     * gzipped bytes to a client that has said it will not decode them, and it
     * renders nothing at all.
     */
    $resolution = $this->reader->resolve('/promo', 'GET', 'gzip;q=0, deflate');

    expect($resolution['path'])->toBe(realpath($this->cache->htmlPath('promo')))
        ->and($resolution['headers'])->not->toContain('Content-Encoding: gzip');
});

it('serves the pre-compressed representation to a client that merely prefers something else', function () {
    $this->cache->put('promo', '<h1>Winter</h1>');

    $resolution = $this->reader->resolve('/promo', 'GET', 'br;q=1.0, gzip;q=0.8');

    expect($resolution['path'])->toBe(realpath($this->cache->gzipPath('promo')))
        ->and($resolution['headers'])->toContain('Content-Encoding: gzip');
});

it('serves the plain representation when the compressed sibling is missing', function () {
    $this->cache->put('promo', '<h1>Winter</h1>');

    unlink($this->cache->gzipPath('promo'));

    /**
     * The writer pairs the two, so this should not happen — but a client that
     * accepts gzip must not be handed a 404 because someone reached into the
     * directory.
     */
    $resolution = $this->reader->resolve('/promo', 'GET', 'gzip');

    expect($resolution['path'])->toBe(realpath($this->cache->htmlPath('promo')))
        ->and($resolution['headers'])->not->toContain('Content-Encoding: gzip');
});

it('is consulted before the framework is loaded', function () {
    $frontController = file_get_contents(base_path('public/index.php'));

    /**
     * The whole value of this reader is that it answers without booting the
     * framework, and the only thing that makes that true is running above the
     * autoloader. No test that enters through the kernel can observe it, and a
     * framework upgrade rewrites this file — so the ordering is pinned on the
     * text of the front controller itself.
     */
    $autoloader = strpos($frontController, 'vendor/autoload.php');

    /**
     * Presence is asserted for both lines before either position is, because
     * `strpos` answers a missing needle with `false` and PHP reads `false` as
     * lower than any offset — so a position check alone passes for a line that
     * an upgrade deleted outright, which is the failure being guarded against.
     */
    expect($frontController)->toContain("require_once __DIR__.'/../app/Support/PageCache/FallbackPageCacheReader.php';")
        ->toContain('->serve($_SERVER)')
        ->and(strpos($frontController, 'FallbackPageCacheReader.php'))->toBeLessThan($autoloader)
        ->and(strpos($frontController, '->serve($_SERVER)'))->toBeLessThan($autoloader);
});

it('answers the site root from the front entry', function () {
    $this->cache->putFront('<h1>Front</h1>');

    /**
     * `/` has no Slug to be keyed on, so the reader resolves it to the one
     * constant the writer and every webserver configuration also spell
     * (ADR-0023). This is what reaches a Merchant whose webserver was never
     * configured — the population the second reader exists for — and without
     * it their single most valuable address would be the only one on the site
     * paying a full framework boot.
     */
    $hit = $this->reader->resolve('/', 'GET');

    expect($hit)->not->toBeNull()
        ->and(file_get_contents($hit['path']))->toBe('<h1>Front</h1>')
        ->and($hit['headers'])->toContain('X-GioPages-Cache: fallback');
});

it('answers the site root with a query string attached', function () {
    $this->cache->putFront('<h1>Front</h1>');

    /**
     * Every ad click arrives with a tracking parameter, and the root is no
     * different from a Slug in that respect.
     */
    expect($this->reader->resolve('/?fbclid=abc123', 'GET'))->not->toBeNull();
});

it('lets the framework boot at the site root when no front entry was written', function () {
    /**
     * Absence is the whole signal. No file means no Front Page has been
     * rendered yet, so the request goes through to the application, which
     * decides between the Front Page and the Placeholder and caches whichever
     * it drew.
     */
    expect($this->reader->resolve('/', 'GET'))->toBeNull();
});

it('serves the front entry pre-compressed to a client that accepts gzip', function () {
    $this->cache->putFront('<h1>Front</h1>');

    $hit = $this->reader->resolve('/', 'GET', 'gzip, deflate');

    expect(gzdecode(file_get_contents($hit['path'])))->toBe('<h1>Front</h1>')
        ->and($hit['headers'])->toContain('Content-Encoding: gzip');
});

it('refuses to answer the site root for a method a static file cannot honour', function () {
    $this->cache->putFront('<h1>Front</h1>');

    expect($this->reader->resolve('/', 'POST'))->toBeNull();
});

it('sends no body for a HEAD of the site root', function () {
    $this->cache->putFront('<h1>Front</h1>');

    expect($this->reader->resolve('/', 'HEAD')['sendsBody'])->toBeFalse();
});
