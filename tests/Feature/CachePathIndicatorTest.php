<?php

use App\Filament\Widgets\CachePathIndicator;
use App\Models\Page;
use App\Models\User;
use App\Support\PageCache\FallbackPageCacheReader;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

/*
| The check itself runs in the User's browser and is deliberately not
| automated: what it measures is the webserver in front of a real
| installation, which is the one thing CI does not have. What is asserted here
| is everything the panel puts in front of that check — that the widget is on
| the Dashboard, that it asks about a published Page at a same-origin address,
| and that all three paths are named with remediation for the two that are
| slow.
*/

it('puts the indicator on the Dashboard', function () {
    Page::factory()->published()->create();

    $this->get('/admin')
        ->assertOk()
        ->assertSeeLivewire(CachePathIndicator::class)
        ->assertSee('Page cache')
        ->assertSee('Check again');
});

it('names all three serving paths', function () {
    Page::factory()->published()->create();

    $this->get('/admin')
        ->assertOk()
        ->assertSee('Served by the webserver')
        ->assertSee('Served by the fallback reader')
        ->assertSee('Not cached at all');
});

it('tells the User what to change on the two paths that are not the fast one', function () {
    Page::factory()->published()->create();

    /**
     * A diagnosis a merchant cannot act on is the same silence the widget
     * exists to break, so each slow path names the thing to go and look at:
     * where the webserver is pointed for the fallback, and where the cache is
     * written for a full render.
     */
    $this->get('/admin')
        ->assertOk()
        ->assertSee('document root')
        ->assertSee('public/page-cache');
});

it('reads the fast path from the absence of a marker rather than one it sets', function () {
    Page::factory()->published()->create();

    /**
     * The whole scheme rests on this inversion: the webserver path sets no
     * header, because Apache cannot reliably stamp one onto a static file, so
     * silence is the healthy answer and the other two announce themselves.
     * The rendered check is where that has to be pinned — the fetch is not
     * automated, and a widget that read it the other way round would call
     * every fast installation broken.
     */
    $this->get('/admin')
        ->assertOk()
        ->assertSeeInOrder([
            FallbackPageCacheReader::HEADER,
            'if (marker === null)',
            "return 'webserver'",
        ], escape: false);
});

it('asks about a published Page rather than a draft', function () {
    Page::factory()->create(['slug' => 'draft-page']);
    Page::factory()->published()->create(['slug' => 'live-page']);

    $this->get('/admin')
        ->assertOk()
        ->assertSee('/live-page')
        ->assertDontSee('/draft-page');
});

it('passes over a published Page another route answers first', function () {
    /**
     * `/up` is the framework's health endpoint, registered ahead of the landing
     * catch-all, so a Page claiming that slug is never reachable at it. Asking
     * there would get a 200 carrying no marker, which the widget reads as the
     * fast path — a false green, and the one answer worse than having no widget
     * at all. That the Page itself is unreachable is a separate problem and not
     * this widget's to solve; reporting on it is.
     */
    $shadowed = Page::factory()->published()->create(['slug' => 'up']);

    Page::factory()->published()->create(['slug' => 'reachable-page']);

    $shadowed->updated_at = now()->subWeek();
    $shadowed->saveQuietly();

    $this->get('/admin')
        ->assertOk()
        ->assertSee('Checking /reachable-page')
        ->assertDontSee('Checking /up');
});

it('asks about the published Page saved longest ago', function () {
    $settled = Page::factory()->published()->create(['slug' => 'settled-page']);

    Page::factory()->published()->create(['slug' => 'saved-a-moment-ago']);

    $settled->updated_at = now()->subWeek();
    $settled->saveQuietly();

    /**
     * Any published Page answers the question, because the question is about
     * the installation. The one to avoid is the Page just saved: PageObserver
     * has removed its cached copy, so it would report a miss that says nothing
     * about the webserver.
     */
    $this->get('/admin')
        ->assertOk()
        ->assertSee('/settled-page')
        ->assertDontSee('/saved-a-moment-ago');
});

it('asks at a same-origin path rather than an absolute URL', function () {
    Page::factory()->published()->create(['slug' => 'winter-promo']);

    /**
     * A browser only lets a page read a response header when the request was
     * same-origin, and an absolute URL is same-origin only while the host it
     * names agrees with the host the User is on. Asking for a path makes it
     * true by construction — which is what this stops anyone helpfully
     * swapping back to `route()`.
     */
    $this->get('/admin')
        ->assertOk()
        ->assertSee('Checking /winter-promo')
        ->assertDontSee(url('/winter-promo'));
});

it('says there is nothing to check when no Page is published', function () {
    Page::factory()->create(['slug' => 'draft-page']);

    $this->get('/admin')
        ->assertOk()
        ->assertSee('Nothing published to check')
        ->assertDontSee('Check again');
});
