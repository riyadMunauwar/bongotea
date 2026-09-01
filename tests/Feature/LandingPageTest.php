<?php

use App\Models\Page;
use App\Models\Product;
use App\Shortcodes\Node;
use Illuminate\Support\Facades\Log;

it('serves a published page at its slug and writes it to the cache', function () {
    Page::factory()->published()->withBody('<h1>Winter Promo</h1>')->create(['slug' => 'promo']);

    $response = $this->get('/promo');

    $response->assertOk()->assertSee('Winter Promo', escape: false);

    expect($this->cache->has('promo'))->toBeTrue();
});

it('serves the stored document byte for byte', function () {
    $page = Page::factory()->published()->create(['slug' => 'promo']);

    /**
     * The merchant owns the whole document, `<head>` included. Anything we wrap
     * around it or inject into it looks to them like the product broke their
     * page.
     */
    expect($this->get('/promo')->getContent())->toBe($page->body);
});

it('sets no cookie on the landing route', function () {
    Page::factory()->published()->create(['slug' => 'promo']);

    $response = $this->get('/promo');

    /**
     * A Set-Cookie here would mean the bytes written to the cache differ from
     * the bytes a live render returns, and every visitor after the first would
     * silently lose their cookie.
     */
    expect($response->headers->getCookies())->toBeEmpty();
});

it('tells the visitor not to keep a private copy of the miss response', function () {
    Page::factory()->published()->create(['slug' => 'promo']);

    /**
     * The shared file on disk is the one source of truth, and the observers
     * invalidate that. A private copy in a visitor's browser would outlive an
     * edit.
     */
    expect($this->get('/promo')->headers->get('Cache-Control'))->toContain('no-store');
});

it('404s an unpublished page and leaves nothing on disk', function () {
    Page::factory()->withBody('<h1>Draft</h1>')->create(['slug' => 'promo']);

    $this->get('/promo')->assertNotFound();

    /**
     * public/page-cache is served publish-blind, so a draft written there would
     * be publicly reachable *and* frozen in place.
     */
    expect($this->cache->has('promo'))->toBeFalse();
});

it('404s a soft deleted page', function () {
    $page = Page::factory()->published()->create(['slug' => 'promo']);

    $page->delete();

    $this->get('/promo')->assertNotFound();
});

it('renders a shortcode in the body', function () {
    registerShortcode('greeting', fn (Node $node, Page $page): string => '<p>Hello from '.$page->slug.'</p>');

    Page::factory()->published()->withBody('<main>[greeting]</main>')->create(['slug' => 'promo']);

    $this->get('/promo')
        ->assertOk()
        ->assertSee('<p>Hello from promo</p>', escape: false)
        ->assertDontSee('[greeting]', escape: false);
});

it('renders the checkout shortcode with the page product', function () {
    $product = Product::factory()->create(['name' => 'Winter Jacket', 'price' => 99000]);
    $page = Page::factory()->published()->withBody('<main>[checkout]</main>')->create(['slug' => 'promo']);
    $page->products()->attach($product);

    $this->get('/promo')
        ->assertOk()
        ->assertSee('Winter Jacket')
        ->assertSee('৳990.00');
});

it('renders only the product linked to the page the checkout sits on', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket', 'price' => 99000]);
    $boots = Product::factory()->create(['name' => 'Hiking Boots', 'price' => 149000]);

    $jacketPage = Page::factory()->published()->withBody('<main>[checkout]</main>')->create(['slug' => 'jackets']);
    $jacketPage->products()->attach($jacket);

    $bootsPage = Page::factory()->published()->withBody('<main>[checkout]</main>')->create(['slug' => 'boots']);
    $bootsPage->products()->attach($boots);

    /**
     * Every figure the visitor is asked to agree to comes from the Page they
     * are standing on. A checkout that could show a neighbouring page's product
     * would collect the wrong price at the door.
     */
    $this->get('/jackets')
        ->assertSee('Winter Jacket')
        ->assertSee('৳990.00')
        ->assertDontSee('Hiking Boots')
        ->assertDontSee('৳1490.00');
});

it('posts the checkout back with the slug of the page it sits on', function () {
    $page = Page::factory()->published()->withBody('<main>[checkout]</main>')->create(['slug' => 'promo']);
    $page->products()->attach(Product::factory()->create());

    /**
     * The slug is how Capture finds the Page, and with it the Product and the
     * price. It is the only thing the form tells the server about what is being
     * sold.
     */
    $this->get('/promo')
        ->assertSee('<input type="hidden" name="page" value="promo">', escape: false)
        ->assertSee('action="'.route('checkout.store').'"', escape: false);
});

it('bakes no request forgery token into a page every visitor shares', function () {
    $page = Page::factory()->published()->withBody('<main>[checkout]</main>')->create(['slug' => 'promo']);
    $page->products()->attach(Product::factory()->create());

    /**
     * A token here would be one person's token written to disk and handed to
     * everyone. The POST authenticates by origin instead.
     */
    $this->get('/promo')->assertDontSee('_token', escape: false);
});

it('drops the checkout when the page product was soft deleted, and keeps rendering', function () {
    $product = Product::factory()->create(['name' => 'Winter Jacket']);
    $page = Page::factory()->published()->withBody('<main>[checkout]</main>')->create(['slug' => 'promo']);
    $page->products()->attach($product);

    $product->delete();

    /**
     * 404-ing mid-campaign burns ad spend on a dead link; still taking orders
     * means collecting cash for something that cannot ship. Rendering without a
     * checkout fails in the direction that is cheapest to recover from, and is
     * visible: conversions go to zero.
     */
    $this->get('/promo')
        ->assertOk()
        ->assertSee('<!-- giopages: checkout unavailable (no product linked) -->', escape: false)
        ->assertDontSee('gio-checkout', escape: false);
});

it('serves a page whose shortcode threw, but refuses to freeze it onto disk', function () {
    Log::spy();

    registerShortcode('boom', function (): string {
        throw new RuntimeException('definition blew up');
    });

    Page::factory()->published()->withBody('<main>[boom]</main>')->create(['slug' => 'promo']);

    /**
     * Fail-soft *plus* caching would freeze a broken page onto disk, where
     * nginx serves it to everyone until someone happens to save something.
     * Skipping the write costs a boot per request instead — recoverable.
     */
    $this->get('/promo')
        ->assertOk()
        ->assertSee('<!-- giopages: [boom] failed to render -->', escape: false);

    expect($this->cache->has('promo'))->toBeFalse();

    Log::shouldHaveReceived('error')->once();
});
