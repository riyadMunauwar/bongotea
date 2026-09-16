<?php

use App\Models\Page;
use App\Models\User;

it('requires a signed-in user', function () {
    $page = Page::factory()->create();

    $this->get(route('pages.preview', $page))->assertRedirect();
});

/**
 * The preview is the Pages resource's back door onto a draft, so it is gated by
 * the same `author pages` Permission (#158): a User who cannot open the Pages
 * screens has no way to reach a draft through here either.
 */
it('refuses a signed-in User who cannot author pages', function () {
    $page = Page::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('pages.preview', $page))
        ->assertForbidden();
});

it('renders a draft through the same pipeline without publishing it', function () {
    registerShortcode('greeting', fn (): string => '<p>Hello</p>');

    $page = Page::factory()->withBody('<main>[greeting]</main>')->create(['slug' => 'promo']);

    /**
     * A preview that renders differently is not a preview, so this shares the
     * expander rather than reimplementing expansion. Without it the merchant's
     * only way to see a page is to publish it — which is also the act that
     * makes it reachable to ad traffic.
     */
    $this->actingAs(User::factory()->authoringPages()->create())
        ->get(route('pages.preview', $page))
        ->assertOk()
        ->assertSee('<p>Hello</p>', escape: false);
});

it('never writes the preview to the static cache', function () {
    $page = Page::factory()->create(['slug' => 'promo']);

    $this->actingAs(User::factory()->authoringPages()->create())
        ->get(route('pages.preview', $page))
        ->assertOk();

    /**
     * public/page-cache is served by the webserver publish-blind, so anything
     * there is public. A draft must never reach it.
     */
    expect($this->cache->has('promo'))->toBeFalse();
});
