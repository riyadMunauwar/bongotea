<?php

use App\Models\Page;
use App\Support\Pages\FrontPage;
use App\Support\Settings\GeneralSettings;

it('answers with nothing when no page has been designated', function () {
    Page::factory()->published()->create();

    expect(app(FrontPage::class)->designated())->toBeNull();
});

it('answers with the designated page', function () {
    $page = Page::factory()->published()->create(['slug' => 'promo']);

    makeFrontPage($page);

    expect(app(FrontPage::class)->designated()?->slug)->toBe('promo');
});

it('answers with nothing while the designated page is a draft', function () {
    $page = Page::factory()->create(['slug' => 'promo']);

    makeFrontPage($page);

    /**
     * The Site Root serves the Placeholder until it is published again. The
     * setting is remembered either way, which is what lets publishing restore
     * the Site Root with no second trip to Settings.
     */
    expect(app(FrontPage::class)->designated())->toBeNull();
});

it('answers with nothing while the designated page is in the bin', function () {
    $page = Page::factory()->published()->create();

    makeFrontPage($page);
    $page->delete();

    expect(app(FrontPage::class)->designated())->toBeNull();
});

it('answers with nothing for an id no page has', function () {
    app(GeneralSettings::class)->with(['frontPageId' => 4040])->save();

    expect(app(FrontPage::class)->designated())->toBeNull();
});

it('recognises the designated page whatever state it is in', function () {
    $page = Page::factory()->create();

    makeFrontPage($page);

    /**
     * Asked by the invalidator, which has to forget the Site Root's entry
     * precisely when the Front Page is unpublished or deleted — the moments
     * `designated()` has already stopped answering.
     */
    expect(app(FrontPage::class)->isDesignated($page))->toBeTrue()
        ->and(app(FrontPage::class)->isDesignated(Page::factory()->create()))->toBeFalse();
});

it('reads the designation as it stands now, not as it stood when it was built', function () {
    $page = Page::factory()->published()->create(['slug' => 'promo']);

    $frontPage = app(FrontPage::class);

    expect($frontPage->designated())->toBeNull();

    makeFrontPage($page);

    /**
     * The same instance, asked twice across a save. It must answer differently,
     * because `Route::getController()` caches the controller it builds on the
     * Route object and the route collection outlives a request — so the
     * `FrontPage` the Site Root is served through is built once and reused. A
     * `GeneralSettings` captured in this class's constructor would be frozen at
     * whatever the first request saw, and designating a Front Page would never
     * take effect until the process died.
     */
    expect($frontPage->designated()?->slug)->toBe('promo');
});
