<?php

use App\Enums\PageStatus;
use App\Models\Page;
use App\Support\PageCache\FallbackPageCacheReader;
use App\Support\Pages\FrontPage;

it('serves the placeholder at the site root when nothing is designated', function () {
    $response = $this->get('/');

    /**
     * Asserted on the bytes rather than with `assertViewIs`, because the
     * response deliberately is not a view response: the Placeholder is rendered
     * to a string so that what goes to disk and what the visitor receives are
     * the same object.
     */
    $response->assertOk()
        ->assertSee('No front page has been chosen', escape: false)
        ->assertSee('noindex', escape: false);
});

it('caches the placeholder, so the site root has one serving story', function () {
    /**
     * The Placeholder is cached like anything else. Without this, the one
     * address that is not a Slug would be the one address exempt from the
     * promise the whole cache exists to keep.
     */
    $response = $this->get('/');

    expect($this->cache->hasFront())->toBeTrue()
        ->and(file_get_contents($this->cache->frontHtmlPath()))->toBe($response->getContent());
});

it('serves the designated page at the site root', function () {
    $page = Page::factory()->published()->withBody('<html><head></head><body><h1>Winter Promo</h1></body></html>')->create(['slug' => 'promo']);

    makeFrontPage($page);

    $this->get('/')->assertOk()->assertSee('Winter Promo', escape: false);
});

it('keeps the front page reachable at its slug', function () {
    $page = Page::factory()->published()->create(['slug' => 'promo']);

    makeFrontPage($page);

    /**
     * A Slug is claimed for good and may be baked into ad creatives that
     * outlive the Page. Designating a Front Page is a decision about the
     * installation and retires nothing.
     */
    $this->get('/promo')->assertOk();
});

it('hands both addresses and the disk the same bytes from one render', function () {
    $page = Page::factory()->published()->withBody('<html><head></head><body><h1>Winter</h1></body></html>')->create(['slug' => 'promo']);

    makeFrontPage($page);

    $atTheRoot = $this->get('/');

    /**
     * Whichever address misses first renders once and writes both entries, so
     * `<slug>.html` and `_front.html` are one string written twice rather than
     * two renders that happen to agree. The fallback reader is the third
     * representation, for a Merchant whose webserver was never configured.
     */
    $served = (new FallbackPageCacheReader($this->cacheDirectory))->resolve('/', 'GET');

    expect(file_get_contents($this->cache->frontHtmlPath()))->toBe($atTheRoot->getContent())
        ->and(file_get_contents($this->cache->htmlPath('promo')))->toBe($atTheRoot->getContent())
        ->and(file_get_contents($served['path']))->toBe($atTheRoot->getContent());
});

it('warms the site root from a visitor who arrived at the slug', function () {
    $page = Page::factory()->published()->create(['slug' => 'promo']);

    makeFrontPage($page);

    $atTheSlug = $this->get('/promo');

    expect($this->cache->hasFront())->toBeTrue()
        ->and(file_get_contents($this->cache->frontHtmlPath()))->toBe($atTheSlug->getContent());
});

it('leaves the site root alone for a page that is not the front page', function () {
    Page::factory()->published()->create(['slug' => 'promo']);

    $this->get('/promo');

    expect($this->cache->hasFront())->toBeFalse();
});

it('marks a full render at the site root as a miss', function () {
    expect($this->get('/')->headers->get('X-GioPages-Cache'))->toBe('miss');
});

it('falls back to the placeholder when the front page is pulled to draft', function () {
    $page = Page::factory()->published()->create(['slug' => 'promo']);

    makeFrontPage($page);
    $this->get('/');

    $page->update(['status' => PageStatus::Draft]);

    /**
     * The stale-bytes failure is the sharpest bug this feature has: without the
     * invalidation the Merchant unpublishes, /promo correctly goes away, and /
     * goes on serving the unpublished Page to everyone indefinitely.
     */
    expect($this->cache->hasFront())->toBeFalse();

    $this->get('/')->assertOk()->assertSee('No front page has been chosen', escape: false);
});

it('falls back to the placeholder when the front page is binned', function () {
    $page = Page::factory()->published()->create();

    makeFrontPage($page);
    $this->get('/');

    $page->delete();

    expect($this->cache->hasFront())->toBeFalse();

    $this->get('/')->assertSee('No front page has been chosen', escape: false);
});

it('remembers the designation while the front page is a draft', function () {
    $page = Page::factory()->create(['slug' => 'promo']);

    makeFrontPage($page);

    $this->get('/')->assertSee('No front page has been chosen', escape: false);

    $page->update(['status' => PageStatus::Published]);

    /**
     * Publishing restores the Site Root with no second trip to Settings, which
     * is the whole reason the setting is kept rather than cleared.
     */
    expect(app(FrontPage::class)->designated()?->slug)->toBe('promo');

    $this->get('/')->assertOk()->assertSee(route('landing.show', 'promo'), escape: false);
});

it('forgets the site root when the front page is edited', function () {
    $page = Page::factory()->published()->create(['slug' => 'promo']);

    makeFrontPage($page);
    $this->get('/');

    $page->update(['body' => '<html><head></head><body><h1>Rewritten</h1></body></html>']);

    expect($this->cache->hasFront())->toBeFalse();

    $this->get('/')->assertSee('Rewritten', escape: false);
});

it('sets no cookies at the site root, so the bytes on disk are the bytes served', function () {
    /**
     * The root moved out of the `web` group for this: StartSession would ship a
     * Set-Cookie and an XSRF-TOKEN cookie, and the response would no longer be
     * byte-identical to what was written to disk.
     */
    expect($this->get('/')->headers->getCookies())->toBeEmpty();
});

it('looks for the site root entry before the rule that stops at a directory', function () {
    $htaccess = file_get_contents(base_path('public/.htaccess'));

    /**
     * The one failure ADR-0023 singles out as silent, pinned the way the front
     * controller's two lines are: by reading the configuration's own text,
     * because no test that enters through the kernel can observe a rewrite
     * rule.
     *
     * "/" resolves to the document root, which is a directory, so the -d stanza
     * matches it and stops rewriting — that is exactly why the root used to
     * fall through to index.php. Put the root lookup below it and the Front
     * Page silently stops being served from the cache, on a correctly
     * configured host, with every other test in this suite still green.
     */
    $rootLookup = strpos($htaccess, 'page-cache/_front.html -f');
    $stopsAtADirectory = strpos($htaccess, 'RewriteCond %{REQUEST_FILENAME} -d');

    expect($rootLookup)->not->toBeFalse()
        ->and($stopsAtADirectory)->not->toBeFalse()
        ->and($rootLookup)->toBeLessThan($stopsAtADirectory);
});

it('serves a newly designated front page rather than the placeholder it replaces', function () {
    $page = Page::factory()->published()->withBody('<html><head></head><body><h1>Winter</h1></body></html>')->create(['slug' => 'promo']);

    /** The Placeholder is on disk before the Merchant has chosen anything. */
    $this->get('/');
    expect($this->cache->hasFront())->toBeTrue();

    makeFrontPage($page);

    /**
     * Designating a Front Page has to replace those bytes, or the Merchant
     * chooses a page in Settings and their domain goes on advertising GioPages
     * forever. It is forgotten because saving any Settings group flushes every
     * cached Page — so this test is what stands between that flush being
     * narrowed one day and the Site Root freezing on the Placeholder.
     */
    expect($this->cache->hasFront())->toBeFalse();

    $this->get('/')->assertOk()->assertSee('Winter', escape: false);
});
