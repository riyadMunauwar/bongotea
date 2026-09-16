<?php

use App\Enums\PaymentMethod;
use App\Models\Page;
use App\Models\Product;
use App\Shortcodes\Node;
use App\Support\Checkout\CheckoutConfig;
use App\Support\Checkout\CheckoutFont;
use App\Support\Checkout\CheckoutLayout;
use App\Support\Checkout\FieldWidth;
use App\Support\Checkout\OrderButton;
use App\Support\Checkout\StyleToken;
use App\Support\Checkout\WordSlot;
use App\Support\Money;
use App\Support\PageCache\FallbackPageCacheReader;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;

it('serves a published page at its slug and writes it to the cache', function () {
    Page::factory()->published()->withBody('<h1>Winter Promo</h1>')->create(['slug' => 'promo']);

    $response = $this->get('/promo');

    $response->assertOk()->assertSee('Winter Promo', escape: false);

    expect($this->cache->has('promo'))->toBeTrue();
});

it('hands the visitor, the disk and the fallback reader the same bytes', function () {
    Page::factory()->published()->create(['slug' => 'promo']);

    $response = $this->get('/promo');

    /**
     * The controller writes the file before the response leaves the framework,
     * so anything that edits it on the way out — a middleware, a
     * `RequestHandled` listener — splits the two apart silently: the visitor
     * who paid for the render gets the edited bytes, and the webserver hands
     * everyone after them the file. This equality is what the whole static
     * cache rests on, and it is the assertion that fails whatever the cause.
     *
     * The third representation is what a Merchant whose webserver was never
     * configured serves. If it ever diverged they would ship different HTML
     * from a Merchant on the fast path, with nothing anywhere to say so — which
     * is why the identity is pinned in one place rather than pairwise.
     */
    $served = (new FallbackPageCacheReader($this->cacheDirectory))->resolve('/promo', 'GET');

    expect(file_get_contents($this->cache->htmlPath('promo')))->toBe($response->getContent())
        ->and(file_get_contents($served['path']))->toBe($response->getContent());
});

it('marks a full render as the path that did not come from the cache', function () {
    Page::factory()->published()->create(['slug' => 'promo']);

    /**
     * One header names the three serving paths, and the fast path is the one
     * that sets nothing: Apache cannot reliably stamp a header onto a static
     * file, so absence is the only marker every supported webserver can
     * produce.
     */
    expect($this->get('/promo')->headers->get('X-GioPages-Cache'))->toBe('miss');
});

it('serves the stored document byte for byte but for the canonical', function () {
    $page = Page::factory()->published()->create(['slug' => 'promo']);

    /**
     * The merchant owns the whole document. Anything we wrap around it looks
     * to them like the product broke their page — so what is injected is
     * counted, and this assertion is where the count is kept.
     *
     * It used to be exactly one thing, and only where the merchant asked for
     * it: the tracking snippet, into a Page that reports to a Destination
     * (ADR-0017). It is now two, and the second is unconditional. The canonical
     * goes into every Page because a Front Page answers at two addresses and
     * the bytes may not know which one they are being served at (ADR-0023) —
     * so a link that only the Front Page needed is drawn on all of them, and a
     * Merchant who wanted a bare document no longer gets one.
     *
     * Two things hold it down, and both are pinned in `CanonicalLinkTest`: it
     * goes in only where there is a head to put it in, and it stands down
     * entirely for a Merchant who wrote a canonical of their own.
     */
    expect($this->get('/promo')->getContent())->toBe(asServed($page));
});

it('serves the stored document byte for byte with a component already rendered', function () {
    $page = Page::factory()->published()->create(['slug' => 'promo']);

    /**
     * What an admin screen leaves behind. Livewire sets this static flag when a
     * component renders and injects its styles and its script tag into every
     * HTML response while it stands; nothing in a request lifecycle clears it,
     * so it walks from the test that opened the admin into this one — and the
     * bytes it would land in are the ones `StaticPageCache` freezes onto disk.
     *
     * Set here rather than by fetching an admin page so the guarantee does not
     * depend on which tests happen to run first, or on the alphabet.
     */
    SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = true;

    expect($this->get('/promo')->getContent())->toBe(asServed($page));
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

it('renders every product the page sells, each with a quantity of its own', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket', 'price' => 99000]);
    $boots = Product::factory()->create(['name' => 'Hiking Boots', 'price' => 149000]);

    $page = Page::factory()->published()->withBody('<main>[checkout]</main>')->create(['slug' => 'promo']);
    $page->products()->attach([$jacket->id, $boots->id]);

    $html = $this->get('/promo')->assertOk()->getContent();

    /**
     * Named by Product id rather than by position: reordering the links in the
     * admin must not point a form already open on a phone at a different item.
     */
    expect($html)->toContain('Winter Jacket')
        ->and($html)->toContain('৳990.00')
        ->and($html)->toContain('name="quantities['.$jacket->getKey().']"')
        ->and($html)->toContain('Hiking Boots')
        ->and($html)->toContain('৳1490.00')
        ->and($html)->toContain('name="quantities['.$boots->getKey().']"')
        /** One of everything, which is what the quantity boxes start on. */
        ->and($html)->toContain('৳2480.00');
});

it('shows each product its own image', function () {
    /**
     * Faked with the disk's own configuration rather than with the bare
     * defaults, because the address an image is served at is read off that
     * configuration — a fake carrying none of it would answer for a disk this
     * application does not have.
     */
    Storage::fake(Product::IMAGE_DISK, config('filesystems.disks.'.Product::IMAGE_DISK));

    $jacket = Product::factory()->create(['name' => 'Winter Jacket']);
    $boots = Product::factory()->create(['name' => 'Hiking Boots']);

    $jacket->addMediaFromString('jacket-bytes')->usingFileName('jacket.jpg')->toMediaCollection(Product::IMAGE_COLLECTION);
    $boots->addMediaFromString('boots-bytes')->usingFileName('boots.jpg')->toMediaCollection(Product::IMAGE_COLLECTION);

    $page = Page::factory()->published()->withBody('<main>[checkout]</main>')->create(['slug' => 'promo']);
    $page->products()->attach([$jacket->id, $boots->id]);

    $html = $this->get('/promo')->assertOk()->getContent();

    /**
     * A customer buying from a page that sells several things has to be able to
     * see which is which. Each image is the Product's own, alt-texted with the
     * Product's own name.
     */
    expect($jacket->imageUrl())->toContain('jacket.jpg')
        ->and($boots->imageUrl())->toContain('boots.jpg')
        ->and($html)->toContain('src="'.$jacket->imageUrl().'"')
        ->and($html)->toContain('src="'.$boots->imageUrl().'"')
        ->and($html)->toContain('alt="Winter Jacket"')
        ->and($html)->toContain('alt="Hiking Boots"');

    /**
     * And the address in the markup is one a visitor can actually fetch. A
     * page that names an image it cannot serve is the failure this is here
     * for: it asked a Customer paying cash on delivery to commit to something
     * they could not see, and it was invisible to every assertion that only
     * read the markup.
     */
    expect($jacket->imageUrl())->toBeServedFromTheDocumentRoot()
        ->and($boots->imageUrl())->toBeServedFromTheDocumentRoot();

    /**
     * Served by the webserver reading a file, and by nothing else. The
     * framework's serve route is off this disk, so no route claims that
     * address and PHP never boots for an image — which is the whole premise of
     * a product whose Pages are static files.
     */
    expect(Route::has('storage.'.Product::IMAGE_DISK))->toBeFalse();
});

it('keeps selling the rest when one of several products is deleted', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket']);
    $boots = Product::factory()->create(['name' => 'Hiking Boots']);

    $page = Page::factory()->published()->withBody('<main>[checkout]</main>')->create(['slug' => 'promo']);
    $page->products()->attach([$jacket->id, $boots->id]);

    /**
     * Taking a working page off the air over a deleted upsell turns a
     * merchandising slip into dead ad spend. The jacket is still exactly what
     * it was, at the price it was.
     */
    $boots->delete();

    $this->get('/promo')
        ->assertOk()
        ->assertSee('Winter Jacket')
        ->assertDontSee('Hiking Boots')
        ->assertSee('gio-checkout', escape: false);
});

it('drops the checkout when every product the page sells was soft deleted, and keeps rendering', function () {
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
     * it is served to everyone until someone happens to save something.
     * Skipping the write costs a boot per request instead — recoverable.
     */
    $this->get('/promo')
        ->assertOk()
        ->assertSee('<!-- giopages: [boom] failed to render -->', escape: false);

    expect($this->cache->has('promo'))->toBeFalse();

    Log::shouldHaveReceived('error')->once();
});

it('asks a page nobody configured for the details the checkout has always asked for', function () {
    $page = Page::factory()->published()->withBody('<main>[checkout]</main>')->create(['slug' => 'promo']);
    $page->products()->attach(Product::factory()->create());

    /**
     * The seeded Checkout Config reproduces the form as it stood before a Page
     * could carry one. A merchant who never opens the checkout settings has to
     * see no change at all.
     */
    $this->get('/promo')
        ->assertSee('Your name')
        ->assertSee('Phone number')
        ->assertSee('District')
        ->assertSee('Delivery address');
});

it('asks in the wording and the order the page\'s own checkout config names', function () {
    $page = Page::factory()->published()->withBody('<main>[checkout]</main>')->create([
        'slug' => 'promo',
        'checkout_config' => CheckoutConfig::fromArray([
            'fields' => [
                ['key' => 'phone', 'label' => 'আপনার মোবাইল নম্বর', 'required' => true],
                ['key' => 'name', 'label' => 'আপনার নাম', 'required' => true],
            ],
        ]),
    ]);
    $page->products()->attach(Product::factory()->create());

    $html = $this->get('/promo')->assertOk()->getContent();

    preg_match_all('/ name="([a-z]+)"/', $html, $asked);

    /**
     * The merchant's wording, in the merchant's order, with the built-ins their
     * Config never mentioned following on behind — a Checkout is complete
     * whatever was stored for it.
     */
    expect($html)->toContain('আপনার মোবাইল নম্বর')
        ->and($html)->not->toContain('Phone number')
        /**
         * The quantity boxes are absent from this list because they are named
         * `quantities[<id>]` — keyed by Product, which this pattern does not
         * match. What is being pinned here is the field list, and the fields
         * are all of it; `page` and `fingerprint` are the two hidden inputs
         * that say which Page this is and what it quoted, and neither is
         * something the merchant asked for.
         */
        ->and($asked[1])->toBe(['page', 'fingerprint', 'phone', 'name', 'district', 'address']);
});

it('renders no box at all for a question the page has stopped asking', function () {
    $page = Page::factory()->published()->withBody('<main>[checkout]</main>')->create([
        'slug' => 'promo',
        'checkout_config' => CheckoutConfig::fromArray([
            'fields' => [
                ['key' => 'name', 'label' => 'Your name', 'enabled' => false],
                ['key' => 'district', 'label' => 'District', 'enabled' => false],
            ],
        ]),
    ]);
    $page->products()->attach(Product::factory()->create());

    $html = $this->get('/promo')->assertOk()->getContent();

    preg_match_all('/ name="([a-z]+)"/', $html, $asked);

    /**
     * Switched off is absent, not hidden. A field left in the markup would
     * still be posted by a browser and still be there to autofill, and the
     * merchant asked for a shorter form.
     */
    expect($asked[1])->toBe(['page', 'fingerprint', 'phone', 'address'])
        ->and($html)->not->toContain('Your name')
        ->and($html)->not->toContain('Choose your district');
});

it('asks for an email address once the merchant switches one on', function () {
    $page = Page::factory()->published()->withBody('<main>[checkout]</main>')->create([
        'slug' => 'promo',
        'checkout_config' => CheckoutConfig::fromArray([
            'fields' => [
                ['key' => 'email', 'label' => 'Email address', 'enabled' => true, 'required' => false],
            ],
        ]),
    ]);
    $page->products()->attach(Product::factory()->create());

    $html = $this->get('/promo')->assertOk()->getContent();

    /**
     * Asked where the merchant's Config puts it, not appended to the end: the
     * stored list is the order, and a built-in it never mentioned follows on
     * behind.
     */
    preg_match_all('/ name="([a-z]+)"/', $html, $asked);

    expect($asked[1])->toBe(['page', 'fingerprint', 'email', 'name', 'phone', 'district', 'address'])
        ->and($html)->toContain('Email address')
        /** The keyboard a phone opens for an address is not the one it opens for a name. */
        ->and($html)->toContain('type="email"')
        ->and($html)->toContain('inputmode="email"');
});

it('hides a quantity behind the checkbox that reveals it', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket', 'price' => 99000]);
    $boots = Product::factory()->create(['name' => 'Hiking Boots', 'price' => 149000]);

    $page = Page::factory()->published()->withBody('<main>[checkout]</main>')->create([
        'slug' => 'promo',
        'checkout_config' => CheckoutConfig::fromArray(['product_picker' => true]),
    ]);
    $page->products()->attach([$jacket->id, $boots->id]);

    $html = $this->get('/promo')->assertOk()->getContent();

    /**
     * Five products on a phone are five lines of text until they are wanted,
     * which is the whole point of the picker. The stepper is hidden by the
     * checkbox's own state, so it appears without a script having to run.
     */
    expect($html)->toMatch('/\.gio-checkout-pick:not\(:checked\)\s*~\s*\.gio-checkout-quantity\s*\{[^}]*display:\s*none/')
        ->and(substr_count($html, 'name="selected[]"'))->toBe(2)
        /** The name and the picture are the box's label, so a thumb can tick them. */
        ->and($html)->toContain('<label class="gio-checkout-product-head" for="gio-pick-'.$jacket->getKey().'"')
        ->and($html)->toContain('name="quantities['.$jacket->getKey().']"')
        ->and($html)->toContain('name="quantities['.$boots->getKey().']"');

    /**
     * The steppers stop being `required` where they can be hidden: a box
     * emptied and then unticked would fail the browser's own check from
     * behind `display: none`, which refuses to submit and shows the customer
     * nothing at all.
     */
    preg_match_all('/<input[^>]*data-gio-quantity[^>]*>/', $html, $quantities);

    expect($quantities[0])->toHaveCount(2);

    foreach ($quantities[0] as $quantity) {
        expect($quantity)->not->toContain('required');
    }

    /** Nothing is ticked, because this page's merchant has said nothing about which should be. */
    expect($html)->toContain('৳0.00');

    preg_match_all('/<input class="gio-checkout-pick"[^>]*>/', $html, $picks);

    expect($picks[0])->toHaveCount(2);

    foreach ($picks[0] as $pick) {
        expect($pick)->not->toContain('checked');
    }
});

/**
 * A published page whose picker opens on the Products named, selling the
 * Products it is handed.
 *
 * @param  list<Product>  $products
 * @param  list<int>  $ticked
 * @param  array<string, mixed>  $config
 */
function pickingPage(array $products, array $ticked, array $config = []): Page
{
    $page = Page::factory()->published()->withBody('<main>[checkout]</main>')->create([
        'slug' => 'promo',
        'checkout_config' => CheckoutConfig::fromArray([
            'product_picker' => true,
            'default_selection' => $ticked,
            ...$config,
        ]),
    ]);

    $page->products()->attach(array_map(fn (Product $product): int => $product->getKey(), $products));

    return $page->refresh();
}

/**
 * The checkbox drawn for one Product, read out of the served page.
 *
 * Matched on the value rather than on the order the boxes appear in, because
 * nothing resolves a Product by the position it sits at.
 */
function pickFor(string $html, Product $product): string
{
    preg_match('/<input class="gio-checkout-pick"[^>]*value="'.$product->getKey().'"[^>]*>/', $html, $pick);

    return $pick[0] ?? '';
}

it('opens the picker on the products a merchant pre-ticked', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket', 'price' => 99000]);
    $boots = Product::factory()->create(['name' => 'Hiking Boots', 'price' => 149000]);

    pickingPage([$jacket, $boots], [$jacket->getKey()]);

    $html = $this->get('/promo')->assertOk()->getContent();

    /**
     * The customer meets a price rather than a form totalling nothing, and the
     * stepper for what is ticked is on screen without a script having run —
     * the checkbox's own state reveals it.
     */
    expect(pickFor($html, $jacket))->toContain('checked')
        ->and(pickFor($html, $boots))->not->toContain('checked')
        /** What is ticked and nothing else: the shelf is 2,480 and no order could come to it. */
        ->and($html)->toContain('৳990.00')
        ->and($html)->toContain('৳1050.00')
        ->and($html)->not->toContain('৳2480.00')
        /**
         * A ticked line's stepper is on screen without a script having run,
         * because the quantity is hidden by the checkbox's own state. That
         * rule is what makes a pre-ticked line orderable at all, so it is
         * pinned beside the tick rather than only where the picker is.
         */
        ->and($html)->toMatch('/\.gio-checkout-pick:not\(:checked\)\s*~\s*\.gio-checkout-quantity\s*\{[^}]*display:\s*none/')
        ->and($html)->toContain('name="quantities['.$jacket->getKey().']"');
});

it('drops a tick a merchant left behind when the picker is switched off', function () {
    $jacket = Product::factory()->create(['price' => 99000]);
    $boots = Product::factory()->create(['price' => 149000]);

    $page = pickingPage([$jacket, $boots], [$jacket->getKey()], ['product_picker' => false]);

    $html = $this->get('/promo')->assertOk()->getContent();

    /**
     * The other half of the gate the layout is one half of. A page selling
     * everything it links has no box to open ticked, so the tick is dropped
     * and the total is the whole shelf — which is what the customer is being
     * charged. The merchant's list is kept for the day they switch the picker
     * back on.
     */
    expect($html)->not->toContain('name="selected[]"')
        ->and($page->checkout_config->defaultSelection)->toBe([$jacket->getKey()])
        ->and($page->checkout_config->opensTicked())->toBe([])
        ->and($page->defaultSelection())->toBe([])
        ->and($html)->toContain('৳2480.00');
});

it('opens ticked on every product a merchant ticked, however many that is', function () {
    $jacket = Product::factory()->create(['price' => 99000]);
    $boots = Product::factory()->create(['price' => 149000]);

    pickingPage([$jacket, $boots], [$jacket->getKey(), $boots->getKey()]);

    $html = $this->get('/promo')->assertOk()->getContent();

    /**
     * A bundle the merchant asserted, honoured as asserted. The risk is real
     * and is theirs to take — the Checkout tab warns them above one, and a
     * merchant selling a set that is normally bought together knows their own
     * products better than this rule does (ADR-0010).
     */
    expect(pickFor($html, $jacket))->toContain('checked')
        ->and(pickFor($html, $boots))->toContain('checked')
        ->and($html)->toContain('৳2480.00');
});

it('drops a tick against a product the page has stopped selling', function () {
    $jacket = Product::factory()->create(['price' => 99000]);
    $boots = Product::factory()->create(['price' => 149000]);
    $scarf = Product::factory()->create(['price' => 45000]);

    $page = pickingPage([$jacket, $boots, $scarf], [$jacket->getKey(), $boots->getKey()]);

    $jacket->delete();

    $html = $this->get('/promo')->assertOk()->getContent();

    /**
     * A form cannot open ticked against something the page cannot offer, so
     * the tick is dropped as the page is drawn. The merchant's list is kept
     * whole meanwhile: a product coming back out of the bin opens ticked again
     * the way they left it, rather than being silently forgotten by a delete
     * they may undo.
     */
    expect(pickFor($html, $jacket))->toBe('')
        ->and(pickFor($html, $boots))->toContain('checked')
        ->and(pickFor($html, $scarf))->not->toContain('checked')
        /** The total is what is left ticked, not what the merchant ticked. */
        ->and($html)->toContain('৳1490.00')
        ->and($page->refresh()->checkout_config->defaultSelection)
        ->toBe([$jacket->getKey(), $boots->getKey()])
        ->and($page->defaultSelection())->toBe([$boots->getKey()]);
});

it('never opens a checkout whose product list is hidden on a pre-ticked product', function () {
    $jacket = Product::factory()->create();
    $boots = Product::factory()->create();

    $page = pickingPage([$jacket, $boots], [$jacket->getKey()], ['show_products' => false]);

    $html = $this->get('/promo')->assertOk()->getContent();

    /**
     * There is nothing on screen to tick, so a tick is a box nobody can see
     * and nobody can untick. Dropped on read like the picker it belongs to,
     * and kept in the Config so switching back restores the page the merchant
     * configured.
     */
    expect($html)->not->toContain('name="selected[]"')
        ->and($page->checkout_config->defaultSelection)->toBe([$jacket->getKey()])
        ->and($page->checkout_config->opensTicked())->toBe([])
        ->and($page->defaultSelection())->toBe([]);
});

it('opens no tick on a page down to the one product it sells', function () {
    $jacket = Product::factory()->create(['price' => 99000]);
    $boots = Product::factory()->create(['price' => 149000]);

    $page = pickingPage([$jacket, $boots], [$jacket->getKey()]);

    $boots->delete();

    $html = $this->get('/promo')->assertOk()->getContent();

    /**
     * A choice of one is not a choice: the page sells its last product
     * implicitly rather than drawing a tick beside the only thing on offer,
     * and the total says so.
     */
    expect($html)->not->toContain('name="selected[]"')
        ->and($page->refresh()->defaultSelection())->toBe([])
        ->and($html)->toContain('৳990.00');
});

/**
 * A published page selling one product, with the Checkout Config given.
 *
 * @param  array<string, mixed>  $config
 */
function pageStyled(array $config, string $slug = 'promo'): Page
{
    $page = Page::factory()->published()->withBody('<main>[checkout]</main>')->create([
        'slug' => $slug,
        'checkout_config' => CheckoutConfig::fromArray($config),
    ]);

    $page->products()->attach(Product::factory()->create());

    return $page;
}

it('declares the whole token set on the checkout root', function () {
    pageStyled([]);

    preg_match('/\.gio-checkout \{([^}]*)\}/', styleBlock($this->get('/promo')->getContent()), $root);

    /**
     * Complete on a page nobody has styled, so every rule under it can read a
     * token rather than carrying a fallback of its own — and so a merchant can
     * see the whole vocabulary in the page they are looking at.
     */
    foreach (StyleToken::cases() as $token) {
        expect($root[1] ?? '')->toContain($token->property().': ');
    }
});

it('paints the checkout in the tokens the page carries', function () {
    pageStyled(['style_tokens' => [
        'accent' => '#ff0000',
        'button_background' => '#101010',
        'radius' => '12px',
        'muted' => '#808080',
    ]]);

    $styles = styleBlock($this->get('/promo')->assertOk()->getContent());

    expect($styles)->toContain('--gio-accent: #ff0000;')
        ->and($styles)->toContain('--gio-button-background: #101010;')
        ->and($styles)->toContain('--gio-radius: 12px;')
        ->and($styles)->toContain('--gio-muted: #808080;')
        /**
         * Read by the rules that were already there rather than written into
         * them, which is what makes one redeclaration restyle every use.
         */
        ->and($styles)->toContain('background: var(--gio-button-background)')
        ->and($styles)->toContain('border-radius: var(--gio-radius)');
});

it('sets the checkout in Hind Siliguri served from this site', function (CheckoutLayout $layout) {
    pageStyled(['layout' => $layout->value]);

    $styles = styleBlock($this->get('/promo')->assertOk()->getContent());

    preg_match('/\.gio-checkout \{([^}]*)\}/', $styles, $root);

    /**
     * Bangla is most of what a checkout says, and the face that draws it comes
     * from this site and nowhere else: a landing page answering paid traffic
     * makes no request to a font service on a visitor's behalf. One block of
     * faces, declared once whatever the arrangement — the four weights the
     * designs use, split by script so a browser fetches only what a page draws
     * — and the root reads the token the default names it in.
     */
    expect(substr_count($styles, '@font-face'))->toBe(count(CheckoutFont::files()))
        ->and($styles)->not->toContain('https://')
        ->and($root[1] ?? '')->toContain('--gio-font: '.CheckoutFont::stack().';')
        ->and($root[1] ?? '')->toContain('font-family: var(--gio-font)');

    foreach (CheckoutFont::WEIGHTS as $weight) {
        expect($styles)->toContain('font-weight: '.$weight.';');
    }

    /** Every file a face points at is shipped under public, and is the woff2 it claims to be. */
    foreach (CheckoutFont::files() as $file) {
        expect($styles)->toContain('url(/'.$file.')')
            ->and(file_exists(public_path($file)))->toBeTrue($file.' is missing')
            ->and(file_get_contents(public_path($file), length: 4))->toBe('wOF2');
    }
})->with(fn () => CheckoutLayout::cases());

it('inherits the page\'s own typeface when the font token says so', function () {
    pageStyled(['style_tokens' => ['font' => 'inherit']]);

    $styles = styleBlock($this->get('/promo')->assertOk()->getContent());

    /**
     * A merchant who wants the checkout to blend in asks for the page's font
     * and gets exactly that — and no font file, because a face nothing reads
     * is bytes in every cached page for nothing.
     */
    expect($styles)->toContain('--gio-font: inherit;')
        ->and($styles)->not->toContain('@font-face')
        ->and($styles)->not->toContain(CheckoutFont::DIRECTORY);
});

it('loads no face for a stack of the merchant\'s own', function () {
    pageStyled(['style_tokens' => ['font' => 'Georgia, Times New Roman, serif']]);

    $styles = styleBlock($this->get('/promo')->assertOk()->getContent());

    /** Names of several words are written bare, as CSS allows, so nothing here needs a quote. */
    expect($styles)->toContain('--gio-font: Georgia, Times New Roman, serif;')
        ->and($styles)->not->toContain('@font-face');
});

it('refuses a font that is not a list of names', function (string $font) {
    pageStyled(['style_tokens' => ['font' => $font]]);

    $html = $this->get('/promo')->assertOk()->getContent();

    /**
     * Refused on the Look tab with a message and dropped again here without
     * one, falling back to the design's own stack — and never written into
     * the block, so a quote or a brace typed into the box cannot end the
     * declaration it would have landed in.
     */
    expect($html)->not->toContain('body {')
        ->and(styleBlock($html))->toContain('--gio-font: '.CheckoutFont::stack().';');
})->with([
    'a value that would close the declaration' => 'Georgia; } body { display: none } .x {',
    'a quoted name' => '"Hind Siliguri", serif',
    'a url' => 'url(https://example.com/a.woff2)',
    'a keyword that is not inherit' => 'initial',
    /** A browser meeting a keyword anywhere in a list drops the whole declaration. */
    'a keyword hidden in a list' => 'inherit, serif',
    'a stack longer than anyone meant' => str_repeat('Georgia, ', 20).'serif',
]);

it('reads a background stored before the surface token existed as the boxes\' fill', function () {
    pageStyled(['style_tokens' => ['background' => '#000000']], slug: 'old');
    pageStyled(['style_tokens' => ['background' => '#000000', 'surface' => '#ffffff']], slug: 'new');

    $old = styleBlock($this->get('/old')->assertOk()->getContent());
    $new = styleBlock($this->get('/new')->assertOk()->getContent());

    /**
     * `background` once filled the inputs. A published page that coloured
     * them keeps them coloured rather than gaining a slab behind the whole
     * form on upgrade; a page that says both is read as it says.
     */
    expect($old)->toContain('--gio-surface: #000000;')
        ->and($old)->toContain('--gio-background: '.StyleToken::Background->defaultValue().';')
        ->and($new)->toContain('--gio-surface: #ffffff;')
        ->and($new)->toContain('--gio-background: #000000;');
});

it('ignores a question\'s stored appearance and writes no style on its row', function () {
    $page = pageStyled(['fields' => [
        ['key' => 'name', 'label' => 'Your name', 'style_tokens' => ['border' => '#ff0000', 'text' => '#00ff00']],
        ['key' => 'phone', 'label' => 'Phone number'],
    ]]);

    $html = $this->get('/promo')->assertOk()->getContent();

    /**
     * A field once carried tokens of its own, emitted on its wrapper. Styling
     * is the checkout's alone now, so a page that restyled one question simply
     * follows the rest: the stored object is dropped on read, nothing of it
     * reaches the page, and no field wrapper carries a style attribute.
     */
    expect($html)->not->toContain('#ff0000')
        ->and($html)->not->toContain('#00ff00')
        ->and($html)->not->toMatch('/<div class="gio-checkout-field[^"]*"[^>]*style=/')
        ->and($page->checkout_config->fields[0]->toArray())->not->toHaveKey('style_tokens');
});

it('keeps the emitted styles the same length however many questions are asked', function () {
    pageStyled([], slug: 'plain');

    $many = array_map(fn (int $index): array => [
        'key' => 'question_'.$index,
        'label' => 'Question '.$index,
        'style_tokens' => ['border' => '#ff0000', 'radius' => '2px'],
    ], range(1, 12));

    pageStyled(['fields' => $many], slug: 'busy');

    /**
     * Byte for byte, whatever the form asks and however much of it is styled
     * differently. A generated rule per field would grow this block with every
     * question and change what a merchant's own stylesheet has to beat.
     */
    expect(styleBlock($this->get('/busy')->getContent()))
        ->toBe(styleBlock($this->get('/plain')->getContent()));
});

it('leaves the checkout losable to a merchant\'s own stylesheet', function (CheckoutLayout $layout) {
    pageStyled(['layout' => $layout->value, 'style_tokens' => ['accent' => '#ff0000']]);

    $styles = styleBlock($this->get('/promo')->getContent());

    preg_match_all('/(^|\})([^{}]+)\{/', $styles, $selectors);

    /**
     * The tokens are declared at one class, on the root, with nothing raising
     * them — so a merchant redeclaring them, or simply setting the property
     * outright, wins from their own stylesheet without escalating.
     *
     * Read on every arrangement rather than only on the stacked one, because
     * the sheet a page is served now ends with the block its own layout adds:
     * a rule reaching for an id there would out-specify the merchant on that
     * layout alone, and a check on one arrangement would never see it.
     */
    expect($styles)->not->toContain('!important');

    foreach ($selectors[2] as $selector) {
        expect(trim($selector))->not->toContain('#');
    }

    expect(array_filter($selectors[2], fn (string $selector): bool => trim($selector) === '.gio-checkout'))
        ->not->toBeEmpty();
})->with(fn () => CheckoutLayout::cases());

it('never lets a stored style value break out of the block it is written into', function () {
    pageStyled(['style_tokens' => [
        'accent' => 'red; } body { display: none } .x {',
        'radius' => '4pt',
        'muted' => 'reddish',
        'surface' => 'var(--gio-accent)',
        'font' => 'Georgia; } body { display: none } .x {',
    ]]);

    $html = $this->get('/promo')->assertOk()->getContent();

    /**
     * The Look tab refuses every one of these with a message. This is the same
     * refusal made again as the Config is read, for the Config nobody typed
     * on that tab — silent, and falling back to the default rather than
     * rendering a page whose styling has been rewritten by its own data.
     */
    expect($html)->not->toContain('body {')
        ->and(styleBlock($html))->toContain('--gio-accent: '.StyleToken::Accent->defaultValue().';')
        ->and(styleBlock($html))->toContain('--gio-radius: '.StyleToken::Radius->defaultValue().';')
        ->and(styleBlock($html))->toContain('--gio-muted: '.StyleToken::Muted->defaultValue().';')
        /** A `var()` is refused like any other non-colour: a value naming another token is one the tab cannot show the effect of. */
        ->and(styleBlock($html))->toContain('--gio-surface: '.StyleToken::Surface->defaultValue().';')
        ->and(styleBlock($html))->toContain('--gio-font: '.StyleToken::Font->defaultValue().';');
});

it('renders a transparent surface and border when a merchant chooses them', function () {
    pageStyled(['style_tokens' => ['surface' => 'transparent', 'border' => 'transparent']]);

    $styles = styleBlock($this->get('/promo')->assertOk()->getContent());

    /**
     * Background already let a merchant type `transparent` to drop the ground
     * behind the card; surface and border take it on the same terms, because
     * the acceptance belongs to the colour kind and every colour token shares
     * it. Emitted as the tokens the flat rules already read — the card still
     * draws the border and the fill it always drew, so a browser that cannot
     * read the value falls back to the row it drew before, now unpainted. This
     * is what makes the card dissolve into a merchant's own wrapper.
     */
    expect($styles)->toContain('--gio-surface: transparent;')
        ->and($styles)->toContain('--gio-border: transparent;');

    preg_match('/\.gio-checkout-card \{([^}]*)\}/', $styles, $card);

    expect($card[1] ?? '')->toContain('border: 1px solid var(--gio-border)')
        ->and($card[1] ?? '')->toContain('background: var(--gio-surface)');
});

it('drops a surface or border that is not a colour and keeps the token refusing one', function () {
    pageStyled(['style_tokens' => ['surface' => 'reddish', 'border' => 'not a colour']]);

    $styles = styleBlock($this->get('/promo')->assertOk()->getContent());

    /**
     * Accepting `transparent` is not a licence for arbitrary values. A word no
     * browser reads as a colour would drop the declaration it lands in and
     * leave the merchant looking at a checkout that ignored what they typed, so
     * the same refusal that passes `transparent` refuses a non-colour — on the
     * Look tab with a message, and here silently, falling back to the default.
     */
    expect($styles)->toContain('--gio-surface: '.StyleToken::Surface->defaultValue().';')
        ->and($styles)->toContain('--gio-border: '.StyleToken::Border->defaultValue().';');

    expect(StyleToken::Surface->refusal('transparent'))->toBeNull()
        ->and(StyleToken::Border->refusal('transparent'))->toBeNull()
        ->and(StyleToken::Surface->refusal('reddish'))->not->toBeNull()
        ->and(StyleToken::Border->refusal('not a colour'))->not->toBeNull();
});

it('puts a whole-form failure in a banner at the top of the island', function (CheckoutLayout $layout) {
    pageStyled(['layout' => $layout->value]);

    $html = $this->get('/promo')->assertOk()->getContent();
    $markup = checkoutMarkup($html);
    $styles = styleBlock($html);

    preg_match('/<div class="gio-checkout-banner"[^>]*data-gio-banner[^>]*>(.*?)<\/div>/s', $markup, $banner);

    /**
     * Above the form and outside it, in every arrangement: the layouts all
     * arrange the form's children, and a banner that was one of them would
     * need a grid cell of its own in split and an order of its own in
     * summary-first. A child of the island instead sits above whatever the
     * layout draws, which is where a customer looks first when the button
     * they tapped did nothing.
     */
    expect($banner)->not->toBeEmpty()
        ->and(strpos($markup, 'data-gio-banner'))->toBeLessThan(strpos($markup, 'data-gio-checkout-form'))
        ->and($banner[1])->toContain('data-gio-error-for="page"')
        ->and($banner[1])->toContain('data-gio-reload')
        ->and($banner[1])->toContain('Reload page');

    /** Empty until the island writes to it, so a page that fails nothing draws nothing. */
    expect($styles)->toContain('.gio-checkout-banner { display: none;');

    preg_match('/\.gio-checkout-banner\[data-shown\] \{([^}]*)\}/', $styles, $shown);

    /**
     * The banner paints its own ground in the refusal red and writes on it in
     * white, so what it says is legible on a page styled dark without the
     * merchant having touched a colour — the one failure message that must
     * never be invisible is the one that turned the whole order away. The red
     * is not a token: what a refusal is written in is not a merchant's to
     * restyle.
     */
    expect($shown[1] ?? '')->toContain('background: #b91c1c')
        ->and($shown[1] ?? '')->toContain('color: #fff');

    /** And the button does what it says. */
    expect(checkoutScript($html))->toContain('location.reload()');
})->with(fn () => CheckoutLayout::cases());

it('puts a refusal about one answer beside that answer', function () {
    $page = pageStyled(['fields' => [
        ['key' => 'phone', 'label' => 'Phone number'],
        ['key' => 'address', 'label' => 'Address'],
    ]]);

    $markup = checkoutMarkup($this->get('/promo')->assertOk()->getContent());

    preg_match_all('/<div class="gio-checkout-field[^"]*"[^>]*>.*?data-gio-error-for="[^"]+"><\/p>\s*<\/div>/s', $markup, $wrappers);

    /**
     * Each question's slot is inside that question's own wrapper, after its
     * control, so the words land under the box they are about and nowhere
     * else. Read per wrapper rather than by presence: every slot present and
     * all of them at the bottom of the form would also pass a presence check.
     */
    foreach ($page->checkout_config->enabledFields() as $field) {
        $wrapper = array_values(array_filter(
            $wrappers[0],
            fn (string $wrapper): bool => str_contains($wrapper, 'name="'.$field->key().'"'),
        ));

        expect($wrapper)->toHaveCount(1)
            ->and($wrapper[0])->toContain('data-gio-error-for="'.$field->key().'"')
            ->and(strpos($wrapper[0], 'name="'.$field->key().'"'))
            ->toBeLessThan(strpos($wrapper[0], 'data-gio-error-for="'.$field->key().'"'));
    }
});

it('words each kind of failure for what actually went wrong', function () {
    pageStyled([]);

    $script = checkoutScript($this->get('/promo')->assertOk()->getContent());

    $wordings = [
        'connection lost' => 'We could not reach the server. Please check your connection and try again.',
        'too many attempts' => 'Too many attempts just now. Please wait a moment and try again.',
        'the server failed' => 'Something went wrong placing your order. Please try again.',
    ];

    /**
     * Three different problems, three different sentences: a customer whose
     * phone dropped off the network should not be told to try again in a
     * moment as though they had been sending too fast.
     */
    foreach ($wordings as $wording) {
        expect($script)->toContain($wording);
    }

    expect(array_unique($wordings))->toHaveCount(3);
});

it('fills every box a thumb has to hit with the surface token', function () {
    pageStyled(['product_picker' => true, 'delivery_options' => [['label' => 'Inside Dhaka', 'amount' => 6000]]]);

    $styles = styleBlock($this->get('/promo')->assertOk()->getContent());

    /**
     * The boxes a customer types into, the stepper, the product rows and the
     * delivery rows are the same kind of thing — something a thumb has to hit
     * — so one token fills all of them, the card they sit in included, and
     * the ground behind the card is the background token's: the design's grey
     * until a merchant says otherwise, or `transparent` to let the page show.
     */
    foreach (['.gio-checkout-input', '.gio-checkout-quantity-stepper', '.gio-checkout-product', '.gio-checkout-choice', '.gio-checkout-card'] as $selector) {
        preg_match('/'.preg_quote($selector, '/').' \{([^}]*)\}/', $styles, $rule);

        expect($rule[1] ?? '')->toContain('background: var(--gio-surface)');
    }

    preg_match('/\.gio-checkout \{([^}]*)\}/', $styles, $root);

    expect($root[1] ?? '')->toContain('background: var(--gio-background)')
        ->and($root[1] ?? '')->toContain('--gio-background: #f7f8fa;');
});

it('draws the ground on a page with a default container', function () {
    pageStyled([]);

    $styles = styleBlock($this->get('/promo')->assertOk()->getContent());

    preg_match('/\.gio-checkout \{([^}]*)\}/', $styles, $root);

    /**
     * The ground on is the checkout as designed, and the default for every
     * page: the design's outer padding and the background token behind the
     * card. A page that says nothing about its Container draws exactly the
     * bytes it always did, at every width — the phone narrows the ground it
     * still has.
     */
    expect($root[1] ?? '')->toContain('padding: 2.5rem 1.5rem;')
        ->and($root[1] ?? '')->toContain('background: var(--gio-background);')
        ->and($styles)->toContain('@media (max-width: 37.5rem) { .gio-checkout { padding: 1.25rem 1rem; }');
});

it('removes the ground and keeps the card when the container is off', function () {
    pageStyled(['container' => ['enabled' => false]]);

    $styles = styleBlock($this->get('/promo')->assertOk()->getContent());

    preg_match('/\.gio-checkout \{([^}]*)\}/', $styles, $root);

    /**
     * The ground off dissolves the outer box and nothing else: no grey band and
     * no padding around the card, so the checkout sits flush inside whatever the
     * merchant wrapped the shortcode in. The design's ground figures are gone
     * from the sheet entirely rather than overridden.
     */
    expect($root[1] ?? '')->toContain('padding: 0;')
        ->and($root[1] ?? '')->toContain('background: transparent;')
        ->and($root[1] ?? '')->not->toContain('padding: 2.5rem 1.5rem')
        ->and($root[1] ?? '')->not->toContain('background: var(--gio-background)');

    /** No ground to narrow on a phone, so the media query never touches the root — only the card. */
    expect($styles)->not->toContain('@media (max-width: 37.5rem) { .gio-checkout {')
        ->and($styles)->toContain('@media (max-width: 37.5rem) { .gio-checkout-card { padding: 1.25rem 1rem; }');

    /** The card is untouched: still the bordered panel at the design's width, filled from the surface token. */
    preg_match('/\.gio-checkout-card \{([^}]*)\}/', $styles, $card);

    expect($card[1] ?? '')->toContain('max-width: 35rem;')
        ->and($card[1] ?? '')->toContain('padding: 1.75rem;')
        ->and($card[1] ?? '')->toContain('border: 1px solid var(--gio-border)')
        ->and($card[1] ?? '')->toContain('background: var(--gio-surface)');
});

it('applies the merchant\'s own ground and card padding at every width', function () {
    pageStyled(['container' => [
        'enabled' => true,
        'ground' => ['vertical' => '3rem', 'horizontal' => '10%'],
        'card' => ['vertical' => '2rem', 'horizontal' => '1rem'],
    ]]);

    $styles = styleBlock($this->get('/promo')->assertOk()->getContent());

    preg_match('/\.gio-checkout \{([^}]*)\}/', $styles, $root);
    preg_match('/\.gio-checkout-card \{([^}]*)\}/', $styles, $card);

    /** Two lengths where the sides differ, written into each box's own padding shorthand. */
    expect($root[1] ?? '')->toContain('padding: 3rem 10%;')
        ->and($card[1] ?? '')->toContain('padding: 2rem 1rem;');

    /**
     * A merchant who set their own padding keeps it at every width: there is no
     * phone padding control, so the phone's tighter design figure would only
     * take away what they chose.
     */
    expect($styles)->not->toContain('.gio-checkout { padding: 1.25rem 1rem; }')
        ->and($styles)->not->toContain('.gio-checkout-card { padding: 1.25rem 1rem; }');
});

it('dissolves the card entirely for the flush recipe', function () {
    /** Container off, card padding 0, and the Surface and Border tokens transparent (#164). */
    pageStyled([
        'container' => [
            'enabled' => false,
            'card' => ['vertical' => '0', 'horizontal' => '0'],
        ],
        'style_tokens' => ['surface' => 'transparent', 'border' => 'transparent'],
    ]);

    $styles = styleBlock($this->get('/promo')->assertOk()->getContent());

    preg_match('/\.gio-checkout \{([^}]*)\}/', $styles, $root);
    preg_match('/\.gio-checkout-card \{([^}]*)\}/', $styles, $card);

    /**
     * The four settings together leave nothing a Customer can see: no ground,
     * and a card that is a transparent border around a transparent fill with no
     * padding, so the checkout dissolves into whatever the merchant wrapped the
     * shortcode in.
     */
    expect($root[1] ?? '')->toContain('padding: 0;')
        ->and($root[1] ?? '')->toContain('background: transparent;')
        ->and($root[1] ?? '')->toContain('--gio-surface: transparent;')
        ->and($root[1] ?? '')->toContain('--gio-border: transparent;');

    expect($card[1] ?? '')->toContain('padding: 0;')
        ->and($card[1] ?? '')->toContain('border: 1px solid var(--gio-border)')
        ->and($card[1] ?? '')->toContain('background: var(--gio-surface)');

    /** No phone rule re-pads either box, so the card stays dissolved at every width. */
    expect($styles)->not->toContain('.gio-checkout { padding: 1.25rem 1rem; }')
        ->and($styles)->not->toContain('.gio-checkout-card { padding: 1.25rem 1rem; }');
});

it('sizes and places the card at the merchant\'s width and alignment', function (string $alignment, string $margin) {
    pageStyled(['container' => [
        'width' => '48rem',
        'alignment' => $alignment,
    ]]);

    $styles = styleBlock($this->get('/promo')->assertOk()->getContent());

    preg_match('/\.gio-checkout-card \{([^}]*)\}/', $styles, $card);

    /**
     * The width is written into the card's `max-width`, and the alignment as an
     * auto margin on the side with the slack — left keeps the card against the
     * start, right against the end, and centre is the `0 auto` the card has
     * always carried. Both apply with the ground on or off.
     */
    expect($card[1] ?? '')->toContain('max-width: 48rem;')
        ->and($card[1] ?? '')->toContain('margin: '.$margin.';');
})->with([
    'left' => ['left', '0 auto 0 0'],
    'centre' => ['center', '0 auto'],
    'right' => ['right', '0 0 0 auto'],
]);

it('fills the parent at 100% width, where the alignments coincide', function () {
    pageStyled(['container' => [
        'width' => '100%',
        'alignment' => 'right',
    ]]);

    $styles = styleBlock($this->get('/promo')->assertOk()->getContent());

    preg_match('/\.gio-checkout-card \{([^}]*)\}/', $styles, $card);

    /**
     * A card as wide as its parent has no slack for an auto margin to take up,
     * so the alignment is inert — the card fills the wrapper whichever way it is
     * placed. The chosen margin is still written; it simply has nothing to do.
     */
    expect($card[1] ?? '')->toContain('max-width: 100%;')
        ->and($card[1] ?? '')->toContain('margin: 0 0 0 auto;');
});

it('sizes and places the card even with the ground dissolved', function () {
    pageStyled(['container' => [
        'enabled' => false,
        'width' => '40rem',
        'alignment' => 'left',
    ]]);

    $styles = styleBlock($this->get('/promo')->assertOk()->getContent());

    preg_match('/\.gio-checkout \{([^}]*)\}/', $styles, $root);
    preg_match('/\.gio-checkout-card \{([^}]*)\}/', $styles, $card);

    /**
     * Width and alignment sit on the card rule, which is emitted whether the
     * ground is drawn or not — so a Merchant who dissolved the ground can still
     * narrow the card and place it against the start of their own wrapper. The
     * ground itself is gone.
     */
    expect($root[1] ?? '')->toContain('padding: 0;')
        ->and($card[1] ?? '')->toContain('max-width: 40rem;')
        ->and($card[1] ?? '')->toContain('margin: 0 auto 0 0;');
});

it('writes the quieter words in the muted token', function () {
    pageStyled([]);

    $styles = styleBlock($this->get('/promo')->assertOk()->getContent());

    /**
     * Help under a question, a product's description and the line beneath the
     * button recede by colour rather than by opacity, so a merchant on a dark
     * page can make them readable in one place instead of watching them fade
     * into whatever is behind them.
     */
    foreach (['.gio-checkout-help', '.gio-checkout-description', '.gio-checkout-note'] as $selector) {
        preg_match('/'.preg_quote($selector, '/').' \{([^}]*)\}/', $styles, $rule);

        expect($rule[1] ?? '')->toContain('color: var(--gio-muted)')
            ->and($rule[1] ?? '')->not->toContain('opacity');
    }
});

it('clamps a product description to two lines and leaves the name wrapping free', function () {
    pageStyled([]);

    $styles = styleBlock($this->get('/promo')->assertOk()->getContent());

    /**
     * A long description clamps to two lines with an ellipsis so it cannot blow
     * out the row on a narrow phone, at every width — it is in the base sheet,
     * not behind a media query. The name is the product's identity and is never
     * truncated: a customer must always be able to tell which product a row is.
     */
    preg_match('/\.gio-checkout-description \{([^}]*)\}/', $styles, $description);
    preg_match('/\.gio-checkout-name \{([^}]*)\}/', $styles, $name);

    expect($description[1] ?? '')->toContain('-webkit-line-clamp: 2')
        ->and($description[1] ?? '')->toContain('-webkit-box-orient: vertical')
        ->and($description[1] ?? '')->toContain('display: -webkit-box')
        ->and($description[1] ?? '')->toContain('overflow: hidden')
        ->and($name[1] ?? '')->not->toContain('line-clamp')
        ->and($name[1] ?? '')->not->toContain('-webkit-box');
});

it('draws the quantity stepper narrow rather than the width of the form', function () {
    pageStyled([]);

    $styles = styleBlock($this->get('/promo')->getContent());

    /**
     * The stepper carries both classes and both selectors are one class, so the
     * later declaration is the one that applies. Order is the only thing
     * holding this up — raising the specificity of either would be the escape
     * hatch a merchant's own stylesheet needs, spent on ourselves.
     */
    expect(strpos($styles, '.gio-checkout-quantity-input {'))
        ->toBeGreaterThan(strpos($styles, '.gio-checkout-input {'));
});

/*
|--------------------------------------------------------------------------
| The hairline island
|--------------------------------------------------------------------------
|
| No outer panel, sections set apart by rules, and a border on the things a
| customer touches: the boxes they type in and the product rows they pick.
| Everything else is whitespace, because the island sits inside a page the
| merchant already designed and a box of its own would double-frame it.
|
*/

it('draws the panel on the card and never on the root', function () {
    pageStyled([]);

    $styles = styleBlock($this->get('/promo')->assertOk()->getContent());

    preg_match('/\.gio-checkout \{([^}]*)\}/', $styles, $root);
    preg_match('/\.gio-checkout-card \{([^}]*)\}/', $styles, $card);

    /**
     * The root is the section the design paints behind the card and asserts
     * no box of its own: no border, no radius. The card is the one bordered
     * panel, square-cornered as the design draws it, and everything a
     * customer reads sits inside it. A merchant who wants the root boxed
     * adds it in their own stylesheet, which is what the flat single-class
     * sheet exists to allow.
     */
    expect($root[1] ?? '')->not->toMatch('/(^|;)\s*border:/')
        ->and($root[1] ?? '')->not->toContain('border-radius:')
        ->and($card[1] ?? '')->toContain('border: 1px solid var(--gio-border)')
        ->and($card[1] ?? '')->not->toContain('border-radius:');
});

it('borders each product row and rules off the sections around it', function () {
    pageStyled([]);

    $styles = styleBlock($this->get('/promo')->assertOk()->getContent());

    preg_match('/\.gio-checkout-product \{([^}]*)\}/', $styles, $row);
    preg_match('/\.gio-checkout-products \{([^}]*)\}/', $styles, $panel);
    preg_match('/\.gio-checkout-summary \{([^}]*)\}/', $styles, $summary);

    /**
     * A product row is a thing being bought, and it is boxed like the boxes a
     * customer types into: the same border token, the same width, so one
     * redeclaration restyles all of them together. Square, as the design
     * draws every row; the radius token is for the boxes, the stepper and the
     * pictures.
     */
    expect($row[1] ?? '')->toContain('border: 1px solid var(--gio-border)')
        ->and($row[1] ?? '')->not->toContain('border-radius');

    /**
     * One rule in the whole card, above the totals, read from the border
     * token; the sections above it are set apart by whitespace, and the
     * product panel draws no rule of its own beneath.
     */
    expect($panel[1] ?? '')->not->toContain('border')
        ->and($summary[1] ?? '')->toContain('border-top: 1px solid var(--gio-border)');
});

it('flanks a real numeric quantity with a minus and a plus', function () {
    $page = pageStyled([]);
    $product = $page->refresh()->products->first();

    $html = $this->get('/promo')->assertOk()->getContent();
    $markup = checkoutMarkup($html);

    preg_match('/<div class="gio-checkout-quantity-stepper">(.*?)<\/div>/s', $markup, $stepper);

    expect($stepper)->not->toBeEmpty();

    /**
     * Minus, the box, plus — in that order, so a thumb finds them where a cart
     * puts them. The buttons are `type="button"`: a button inside a form
     * submits it unless told otherwise, and a customer tapping plus must never
     * place an order.
     */
    expect($stepper[1])->toMatch('/<button class="gio-checkout-quantity-step" type="button"[^>]*data-gio-step="-1".*<input[^>]*data-gio-quantity.*<button class="gio-checkout-quantity-step" type="button"[^>]*data-gio-step="1"/s');

    /**
     * What the buttons flank is the same numeric input the form has always
     * posted, with its bounds intact. Those bounds are what keep the browser
     * and the capture agreed on the maximum quantity — a readout that could
     * not be typed into was rejected for exactly that reason — so a customer
     * can still type twelve rather than tapping plus eleven times, and a
     * customer whose script never runs still has a working box.
     */
    preg_match('/<input[^>]*data-gio-quantity[^>]*>/', $stepper[1], $input);

    expect($input[0] ?? '')->toContain('type="number"')
        ->and($input[0] ?? '')->toContain('name="quantities['.$product->getKey().']"')
        ->and($input[0] ?? '')->toContain('min="1"')
        ->and($input[0] ?? '')->toContain('max="'.config('giopages.checkout.max_quantity').'"')
        ->and($input[0] ?? '')->toContain('step="1"')
        ->and($input[0] ?? '')->not->toContain('readonly');

    /**
     * The script upgrades the buttons and nothing else: it steps the input by
     * the input's own step, so the bounds the markup declares are the bounds
     * the buttons honour, and a rule is never written twice.
     */
    expect(checkoutScript($html))->toContain('[data-gio-step]')
        ->and(checkoutScript($html))->toContain('stepUp()')
        ->and(checkoutScript($html))->toContain('stepDown()');
});

/*
|--------------------------------------------------------------------------
| Delivery rows and the summary
|--------------------------------------------------------------------------
|
| Choosing a delivery arrangement is the same gesture as picking a product — a
| priced thing a thumb has to hit — so it is drawn the same way: a bordered row
| with the control and its label on the left and the price on the right. The
| summary beneath is a line list with a rule above an emphasised total, so a
| customer can see what the courier will collect before they order.
|
*/

it('draws each delivery option as a row with the control and label left and the price right', function () {
    pageStyled([
        'delivery_options' => [
            ['label' => 'Inside Dhaka', 'amount' => 6000],
            ['label' => 'Outside Dhaka', 'amount' => 12000],
        ],
    ]);

    $html = $this->get('/promo')->assertOk()->getContent();
    $styles = styleBlock($html);

    preg_match('/<fieldset class="gio-checkout-delivery">(.*?)<\/fieldset>/s', checkoutMarkup($html), $delivery);
    preg_match_all('/<label class="gio-checkout-choice[^"]*">(.*?)<\/label>/s', $delivery[1] ?? '', $rows);

    expect($rows[1])->toHaveCount(2);

    /**
     * Within one row the control comes first, then the words, then the figure
     * — which is reading order, and so also the order a screen reader hears
     * them in. The price is inside the label, so tapping it picks the row too.
     */
    foreach ([['Inside Dhaka', 6000], ['Outside Dhaka', 12000]] as $index => [$label, $amount]) {
        expect($rows[1][$index])->toMatch(
            '/type="radio".*gio-checkout-choice-label">'.preg_quote($label, '/').'<.*gio-checkout-choice-amount">'.preg_quote(Money::format($amount), '/').'</s'
        );
    }

    preg_match('/\.gio-checkout-choice \{([^}]*)\}/', $styles, $row);
    preg_match('/\.gio-checkout-choice-label \{([^}]*)\}/', $styles, $words);
    preg_match('/\.gio-checkout-choice-amount \{([^}]*)\}/', $styles, $price);

    /**
     * Boxed like a product row and like the boxes a customer types into: the
     * same border token and width, and square like every row the design
     * draws. The words take what is left of the row so the figure sits at the
     * far end, and the figure never wraps — a price broken across two lines
     * is a price misread.
     */
    expect($row[1] ?? '')->toContain('border: 1px solid var(--gio-border)')
        ->and($row[1] ?? '')->not->toContain('border-radius')
        ->and($words[1] ?? '')->toContain('flex: 1')
        ->and($words[1] ?? '')->toContain('min-width: 0')
        ->and($price[1] ?? '')->toContain('white-space: nowrap');
});

it('draws a delivery option\'s detail line under its name, and nothing where there is none', function () {
    pageStyled([
        'delivery_options' => [
            ['label' => 'Inside Dhaka', 'amount' => 6000, 'detail' => '1–2 days'],
            ['label' => 'Outside Dhaka', 'amount' => 12000],
        ],
    ]);

    $html = $this->get('/promo')->assertOk()->getContent();

    preg_match('/<fieldset class="gio-checkout-delivery">(.*?)<\/fieldset>/s', checkoutMarkup($html), $delivery);
    preg_match_all('/<label class="gio-checkout-choice[^"]*">(.*?)<\/label>/s', $delivery[1] ?? '', $rows);

    /**
     * Beneath the name and inside the words' span, so it takes the row's
     * reading order — name, then detail, then the figure — and tapping it
     * still picks the row. A row with no detail draws no empty line for one.
     */
    expect($rows[1])->toHaveCount(2)
        ->and($rows[1][0])->toMatch('/gio-checkout-choice-label">Inside Dhaka<span class="gio-checkout-choice-detail">1–2 days<\/span><\/span>.*gio-checkout-choice-amount"/s')
        ->and($rows[1][1])->toContain('gio-checkout-choice-label">Outside Dhaka</span>')
        ->and($rows[1][1])->not->toContain('gio-checkout-choice-detail');

    preg_match('/\.gio-checkout-choice-detail \{([^}]*)\}/', styleBlock($html), $detail);

    /** On its own line beneath the name, smaller, and receding in the muted token like every other help line. */
    expect($detail[1] ?? '')->toContain('display: block')
        ->and($detail[1] ?? '')->toContain('color: var(--gio-muted)');
});

it('marks the chosen delivery row apart from the others', function () {
    pageStyled([
        'delivery_options' => [
            ['label' => 'Inside Dhaka', 'amount' => 6000],
            ['label' => 'Outside Dhaka', 'amount' => 12000],
        ],
    ]);

    $html = $this->get('/promo')->assertOk()->getContent();

    preg_match('/\.gio-checkout-choice:has\(:checked\) \{([^}]*)\}/', styleBlock($html), $chosen);

    /**
     * The row that is picked reads as picked from across the room, not only
     * from the dot inside its radio: its border takes the accent, as the
     * design draws a chosen option, and nothing heavier — no ring, no shadow.
     * Where a browser cannot read `:has()` the radio still shows, so nothing
     * is lost that was there before.
     */
    expect($chosen[1] ?? '')->toContain('border-color: var(--gio-accent)')
        ->and($chosen[1] ?? '')->not->toContain('box-shadow');

    preg_match_all('/<input[^>]*type="radio"[^>]*name="delivery_option"[^>]*>/s', checkoutMarkup($html), $radios);

    expect($radios[0])->toHaveCount(2)
        ->and($radios[0][0])->toContain('checked')
        ->and($radios[0][1])->not->toContain('checked');
});

it('marks a picked product with the same token as a chosen delivery row', function () {
    pageStyled(['product_picker' => true]);

    $styles = styleBlock($this->get('/promo')->assertOk()->getContent());

    preg_match('/\.gio-checkout-product:has\(:checked\) \{([^}]*)\}/', $styles, $picked);
    preg_match('/\.gio-checkout \{([^}]*)\}/', $styles, $root);
    preg_match('/\.gio-checkout-field \{([^}]*)\}/', $styles, $field);

    /**
     * On a cash-on-delivery page an item nobody meant to choose is a refused
     * parcel and courier fees both ways, so a customer must be able to tell
     * at a glance what they have committed to. The accent paints the whole of
     * the selected state — the tick, the row's border and a tint of its
     * ground mixed from the accent and the surface — for a product as for a
     * delivery row, so a merchant changes what "picked" looks like in one
     * place. No ring and no shadow, as the design draws it. The tick is
     * `accent-color` on the root, and nothing below it redeclares the token.
     */
    expect($picked[1] ?? '')->toContain('border-color: var(--gio-accent)')
        ->and($picked[1] ?? '')->not->toContain('box-shadow')
        ->and($picked[1] ?? '')->toMatch('/background: color-mix\([^;]*var\(--gio-accent\)[^;]*var\(--gio-surface\)\)/')
        ->and($root[1] ?? '')->toContain('accent-color: var(--gio-accent)')
        ->and($field[1] ?? '')->not->toContain('accent-color');
});

it('lists the summary line by line with a rule above an emphasised total', function () {
    pageStyled([
        'delivery_options' => [['label' => 'Inside Dhaka', 'amount' => 6000]],
    ]);

    $html = $this->get('/promo')->assertOk()->getContent();
    $styles = styleBlock($html);

    preg_match('/<div class="gio-checkout-summary">(.*?)<\/div>/s', checkoutMarkup($html), $summary);
    preg_match_all('/class="gio-checkout-row[^"]*"/', $summary[1] ?? '', $rows);

    /**
     * Subtotal, then the delivery charge, then the total, each a row with the
     * words left and the figure right — a receipt a customer can read down to
     * what the courier will collect. The arrangement's own name is on the
     * radio above, where it is chosen, as the designs draw it.
     */
    expect($rows[0])->toBe([
        'class="gio-checkout-row"',
        'class="gio-checkout-row"',
        'class="gio-checkout-row gio-checkout-total"',
    ])->and($summary[1] ?? '')->toMatch('/Subtotal.*Delivery.*Total/s')
        ->and($summary[1] ?? '')->not->toContain('Inside Dhaka');

    preg_match('/\.gio-checkout-total \{([^}]*)\}/', $styles, $total);

    /**
     * The total is ruled off from the lines above it with the same hairline
     * the sections are, and set heavier than they are. Its rule sits after
     * the row's in the sheet, at one class each, so it narrows by order — the
     * same trade the whole sheet makes.
     */
    expect($total[1] ?? '')->toContain('border-top: 1px solid var(--gio-border)')
        ->and($total[1] ?? '')->toContain('font-weight: 700')
        ->and(strpos($styles, '.gio-checkout-total {'))->toBeGreaterThan(strpos($styles, '.gio-checkout-row {'));
});

/*
|--------------------------------------------------------------------------
| Layouts and width
|--------------------------------------------------------------------------
|
| How the Checkout is arranged and how wide it sits. Every layout drawn from one
| stylesheet plus the block belonging to the one chosen, questions that pair up
| on a wide screen and never on a phone — nor anywhere on a compact page — and a
| width that is a token rather than a layout of its own.
|
*/

/**
 * The island's markup, without the stylesheet and the script it ships beside
 * it.
 *
 * Both of those name every class and every data attribute the markup can carry,
 * so a test asking whether something was *drawn* has to read the markup alone —
 * otherwise it passes on a page that draws nothing at all.
 */
function checkoutMarkup(string $html): string
{
    return (string) preg_replace('/<(style|script)>.*?<\/\1>/s', '', $html);
}

it('draws the products while the product list is shown and omits the panel while it is hidden', function () {
    pageStyled([], slug: 'stacked');
    pageStyled(['show_products' => false], slug: 'bare');

    $stacked = checkoutMarkup($this->get('/stacked')->assertOk()->getContent());
    $bare = checkoutMarkup($this->get('/bare')->assertOk()->getContent());

    /**
     * A page hiding its product list sold the product in its own markup above
     * the fold, so the panel is gone entirely rather than hidden: nothing is
     * drawn, no picture is fetched, and none of those bytes are written into
     * the cached page.
     */
    expect($stacked)->toContain('class="gio-checkout-products"')
        ->and($bare)->not->toContain('class="gio-checkout-products"')
        ->and($bare)->not->toContain('gio-checkout-product-head')
        ->and($bare)->not->toContain('data-gio-quantity');
});

it('hides the product list on any layout, and draws everything else the layout draws', function (CheckoutLayout $layout) {
    $page = pageStyled(['layout' => $layout->value, 'show_products' => false]);

    $html = $this->get('/promo')->assertOk()->getContent();

    /**
     * The switch is the merchant's and the layout is the merchant's, and one
     * says nothing about the other: hiding the list is a thing any arrangement
     * can do, so the arrangement keeps its class and its rules and loses only
     * the panel.
     */
    expect($html)->toContain('class="gio-checkout '.$layout->modifierClass().'"')
        ->and(checkoutMarkup($html))->not->toContain('class="gio-checkout-products"')
        ->and($html)->toContain('name="quantities['.$page->products->first()->getKey().']" value="1"')
        ->and($html)->toContain('data-gio-subtotal')
        ->and($html)->toContain('data-gio-error-for="page"');
})->with(fn () => CheckoutLayout::cases());

it('reads a page stored under the retired form-only layout as stacked with its product list hidden, and draws it as it always drew', function () {
    $product = Product::factory()->create(['name' => 'Winter Jacket']);

    $retired = Page::factory()->published()->withBody('<main>[checkout]</main>')->create([
        'slug' => 'retired',
        'checkout_config' => ['layout' => 'form_only'],
    ]);
    $retired->products()->attach($product);

    $switched = Page::factory()->published()->withBody('<main>[checkout]</main>')->create([
        'slug' => 'switched',
        'checkout_config' => ['layout' => 'stacked', 'show_products' => false],
    ]);
    $switched->products()->attach($product);

    /**
     * Nothing published changes on upgrade. A page saved under the old layout
     * is the same Config as one saved with the switch off, and the bytes a
     * visitor is served are the same bytes — the same product sold, the same
     * panel omitted, the same class on the root — with only the slug telling
     * them apart.
     */
    $config = $retired->refresh()->checkout_config;

    expect($config->layout)->toBe(CheckoutLayout::Stacked)
        ->and($config->showProducts)->toBeFalse()
        ->and($config->toArray()['layout'])->toBe('stacked');

    $retiredHtml = $this->get('/retired')->assertOk()->getContent();
    $switchedHtml = $this->get('/switched')->assertOk()->getContent();

    $slugAppearsIn = [route('landing.show', 'retired'), 'value="retired"', 'data-page-slug="retired"'];
    $andReadsAs = [route('landing.show', 'switched'), 'value="switched"', 'data-page-slug="switched"'];

    expect(str_replace($slugAppearsIn, $andReadsAs, $retiredHtml))->toBe($switchedHtml)
        ->and($retiredHtml)->not->toContain('Winter Jacket')
        ->and($retiredHtml)->toContain('gio-checkout-stacked')
        ->and($retiredHtml)->toContain('name="quantities['.$product->getKey().']" value="1"');
});

it('reads the names the checkout first stored its layouts under as the ten it draws now', function (string $stored, CheckoutLayout $layout) {
    pageStyled(['layout' => $stored]);

    $page = Page::where('slug', 'promo')->sole();

    /**
     * A Page published under one of the first layouts keeps working after the
     * upgrade: the old name is read as the one of the ten nearest to what it
     * drew, the root names that one, the first save writes the new name, and
     * the old name is drawn nowhere.
     */
    $html = $this->get('/promo')->assertOk()->getContent();

    expect($page->checkout_config->layout)->toBe($layout)
        ->and($page->checkout_config->toArray()['layout'])->toBe($layout->value)
        ->and($html)->toContain('class="gio-checkout '.$layout->modifierClass().'"')
        ->and($html)->not->toContain('gio-checkout-summary-first')
        ->and($html)->not->toContain('class="gio-checkout gio-checkout-compact"');
})->with([
    'stacked' => ['stacked', CheckoutLayout::Stacked],
    'split' => ['split', CheckoutLayout::Split],
    'compact, now compact embed' => ['compact', CheckoutLayout::CompactEmbed],
    'summary first, now split reversed' => ['summary_first', CheckoutLayout::SplitReversed],
]);

it('posts one of everything a checkout with its product list hidden sells', function () {
    $page = pageStyled(['show_products' => false]);
    $page->products()->attach(Product::factory()->create(['price' => 25000]));

    $html = $this->get('/promo')->assertOk()->getContent();

    /**
     * Every product the page sells, once each, as the capture will read them:
     * the markup a customer never sees is still the markup an Order is made
     * from, so the whole shelf has to be on it.
     */
    foreach ($page->refresh()->products as $product) {
        expect($html)->toContain('<input type="hidden" name="quantities['.$product->getKey().']" value="1"');
    }
});

it('totals a checkout with its product list hidden off the same line markers as one showing it', function () {
    $page = pageStyled(['show_products' => false]);
    $product = $page->refresh()->products->first();

    $html = $this->get('/promo')->assertOk()->getContent();

    /**
     * The hidden inputs carry the line markers, so the summary below totals the
     * same way in every layout rather than the script needing to know which
     * one it is looking at — and the rendered subtotal is already the price of
     * everything sold.
     */
    expect($html)->toContain('data-gio-line data-product-id="'.$product->getKey().'" data-unit-price="'.$product->price.'"')
        ->and($html)->toContain('<span data-gio-subtotal>'.Money::format($product->price).'</span>');
});

it('draws every question, the delivery choice, the summary and the error slots in every layout', function (CheckoutLayout $layout) {
    $page = pageStyled([
        'layout' => $layout->value,
        'fields' => [
            ['key' => 'name', 'label' => 'Your name'],
            ['key' => 'phone', 'label' => 'Phone number'],
            ['key' => 'address', 'label' => 'Address'],
            ['key' => 'floor', 'label' => 'Floor', 'type' => 'number'],
            ['key' => 'district', 'label' => 'District'],
            ['key' => 'wrapping', 'label' => 'Wrapping', 'type' => 'radio', 'options' => [['value' => 'paper', 'label' => 'Brown paper']]],
            ['key' => 'terms', 'label' => 'I agree', 'type' => 'checkbox'],
        ],
        'delivery_options' => [
            ['label' => 'Inside Dhaka', 'amount' => 6000],
            ['label' => 'Outside Dhaka', 'amount' => 12000],
        ],
    ]);

    $html = $this->get('/promo')->assertOk()->getContent();

    /**
     * A layout decides what is drawn around the form and nothing about the form
     * itself. Adding one must not quietly cost a page its radio buttons or its
     * delivery prices — the failure a second template would invite, and which
     * one loop for every layout is what prevents.
     */
    expect($html)->toContain('type="tel"')
        ->and($html)->toContain('<textarea')
        ->and($html)->toContain('type="number"')
        ->and($html)->toContain('<select')
        ->and($html)->toContain('type="radio"')
        ->and($html)->toContain('type="checkbox"')
        ->and($html)->toContain('Inside Dhaka')
        ->and($html)->toContain('data-gio-delivery-option')
        ->and($html)->toContain('data-gio-subtotal')
        ->and($html)->toContain('data-gio-total')
        ->and($html)->toContain('data-gio-error-for="delivery_option"')
        ->and($html)->toContain('data-gio-error-for="page"');

    foreach ($page->checkout_config->enabledFields() as $field) {
        expect($html)->toContain('data-gio-error-for="'.$field->key().'"');
    }
})->with(fn () => CheckoutLayout::cases());

it('writes the document in the same order in every layout', function (CheckoutLayout $layout) {
    $page = pageStyled([
        'layout' => $layout->value,
        'fields' => [['key' => 'phone', 'label' => 'Phone number']],
        'delivery_options' => [
            ['label' => 'Inside Dhaka', 'amount' => 6000],
            ['label' => 'Outside Dhaka', 'amount' => 12000],
        ],
        'hide_lone_cash_choice' => false,
    ]);

    $product = $page->refresh()->products->first();

    $markup = checkoutMarkup($this->get('/promo')->assertOk()->getContent());

    /**
     * The same island drawn by the same loop, and the bytes in the same order
     * whichever arrangement was chosen: the products, the questions, the
     * delivery choice, the payment choice, the summary, then the button. What
     * a layout moves, it moves in the sheet — a second template would be a
     * second total to keep truthful about a form that takes money, and the
     * order the bytes are in is the order a customer whose stylesheet never
     * arrives, or who is listening rather than looking, reads them in
     * (ADR-0015).
     */
    $landmarks = [
        'class="gio-checkout-products"',
        $product->name,
        'name="phone"',
        'name="delivery_option"',
        'name="payment_method"',
        'class="gio-checkout-summary"',
        'data-gio-submit',
    ];

    $positions = array_map(fn (string $landmark): int => (int) strpos($markup, $landmark), $landmarks);

    foreach ($landmarks as $landmark) {
        expect($markup)->toContain($landmark);
    }

    $sorted = $positions;
    sort($sorted);

    expect($positions)->toBe($sorted);
})->with(fn () => CheckoutLayout::cases());

/**
 * The slug a page arranged in one layout is published at.
 *
 * Hyphenated, because a stored layout is snake_case and a slug is one segment
 * of a URL.
 */
function layoutSlug(CheckoutLayout $layout): string
{
    return str_replace('_', '-', $layout->value);
}

/**
 * The sheet a page arranged in one layout serves, published and fetched.
 */
function emittedStylesFor(CheckoutLayout $layout): string
{
    pageStyled(['layout' => $layout->value], slug: layoutSlug($layout));

    return styleBlock(test()->get('/'.layoutSlug($layout))->assertOk()->getContent());
}

/**
 * What every arrangement ships in common: the emitted sheet with the block the
 * chosen layout adds to the end of it taken back out.
 *
 * The last occurrence and only that one, because it is position rather than
 * text that says which bytes belong to the layout. A block repeating a
 * declaration the base already makes would otherwise be cut out of both, and
 * two arrangements would then agree about a base neither of them ships.
 */
function baseSheet(string $styles, CheckoutLayout $layout): string
{
    $rules = $layout->styleRules();

    if ($rules === '') {
        return $styles;
    }

    $at = strrpos($styles, $rules);

    expect($at)->not->toBeFalse();

    return substr_replace($styles, '', (int) $at, strlen($rules));
}

it('ships the same base sheet whichever layout the page is arranged in', function () {
    $bases = [];

    foreach (CheckoutLayout::cases() as $layout) {
        $bases[layoutSlug($layout)] = baseSheet(emittedStylesFor($layout), $layout);
    }

    /**
     * Byte for byte, and the whole of what "one sheet plus a modifier" means
     * now that an arrangement may add rules: what differs between two pages is
     * the block belonging to the layout each one draws, and nothing else. A
     * layout carrying a sheet of its own would charge every visitor for the
     * arrangements their page is not using.
     *
     * Pinned as a real sheet and not merely as agreement, because two pages
     * emitting no styles at all would agree just as well.
     */
    expect(array_unique($bases))->toHaveCount(1);

    foreach ($bases as $base) {
        expect($base)->toContain('.gio-checkout {')
            ->and($base)->toContain('.gio-checkout-card {')
            ->and($base)->toContain('.gio-checkout-input {');
    }
});

it('ships no other layout\'s rules to a page that does not draw them', function () {
    foreach (CheckoutLayout::cases() as $layout) {
        $styles = emittedStylesFor($layout);

        /**
         * The other half of the replaced byte-identity assertion, and the half
         * that makes adding an arrangement cost only the pages that choose it.
         * Rules are pinned through the modifier class as well as through their
         * own text, because an arrangement whose rules did not name its own
         * class would be styling every page that does not draw it.
         *
         * The guard is on the negative half alone: every string contains the
         * empty one, so an arrangement adding nothing has nothing to look for.
         * The positive half is left unguarded so that it starts holding the
         * first block to an appearance the day one is written. A class is
         * matched as a whole word, because one layout's class is the start of
         * another's (`split`, `split-reversed`).
         */
        foreach (CheckoutLayout::cases() as $other) {
            if ($other === $layout || $other->styleRules() === '') {
                continue;
            }

            expect($styles)->not->toContain($other->styleRules());
        }

        foreach (CheckoutLayout::cases() as $other) {
            if ($other !== $layout) {
                expect($styles)->not->toMatch('/\.'.preg_quote($other->modifierClass(), '/').'(?![a-z-])/');
            }
        }

        expect($styles)->toContain($layout->styleRules());
    }
});

it('writes the chosen layout\'s rules into the one sheet, last', function () {
    $view = (string) file_get_contents(resource_path('views/shortcodes/checkout.blade.php'));

    $emission = strpos($view, '{!! $layout->styleRules() !!}');

    /**
     * The one part of this that cannot be read out of a served page while no
     * arrangement adds anything: with every block empty, a page that emits the
     * chosen layout's rules and a page that has dropped the line are the same
     * bytes. So the call is pinned where it is written until the first
     * arrangement with rules of its own pins it in the output.
     *
     * Its position is pinned with it. Last inside the sheet is what lets a
     * layout narrow a base rule by order rather than by specificity, and
     * inside the one `<style>` is the difference between a block a layout adds
     * and the sheet of its own it may never carry.
     */
    expect($emission)->not->toBeFalse()
        ->and($emission)->toBeGreaterThan(strpos($view, '.gio-checkout-note {'))
        ->and($emission)->toBeLessThan(strpos($view, '</style>'))
        ->and(substr_count($view, '<style>'))->toBe(1);
});

it('ends the served sheet with the chosen layout\'s block, after the base', function (CheckoutLayout $layout) {
    $styles = emittedStylesFor($layout);

    /**
     * The half of "last inside the one sheet" that is read out of a served
     * page rather than the template: whatever the arrangement adds is the
     * tail of the sheet, after the base's last rule. A block emitted before
     * the base would leave a page drawn at the spacing it was arranged out
     * of, with nothing failing. Trivially true of an arrangement adding
     * nothing, and holding for each of the nine the day its block is written.
     */
    expect(str_ends_with(trim($styles), trim($layout->styleRules())))->toBeTrue()
        ->and(strrpos($styles, '.gio-checkout-note {'))->toBeGreaterThan(strpos($styles, '.gio-checkout {'));
})->with(fn () => CheckoutLayout::cases());

it('generates no selector for any layout', function () {
    $many = array_map(fn (int $index): array => [
        'key' => 'question_'.$index,
        'label' => 'Question '.$index,
        'style_tokens' => ['border' => '#ff0000', 'radius' => '2px'],
    ], range(1, 12));

    foreach (CheckoutLayout::cases() as $layout) {
        pageStyled(['layout' => $layout->value], slug: 'plain-'.layoutSlug($layout));

        pageStyled([
            'layout' => $layout->value,
            'fields' => $many,
        ], slug: 'busy-'.layoutSlug($layout));

        /**
         * Byte for byte within an arrangement, which is where the no-generated-
         * selector promise moved to when the sheet stopped being identical
         * across arrangements. A layout's block is picked from a fixed list and
         * never built from a merchant's figures, so asking twelve more
         * questions and restyling each of them leaves the sheet exactly where
         * it was — and leaves a merchant's own stylesheet the same thing to
         * beat, in every arrangement rather than only in the stacked one.
         */
        expect(styleBlock($this->get('/busy-'.layoutSlug($layout))->assertOk()->getContent()))
            ->toBe(styleBlock($this->get('/plain-'.layoutSlug($layout))->assertOk()->getContent()));
    }
});

it('names the layout on the checkout root in every layout', function (string $layout, string $class) {
    pageStyled(['layout' => $layout]);

    /**
     * A modifier beside the base class, always emitted, so a merchant writing
     * their own CSS has a stable thing to hang it on and a layout added later
     * is a rule rather than a copy. The ten, in the design's order.
     */
    expect($this->get('/promo')->getContent())->toContain('class="gio-checkout '.$class.'"');
})->with([
    'stacked' => ['stacked', 'gio-checkout-stacked'],
    'split' => ['split', 'gio-checkout-split'],
    'split reversed' => ['split_reversed', 'gio-checkout-split-reversed'],
    'stepper' => ['stepper', 'gio-checkout-stepper'],
    'accordion' => ['accordion', 'gio-checkout-accordion'],
    'bento grid' => ['bento_grid', 'gio-checkout-bento-grid'],
    'compact embed' => ['compact_embed', 'gio-checkout-compact-embed'],
    'chips' => ['chips', 'gio-checkout-chips'],
    'invoice' => ['invoice', 'gio-checkout-invoice'],
    'dark panel' => ['dark_panel', 'gio-checkout-dark-panel'],
]);

it('reads a layout it cannot draw as the one the checkout has always had', function () {
    pageStyled(['layout' => 'carousel']);

    /**
     * Total, like everything a Config is read from. A landing page answering
     * paid traffic with a 500 costs more than one drawn in the arrangement it
     * started in.
     */
    expect($this->get('/promo')->assertOk()->getContent())->toContain('gio-checkout-stacked');
});

it('never asks a checkout whose product list is hidden which products to buy', function () {
    $page = pageStyled(['show_products' => false, 'product_picker' => true]);
    $page->products()->attach(Product::factory()->create());

    $html = $this->get('/promo')->assertOk()->getContent();

    /**
     * There is nothing on screen to tick, so there is no question. The merchant
     * keeps their switch for the day they show the product list again; what
     * it must never do meanwhile is draw a checkbox nobody can find, or — worse
     * — leave a rule standing that demands a choice the form never offered.
     */
    expect($html)->not->toContain('name="selected[]"')
        ->and($page->checkout_config->productPicker)->toBeTrue()
        ->and($page->checkout_config->picksProducts())->toBeFalse()
        ->and($page->refresh()->offersProductChoice())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Stacked, as designed
|--------------------------------------------------------------------------
|
| The one layout drawn to its design so far, and what the base sheet draws: a
| grey section holding a white card, three numbered sections, and a footer of
| totals under a button carrying the total. The other nine are chosen from the
| same list and draw as the base does under a class of their own, until each
| one's own block lands.
|
*/

it('draws the stacked checkout as three numbered sections, the totals and a button carrying the total', function () {
    $page = pageStyled([
        'fields' => [['key' => 'phone', 'label' => 'Phone number']],
        'delivery_options' => [
            ['label' => 'Inside Dhaka', 'amount' => 6000],
            ['label' => 'Outside Dhaka', 'amount' => 12000],
        ],
        'hide_lone_cash_choice' => false,
    ]);

    $product = $page->refresh()->products->first();

    $html = $this->get('/promo')->assertOk()->getContent();
    $markup = checkoutMarkup($html);
    $styles = styleBlock($html);

    /**
     * The three headlines the design numbers, in the design's words — Bangla
     * first, English second — each a badge and a heading over its section:
     * the products, then the details with the delivery choice beneath them,
     * then the payment choice. In document order, which is the order every
     * layout writes them in.
     */
    preg_match_all('/<h3 class="gio-checkout-heading"><span class="gio-checkout-number">(\d)<\/span>([^<]+)<\/h3>/', $markup, $headings, PREG_SET_ORDER);

    expect(array_map(fn (array $heading): array => [$heading[1], $heading[2]], $headings))->toBe([
        ['1', 'প্রোডাক্ট নির্বাচন করুন / Select products'],
        ['2', 'ডেলিভারি তথ্য / Delivery details'],
        ['3', 'পেমেন্ট / Payment method'],
    ]);

    expect(strpos($markup, 'Select products'))->toBeLessThan(strpos($markup, $product->name))
        ->and(strpos($markup, 'Delivery details'))->toBeLessThan(strpos($markup, 'name="phone"'))
        ->and(strpos($markup, 'name="phone"'))->toBeLessThan(strpos($markup, 'name="delivery_option"'))
        ->and(strpos($markup, 'Payment method'))->toBeLessThan(strpos($markup, 'name="payment_method"'))
        ->and(strpos($markup, 'name="payment_method"'))->toBeLessThan(strpos($markup, 'class="gio-checkout-summary"'));

    /**
     * The badge is a circle in the text colour with the digit in the surface
     * colour, as the design draws it, and the heading sits beside it.
     */
    preg_match('/\.gio-checkout-number \{([^}]*)\}/', $styles, $badge);

    expect($badge[1] ?? '')->toContain('border-radius: 50%')
        ->and($badge[1] ?? '')->toContain('background: var(--gio-text)')
        ->and($badge[1] ?? '')->toContain('color: var(--gio-surface)');

    /**
     * The total sits on the button in the design's words, filled in at render
     * from the same figure the summary prints, with the template riding on
     * the button for the script to refill: the price is on the button at the
     * moment a customer taps it.
     */
    $total = Money::format($product->price + 6000);

    expect($markup)->toContain('data-gio-submit-wording="অর্ডার করুন / Order Now · {total}"')
        ->and($markup)->toContain('>অর্ডার করুন / Order Now · '.$total.'</button>')
        ->and($markup)->toContain('<span data-gio-total>'.$total.'</span>');

    /**
     * And the payment rows carry the design's logo slot at the far end of the
     * row, after the method's name.
     */
    preg_match_all('/<label class="gio-checkout-choice[^"]*">(.*?)<\/label>/s', $markup, $rows);

    $cash = array_values(array_filter($rows[1], fn (string $row): bool => str_contains($row, 'value="cash_on_delivery"')));

    expect($cash)->toHaveCount(1)
        ->and($cash[0])->toMatch('/gio-checkout-choice-label">[^<]*Cash on Delivery<\/span>\s*<img class="gio-checkout-logo"/');
});

it('numbers the stacked sections by what is on the page', function () {
    pageStyled(['show_products' => false]);

    $markup = checkoutMarkup($this->get('/promo')->assertOk()->getContent());

    /**
     * The design numbers three fixed sections; a page may hide its product
     * list or draw no payment choice. A customer never reads a "2" with no
     * "1" above it: the sections are numbered as they are drawn, so a page
     * showing only its details opens on one.
     */
    preg_match_all('/<span class="gio-checkout-number">(\d)<\/span>/', $markup, $numbers);

    expect($numbers[1])->toBe(['1'])
        ->and($markup)->toContain('Delivery details')
        ->and($markup)->not->toContain('Select products');
});

it('draws the stacked headlines only on the stacked layout, in the merchant\'s words where there are any', function (CheckoutLayout $layout) {
    pageStyled([
        'layout' => $layout->value,
        'words' => [
            'stacked_products_headline' => 'কী কিনবেন / What to buy',
            'stacked_details_headline' => 'কোথায় পাঠাব / Where to send it',
        ],
    ]);

    $markup = checkoutMarkup($this->get('/promo')->assertOk()->getContent());

    /**
     * A layout's own words are drawn by that layout and no other: the merchant
     * who reworded the stacked headlines sees them on a stacked page, in
     * their words where they wrote some and the design's where they did not,
     * and a page arranged any other way draws no numbered heading at all —
     * a headline of its own where it has one, but never the badge and never
     * the stacked words. The words stay in the Config either way, for the
     * day the page comes back.
     */
    if ($layout === CheckoutLayout::Stacked) {
        expect($markup)->toContain('<span class="gio-checkout-number">1</span>কী কিনবেন / What to buy</h3>')
            ->and($markup)->toContain('<span class="gio-checkout-number">2</span>কোথায় পাঠাব / Where to send it</h3>');
    } else {
        expect($markup)->not->toContain('gio-checkout-number')
            ->and($markup)->not->toContain('What to buy')
            ->and($markup)->not->toContain('Where to send it')
            ->and($markup)->not->toContain(WordSlot::StackedProductsHeadline->defaultValue());
    }

    expect(Page::where('slug', 'promo')->sole()->checkout_config->words->slot(WordSlot::StackedProductsHeadline))->toBe('কী কিনবেন / What to buy');
})->with(fn () => CheckoutLayout::cases());

it('draws the design\'s headline where a stored headline is one the words tab would refuse', function () {
    pageStyled(['hide_lone_cash_choice' => false, 'words' => ['stacked_payment_headline' => str_repeat('p', 61)]]);

    /** Refused silently on read, the way every Config value is: the design's words stand rather than a page answering with a 500. */
    expect(checkoutMarkup($this->get('/promo')->assertOk()->getContent()))
        ->toContain('পেমেন্ট / Payment method')
        ->not->toContain(str_repeat('p', 61));
});

it('sizes the card at the design\'s width and paints the section behind it', function () {
    pageStyled([]);

    $styles = styleBlock($this->get('/promo')->assertOk()->getContent());

    preg_match('/\.gio-checkout \{([^}]*)\}/', $styles, $root);
    preg_match('/\.gio-checkout-card \{([^}]*)\}/', $styles, $card);

    /**
     * The root is the section the design paints behind the card, from the
     * background token, with the design's padding around it; the card inside
     * is what is bordered, filled from the surface token and held to the
     * design's width. Neither figure is a token: how wide the form sits is
     * the design's to say.
     */
    expect($root[1] ?? '')->toContain('background: var(--gio-background)')
        ->and($root[1] ?? '')->toContain('padding: 2.5rem 1.5rem')
        ->and($root[1] ?? '')->not->toMatch('/(^|;)\s*border:/')
        ->and($card[1] ?? '')->toContain('max-width: 35rem')
        ->and($card[1] ?? '')->toContain('margin: 0 auto')
        ->and($card[1] ?? '')->toContain('padding: 1.75rem')
        ->and($card[1] ?? '')->toContain('border: 1px solid var(--gio-border)')
        ->and($card[1] ?? '')->toContain('background: var(--gio-surface)')
        ->and($card[1] ?? '')->not->toContain('border-radius')
        ->and($card[1] ?? '')->not->toContain('box-shadow');
});

it('closes the section and the card in on a phone without reordering anything', function () {
    pageStyled([]);

    $styles = styleBlock($this->get('/promo')->assertOk()->getContent());

    preg_match('/@media \(max-width: 37\.5rem\) \{(.*?)\} \}/s', $styles, $phone);

    /**
     * The design's 375: the section and the card lose their air, a product's
     * line total drops under the name, and the button loses a little padding.
     * The same island, tighter — nothing in the phone rules hides, clips or
     * reorders a thing.
     */
    expect($phone[1] ?? '')->toContain('.gio-checkout { padding: 1.25rem 1rem; }')
        ->and($phone[1] ?? '')->toContain('.gio-checkout-card { padding: 1.25rem 1rem; }')
        ->and($phone[1] ?? '')->toContain('.gio-checkout-line-total { flex: 1 0 100%;')
        ->and($phone[1] ?? '')->not->toContain('display: none')
        ->and($phone[1] ?? '')->not->toContain('order:');
});

it('rules the footer off from the sections above it and spaces the sections by whitespace alone', function () {
    pageStyled([]);

    $styles = styleBlock($this->get('/promo')->assertOk()->getContent());

    preg_match('/\.gio-checkout-summary \{([^}]*)\}/', $styles, $summary);
    preg_match('/\.gio-checkout-products \{([^}]*)\}/', $styles, $panel);
    preg_match('/\.gio-checkout-section \+ \.gio-checkout-section \{([^}]*)\}/', $styles, $between);

    /**
     * One hairline in the whole card, above the totals, as the design draws
     * it; the numbered sections are set apart by their headings and the
     * design's gap, and the product panel draws no rule of its own beneath.
     */
    expect($summary[1] ?? '')->toContain('border-top: 1px solid var(--gio-border)')
        ->and($panel[1] ?? '')->not->toContain('border')
        ->and($between[1] ?? '')->toContain('margin: 1.75rem 0 0');
});

it('draws every product row as one line with its total at the far end', function () {
    $page = pageStyled([]);
    $product = $page->refresh()->products->first();

    $html = $this->get('/promo')->assertOk()->getContent();
    $markup = checkoutMarkup($html);
    $styles = styleBlock($html);

    preg_match('/<div class="gio-checkout-product"[^>]*>(.*?)<p class="gio-checkout-line-total"/s', $markup, $row);

    /**
     * The picture, the name with its price beneath, the stepper, then what the
     * line comes to — in that order along one row, as the design draws it.
     * The line total is the price of one until the customer touches the
     * stepper, and the script keeps it truthful after.
     */
    expect($row)->not->toBeEmpty()
        ->and($row[1])->toMatch('/gio-checkout-product-head.*gio-checkout-name.*gio-checkout-price.*gio-checkout-quantity-stepper/s')
        ->and($markup)->toContain('<p class="gio-checkout-line-total" data-gio-line-total>'.Money::format($product->price).'</p>')
        ->and(checkoutScript($html))->toContain('[data-gio-line-total]');

    preg_match('/\.gio-checkout-product \{([^}]*)\}/', $styles, $rule);
    preg_match('/\n\s*\.gio-checkout-line-total \{([^}]*)\}/', $styles, $total);

    expect($rule[1] ?? '')->toContain('display: flex')
        ->and($total[1] ?? '')->toContain('text-align: right')
        ->and($total[1] ?? '')->toContain('white-space: nowrap');
});

it('lets a stacked page still ask which products to buy, and fades the line total of one not picked', function () {
    $page = pageStyled(['product_picker' => true]);

    /** A choice of one is not a choice, so the page has to sell two before it asks. */
    $page->products()->attach(Product::factory()->create());

    $html = $this->get('/promo')->assertOk()->getContent();
    $markup = checkoutMarkup($html);

    /**
     * The tick sits at the head of the row, the stepper it reveals is still
     * revealed by it, and the line total of an item not ticked fades as the
     * design draws it rather than disappearing — a customer reads what
     * ticking it would add. The rules read siblings, which is what makes them
     * hold in a flex row as they did in a grid.
     */
    expect($markup)->toContain('name="selected[]"')
        ->and($markup)->toContain('class="gio-checkout-product gio-checkout-pickable"')
        ->and(styleBlock($html))->toContain('.gio-checkout-pick:not(:checked) ~ .gio-checkout-quantity { display: none; }')
        ->and(styleBlock($html))->toContain('.gio-checkout-pick:not(:checked) ~ .gio-checkout-line-total { opacity: .45; }')
        ->and($page->refresh()->checkout_config->picksProducts())->toBeTrue();
});

it('declares the font faces once, at the top of the sheet', function () {
    pageStyled([]);

    $styles = styleBlock($this->get('/promo')->assertOk()->getContent());

    /** One block of faces, before the root rule that reads the token, and never a second copy anywhere in the sheet. */
    expect(substr_count($styles, '@font-face { font-family: "Hind Siliguri"; font-style: normal; font-weight: 400; font-display: swap; src: url(/fonts/hind-siliguri/hind-siliguri-400-bengali.woff2)'))->toBe(1)
        ->and(strpos($styles, '@font-face'))->toBeLessThan(strpos($styles, '.gio-checkout {'));
});

/*
|--------------------------------------------------------------------------
| What a layout says about itself
|--------------------------------------------------------------------------
|
| Each of the ten answers the same questions — whether it draws a delivery
| detail line, which payment label it names a method by, whether it pairs
| half-width questions — and the view draws by the answers. Read as what a
| customer sees on each arrangement, never as the predicates themselves.
|
*/

it('draws a delivery option\'s detail line only on a layout with room for it', function (CheckoutLayout $layout, bool $drawn) {
    pageStyled([
        'layout' => $layout->value,
        'delivery_options' => [
            ['label' => 'Inside Dhaka', 'amount' => 6000, 'detail' => '1–2 days'],
            ['label' => 'Outside Dhaka', 'amount' => 12000],
        ],
    ]);

    $markup = checkoutMarkup($this->get('/promo')->assertOk()->getContent());

    /**
     * A roomy arrangement draws the choice as a boxed row with a second line;
     * a segmented one draws it as a button a word wide, where a second line
     * has nowhere to go. The line stays in the Config either way, so a page
     * moved to a roomy layout draws it again.
     */
    expect($markup)->toContain('Inside Dhaka')
        ->and(str_contains($markup, '<span class="gio-checkout-choice-detail">1–2 days</span>'))->toBe($drawn)
        ->and(Page::where('slug', 'promo')->sole()->checkout_config->deliveryOptions[0]->detail)->toBe('1–2 days');
})->with([
    'stacked' => [CheckoutLayout::Stacked, true],
    'split' => [CheckoutLayout::Split, true],
    'split reversed' => [CheckoutLayout::SplitReversed, false],
    'stepper' => [CheckoutLayout::Stepper, true],
    'accordion' => [CheckoutLayout::Accordion, true],
    'bento grid' => [CheckoutLayout::BentoGrid, false],
    'compact embed' => [CheckoutLayout::CompactEmbed, false],
    'chips' => [CheckoutLayout::Chips, false],
    'invoice' => [CheckoutLayout::Invoice, true],
    'dark panel' => [CheckoutLayout::DarkPanel, true],
]);

it('names a payment method by the label the layout has room for', function (CheckoutLayout $layout, bool $short) {
    pageStyled([
        'layout' => $layout->value,
        'hide_lone_cash_choice' => false,
        'words' => ['payment' => ['cash_on_delivery' => ['long' => 'হাতে হাতে / Pay at the door', 'short' => 'Door']]],
    ]);

    $markup = checkoutMarkup($this->get('/promo')->assertOk()->getContent());

    /**
     * The merchant wrote both labels once; which one names the method is the
     * layout's decision. A segmented control or a two-by-two grid draws the
     * short one and a boxed row draws the long one, and the other is kept for
     * the day the page is arranged differently.
     */
    expect($markup)->toContain($short ? '>Door</span>' : '>হাতে হাতে / Pay at the door</span>')
        ->and($markup)->not->toContain($short ? 'Pay at the door' : '>Door</span>');
})->with([
    'stacked' => [CheckoutLayout::Stacked, false],
    'split' => [CheckoutLayout::Split, false],
    'split reversed' => [CheckoutLayout::SplitReversed, false],
    'stepper' => [CheckoutLayout::Stepper, false],
    'accordion' => [CheckoutLayout::Accordion, false],
    'bento grid' => [CheckoutLayout::BentoGrid, true],
    'compact embed' => [CheckoutLayout::CompactEmbed, true],
    'chips' => [CheckoutLayout::Chips, false],
    'invoice' => [CheckoutLayout::Invoice, false],
    'dark panel' => [CheckoutLayout::DarkPanel, true],
]);

it('fills every question\'s row on a layout that pairs nothing, and keeps the width the merchant chose', function (CheckoutLayout $layout, bool $paired) {
    $page = pageStyled([
        'layout' => $layout->value,
        'fields' => [
            ['key' => 'name', 'label' => 'Your name', 'width' => 'half'],
            ['key' => 'phone', 'label' => 'Phone number', 'width' => 'half'],
        ],
    ]);

    $markup = checkoutMarkup($this->get('/promo')->assertOk()->getContent());

    /**
     * The narrow-column arrangements pair nothing: a merchant choosing one has
     * already said what they want the form to do, so a half-width question
     * fills its row there whatever the merchant set. The width itself stays
     * in the Config, untouched, which is what a page moved back reads to
     * pair its questions the way it always did.
     */
    expect(substr_count($markup, 'class="gio-checkout-field gio-checkout-field-half"'))->toBe($paired ? 2 : 0)
        ->and($page->refresh()->checkout_config->fields[0]->width)->toBe(FieldWidth::Half);
})->with([
    'stacked' => [CheckoutLayout::Stacked, true],
    'split' => [CheckoutLayout::Split, true],
    'split reversed' => [CheckoutLayout::SplitReversed, false],
    'stepper' => [CheckoutLayout::Stepper, true],
    'accordion' => [CheckoutLayout::Accordion, true],
    'bento grid' => [CheckoutLayout::BentoGrid, true],
    'compact embed' => [CheckoutLayout::CompactEmbed, false],
    'chips' => [CheckoutLayout::Chips, true],
    'invoice' => [CheckoutLayout::Invoice, true],
    'dark panel' => [CheckoutLayout::DarkPanel, true],
]);

/**
 * The script the island ships beside its markup, from wherever in the page it
 * was expanded.
 *
 * There is no Blade in it, so two pages differ here only if a layout put
 * something there — which is the whole of what this is read for.
 */
function checkoutScript(string $html): string
{
    preg_match_all('/<script>(.*?)<\/script>/s', $html, $blocks);

    return implode('', $blocks[1]);
}

it('never puts a total behind an interaction on any layout', function (CheckoutLayout $layout) {
    pageStyled(['layout' => $layout->value], slug: layoutSlug($layout));
    pageStyled([], slug: 'plain-stacked');

    $html = $this->get('/'.layoutSlug($layout))->assertOk()->getContent();
    $markup = checkoutMarkup($html);

    /**
     * Expanded, always, and nothing in the markup to collapse it: a page leads
     * with the figure a customer is agreeing to hand over in cash at their
     * door, and a disclosure would put that figure behind a tap. A layout
     * that behaves (Stepper, Accordion) hides by script and never by markup
     * alone, so without script every panel shows (ADR-0015).
     */
    expect($markup)->toContain('data-gio-subtotal')
        ->and($markup)->toContain('data-gio-total')
        ->and($markup)->not->toContain('<details')
        ->and($markup)->not->toContain('<summary')
        ->and($markup)->not->toContain('gio-checkout-section" hidden');

    /**
     * And the arrangement ships the one script every page ships, so a page
     * whose scripts never ran still leads with its total in the document's
     * order.
     */
    expect(checkoutScript($html))->toBe(checkoutScript($this->get('/plain-stacked')->assertOk()->getContent()));
})->with(fn () => CheckoutLayout::cases());

it('names each of the three panels in the markup on every layout, so a behaving one can find them', function (CheckoutLayout $layout) {
    twoColumnPage($layout);

    $markup = checkoutMarkup($this->get('/promo')->assertOk()->getContent());

    /**
     * Which of the three a section is, said in the markup rather than inferred
     * from what happens to be inside it: the products may be hidden and the
     * payment choice may not be drawn, so a stepper's step labels cannot be
     * paired with its panels by counting. Written on every arrangement, so
     * the document is the same shape whichever is chosen, and read by nothing
     * at all on the eight that do not behave.
     */
    expect($markup)->toContain('<div class="gio-checkout-section" data-gio-panel="products">')
        ->and($markup)->toContain('<div class="gio-checkout-section" data-gio-panel="details">')
        ->and($markup)->toContain('<div class="gio-checkout-section" data-gio-panel="payment">')
        ->and(substr_count($markup, 'data-gio-panel='))->toBe(3);
})->with(fn () => CheckoutLayout::cases());

it('keeps a behaving layout\'s own markers clear of the quantity stepper\'s', function () {
    pageStyled(['layout' => CheckoutLayout::Stepper->value]);

    $script = checkoutScript($this->get('/promo')->assertOk()->getContent());

    /**
     * `data-gio-step` is the quantity stepper's, on the two buttons that step
     * one product's box. Put the same name on the form to hold which step is
     * showing and `closest('[data-gio-step]')` answers the form for every
     * click inside it: a tap on Next, on a payment row, on anything at all
     * would step the first product's quantity, and the customer would be
     * ordering more than they asked for with nothing on screen to say so. The
     * form says `data-gio-showing`, and the quantity handler asks for the
     * button rather than for the name, so neither half can bring it back.
     */
    expect($script)->toContain('form.dataset.gioShowing')
        ->and($script)->not->toContain('form.dataset.gioStep')
        ->and($script)->toContain("closest('button[data-gio-step]')")
        ->and($script)->not->toContain("closest('[data-gio-step]')");
});

it('leaves an arrangement that only arranges with nothing to behave with', function (CheckoutLayout $layout) {
    twoColumnPage($layout);

    $markup = checkoutMarkup($this->get('/promo')->assertOk()->getContent());

    /**
     * The behaviour is one script keyed off two modifier classes, so the
     * other eight pay for it in bytes and in nothing else: no word riding on
     * the form for a control they never build, and no rule saying what
     * `hidden` means, because they hide nothing. `display: none` and a
     * reordering in a block stay the thing only a behaving layout may write,
     * and it writes each once (ADR-0015).
     */
    expect($layout->behaves())->toBeFalse()
        ->and($markup)->not->toContain('data-gio-cue-')
        ->and($layout->styleRules())->not->toContain('[hidden]')
        ->and($layout->styleRules())->not->toContain('display: none')
        ->and($layout->styleRules())->not->toMatch('/[^a-z-]order:/');
})->with(fn () => array_values(array_filter(
    CheckoutLayout::cases(),
    fn (CheckoutLayout $layout): bool => ! $layout->behaves(),
)));

it('marks a half-width question with one flat class and generates nothing for it', function () {
    pageStyled([
        'fields' => [
            ['key' => 'name', 'label' => 'Your name', 'width' => 'half'],
            ['key' => 'phone', 'label' => 'Phone number', 'width' => 'half'],
        ],
    ], slug: 'paired');

    pageStyled([], slug: 'plain');

    $html = $this->get('/paired')->assertOk()->getContent();

    /**
     * A width is a class the sheet already carries and never a rule of its own,
     * so pairing every question on a page costs the same bytes as pairing none
     * — and a merchant's own stylesheet still beats us at the specificity it
     * always did.
     */
    expect(substr_count(checkoutMarkup($html), 'class="gio-checkout-field gio-checkout-field-half"'))->toBe(2)
        ->and(styleBlock($html))->toBe(styleBlock($this->get('/plain')->getContent()));
});

it('leaves a question at full width when the page says nothing about it', function () {
    pageStyled([]);

    /**
     * Absent is the full row every question had before one could sit beside
     * another, and the markup is the markup those pages already carry.
     */
    expect(checkoutMarkup($this->get('/promo')->getContent()))
        ->toContain('<div class="gio-checkout-field" data-gio-field>')
        ->not->toContain('gio-checkout-field-half');
});

it('pairs half-width questions above the breakpoint and nowhere else', function () {
    pageStyled([]);

    $styles = styleBlock($this->get('/promo')->getContent());

    preg_match('/@media \(min-width: [^)]+\) \{.*?\} \}/s', $styles, $wide);

    $narrow = str_replace($wide[0] ?? '', '', $styles);

    /**
     * The second column exists inside the media query and nowhere else, so
     * below the breakpoint the grid is one column and every question fills it
     * whatever the merchant chose. This traffic is overwhelmingly phone-sized:
     * a control whose only use is breaking the form for most of the people who
     * will see it is not a control worth having, and there is nothing here to
     * override.
     */
    expect($wide[0] ?? '')->toContain('.gio-checkout-field-half')
        ->and($wide[0])->toContain('grid-template-columns: 1fr 1fr;')
        ->and($narrow)->not->toContain('gio-checkout-field-half')
        ->and($narrow)->toContain('.gio-checkout-fields { display: grid; grid-template-columns: 1fr;');
});

it('lets the half-width rule win by order rather than by specificity', function () {
    pageStyled([]);

    $styles = styleBlock($this->get('/promo')->getContent());

    /**
     * Both selectors are one class, so the later declaration is the one that
     * applies. Raising either would spend the escape hatch a merchant's own
     * stylesheet needs on ourselves — the same trade the quantity stepper makes.
     */
    expect(strpos($styles, '.gio-checkout-field-half { grid-column'))
        ->toBeGreaterThan(strpos($styles, '.gio-checkout-field { grid-column'));
});

it('lets a paired question be narrower than the box it types in', function () {
    pageStyled([]);

    $styles = styleBlock($this->get('/promo')->assertOk()->getContent());

    preg_match('/\.gio-checkout-field \{([^}]*)\}/', $styles, $field);

    /**
     * A grid item's `min-width` is `auto`, which is its content's min-content
     * width — and an input's is about twenty characters however wide we draw
     * it. Two columns narrower than that would push the grid past the checkout
     * and out of the element the merchant put the shortcode in, which is the
     * one thing the island must never do. It is reachable on a desktop with
     * two long placeholders, so the floor is removed rather than argued with.
     */
    expect($field[1])->toContain('min-width: 0;');
});

it('puts the merchant\'s words on the order button and beneath it', function () {
    pageStyled(['words' => ['order_button' => [
        'wording' => 'Send me the jacket',
        'note' => 'You pay the courier, not us.',
    ]]]);

    $markup = checkoutMarkup($this->get('/promo')->assertOk()->getContent());

    expect($markup)->toContain('data-gio-submit>Send me the jacket</button>')
        ->and($markup)->toContain('<p class="gio-checkout-note">You pay the courier, not us.</p>');
});

it('still reads the order button from where a config stored it before the words existed', function () {
    pageStyled(['order_button' => [
        'wording' => 'Send me the jacket',
        'note' => 'You pay the courier, not us.',
    ]]);

    $markup = checkoutMarkup($this->get('/promo')->assertOk()->getContent());

    /**
     * No data migration: a Config written before the button's words moved
     * under `words` is read from the key it was stored under, and the first
     * save from the tab writes the new shape.
     */
    expect($markup)->toContain('data-gio-submit>Send me the jacket</button>')
        ->and($markup)->toContain('<p class="gio-checkout-note">You pay the courier, not us.</p>');
});

it('says the design\'s words, Bangla first and English second, on a page that says nothing', function () {
    pageStyled(['payment_methods' => ['cash_on_delivery'], 'hide_lone_cash_choice' => false]);

    $markup = checkoutMarkup($this->get('/promo')->assertOk()->getContent());

    /**
     * Blank is the merchant saying nothing, and a page that says nothing reads
     * the way the design does — and the stacked design puts the total on the
     * button, so the template rides on it for the script to refill.
     */
    expect($markup)->toContain('data-gio-submit-wording="অর্ডার করুন / Order Now · {total}">অর্ডার করুন / Order Now · ৳')
        ->and($markup)->toContain('<p class="gio-checkout-note">পার্সেল হাতে পেয়ে নগদে পরিশোধ করুন / Pay in cash when the parcel reaches you.</p>')
        ->and($markup)->toContain('<span>সাবটোটাল / Subtotal</span>')
        ->and($markup)->toContain('<span>মোট / Total</span>')
        ->and($markup)->toContain('gio-checkout-choice-label">ক্যাশ অন ডেলিভারি / Cash on Delivery<');
});

it('puts the merchant\'s words on the totals rows and beside each payment method', function () {
    pageStyled([
        'delivery_options' => [['label' => 'Inside Dhaka', 'amount' => 6000]],
        'payment_methods' => ['cash_on_delivery'],
        'hide_lone_cash_choice' => false,
        'words' => [
            'subtotal' => 'পণ্যের দাম / Items',
            'delivery' => 'কুরিয়ার / Courier',
            'total' => 'সর্বমোট / Grand total',
            'payment' => ['cash_on_delivery' => ['long' => 'হাতে হাতে / At the door', 'short' => 'Door']],
        ],
    ]);

    $markup = checkoutMarkup($this->get('/promo')->assertOk()->getContent());

    /**
     * The summary rows and the payment row in the merchant's words. The long
     * label is the one drawn for now; which of the two a layout draws is the
     * layout's decision, and every layout as it stands has the room for it.
     */
    expect($markup)->toContain('<span>পণ্যের দাম / Items</span>')
        ->and($markup)->toContain('<span>কুরিয়ার / Courier</span>')
        ->and($markup)->toContain('<span>সর্বমোট / Grand total</span>')
        ->and($markup)->toContain('gio-checkout-choice-label">হাতে হাতে / At the door<')
        ->and($markup)->not->toContain('Door<');
});

it('draws the words it always had where a stored word is one the checkout tab would refuse', function () {
    pageStyled(['words' => [
        'subtotal' => str_repeat('s', 41),
        'delivery' => "Delivery\ncharge",
        'total' => str_repeat('t', 41),
        'payment' => ['cash_on_delivery' => ['long' => str_repeat('l', 61)]],
    ], 'delivery_options' => [['label' => 'Inside Dhaka', 'amount' => 6000]], 'hide_lone_cash_choice' => false]);

    $markup = checkoutMarkup($this->get('/promo')->assertOk()->getContent());

    /** Refused twice: worded on the tab, silent here where a Config written by another route has nobody to tell. */
    expect($markup)->toContain('<span>সাবটোটাল / Subtotal</span>')
        ->and($markup)->toContain('<span>ডেলিভারি চার্জ / Delivery</span>')
        ->and($markup)->toContain('<span>মোট / Total</span>')
        ->and($markup)->toContain('gio-checkout-choice-label">ক্যাশ অন ডেলিভারি / Cash on Delivery<');
});

it('puts the live total on the button where the merchant asked for it', function () {
    $page = pageStyled(['words' => ['order_button' => ['wording' => 'Order for {total}']]]);
    $page->products()->sync([Product::factory()->create(['price' => 99000])->getKey()]);
    $page->checkout_config = CheckoutConfig::fromArray([
        ...$page->checkout_config->toArray(),
        'delivery_options' => [['label' => 'Inside Dhaka', 'amount' => 6000]],
    ]);
    $page->save();

    $html = $this->get('/promo')->assertOk()->getContent();
    $markup = checkoutMarkup($html);

    /**
     * Substituted at render, so a visitor whose scripts never run reads a
     * figure and not a placeholder — the same figure the summary totals. The
     * template stays on the button for the script, which retotals the button
     * with the summary as the customer changes what they are buying.
     */
    expect($markup)->toContain('data-gio-submit-wording="Order for {total}"')
        ->and($markup)->toContain('>Order for '.Money::format(105000).'</button>')
        ->and($markup)->not->toContain('>Order for {total}<')
        ->and(checkoutScript($html))->toContain('gioSubmitWording')
        /** The script and the tab spell the placeholder the same way, or a merchant's total goes stale on the first retotal. */
        ->and(checkoutScript($html))->toContain("var TOTAL_PLACEHOLDER = '".OrderButton::TOTAL_PLACEHOLDER."';");
});

it('drops button copy the checkout tab would refuse and draws the words it always had', function (array $stored) {
    pageStyled(['words' => ['order_button' => $stored]]);

    $markup = checkoutMarkup($this->get('/promo')->assertOk()->getContent());

    /**
     * Refused twice, like every value a Config holds: worded on the tab, and
     * silent here where a Config written by another route has nobody to tell.
     */
    expect($markup)->toContain('>অর্ডার করুন / Order Now · ৳')
        ->and($markup)->not->toContain('xxxxx')
        ->and($markup)->not->toContain('Order\nnow')
        ->and($markup)->toContain('<p class="gio-checkout-note">পার্সেল হাতে পেয়ে নগদে পরিশোধ করুন / Pay in cash when the parcel reaches you.</p>');
})->with([
    'copy too long for the button and the line beneath it' => [['wording' => str_repeat('x', 81), 'note' => str_repeat('y', 201)]],
    'a wording broken over two lines' => [['wording' => "Order\nnow"]],
]);

it('draws no payment chooser on a page that says nothing about paying', function () {
    pageStyled([]);

    $html = $this->get('/promo')->assertOk()->getContent();

    /**
     * Every page that exists today is cash on delivery with the choice
     * hidden, and a page that has always been cash-only draws the same form
     * it always did: no chooser, no method posted, no rule for a logo in the
     * sheet. Nothing about paying reaches the bytes at all.
     */
    expect(checkoutMarkup($html))->not->toContain('payment')
        ->and(styleBlock($html))->not->toContain('gio-checkout-logo')
        ->and($html)->not->toContain('gio-checkout-payment');
});

it('draws a single cash on delivery row with its logo once the page unhides the choice', function () {
    pageStyled(['hide_lone_cash_choice' => false]);

    $html = $this->get('/promo')->assertOk()->getContent();
    $markup = checkoutMarkup($html);

    preg_match('/<fieldset class="gio-checkout-choices gio-checkout-payment">(.*?)<\/fieldset>/s', $markup, $payment);
    preg_match_all('/<label class="gio-checkout-choice[^"]*">(.*?)<\/label>/s', $payment[1] ?? '', $rows);

    /**
     * One row, already picked, drawn as a delivery row is: the control, then
     * the words, then the logo at the far end as the design's slot sits — in
     * the payment section every layout draws in the same place. The radio is
     * a real one in a real form, so a visitor whose script never runs still
     * posts a method.
     */
    expect($payment[1] ?? '')->toContain('<legend class="gio-checkout-label gio-checkout-hidden-label">Payment</legend>')
        ->and($rows[1])->toHaveCount(1)
        ->and($rows[1][0])->toMatch('/type="radio"[^>]*name="payment_method"[^>]*value="cash_on_delivery"[^>]*checked required>.*gio-checkout-choice-label">ক্যাশ অন ডেলিভারি \/ Cash on Delivery<\/span>.*<img class="gio-checkout-logo" src="[^"]*\/images\/payment-methods\/cash-on-delivery\.svg" alt=""/s')
        ->and($markup)->toContain('data-gio-error-for="payment_method"');

    /** The logo rule rides only on a page that draws one, sized in em like everything else below the root. */
    expect(styleBlock($html))->toContain('.gio-checkout-logo { height: 1.5em; width: auto; flex: none; }');
});

it('lists payment methods by name alone when the page switches logos off', function () {
    pageStyled(['hide_lone_cash_choice' => false, 'show_payment_logos' => false]);

    $html = $this->get('/promo')->assertOk()->getContent();

    /** No logo drawn, and no rule for one either: the block appears only where it applies. */
    expect(checkoutMarkup($html))->toContain('gio-checkout-choice-label">ক্যাশ অন ডেলিভারি / Cash on Delivery<')
        ->and($html)->not->toContain('gio-checkout-logo');
});

it('ships every default logo as a public asset the checkout can fetch', function () {
    /** The default marks live in the codebase, so a checkout looks right with no upload from the merchant. */
    foreach (PaymentMethod::cases() as $method) {
        expect(public_path($method->logoPath()))->toBeFile()
            ->and($method->logoUrl())->toEndWith('/'.$method->logoPath());
    }
});

it('generates no selector for the payment chooser', function () {
    pageStyled(['hide_lone_cash_choice' => false], slug: 'plain');
    pageStyled([
        'hide_lone_cash_choice' => false,
        'fields' => [['key' => 'gift', 'label' => 'Gift?', 'style_tokens' => ['border' => '#ff0000']]],
    ], slug: 'busy');

    /**
     * The chooser's block is a fixed rule picked by a switch, never built from
     * a merchant's figures, so restyling a question beside it leaves the sheet
     * exactly where it was — and the base a merchant's own stylesheet has to
     * beat is the same on both pages.
     */
    expect(styleBlock($this->get('/busy')->assertOk()->getContent()))
        ->toBe(styleBlock($this->get('/plain')->assertOk()->getContent()));
});

it('keeps the layout block last when a chooser adds its own', function (CheckoutLayout $layout) {
    pageStyled(['hide_lone_cash_choice' => false, 'layout' => $layout->value]);

    $styles = styleBlock($this->get('/promo')->assertOk()->getContent());

    /**
     * The chooser's rule comes after the base's last rule and before the
     * layout's block, so the layout still sits last and what it narrows it
     * narrows by order, the chooser's rule included. With no layout adding
     * anything the chooser's rule is the tail of the sheet; where a layout's
     * block has landed it follows on a line of its own — the chooser's block
     * ends its line — and the base sheet read back out of the page still
     * holds the chooser's rule and nothing of the layout's.
     */
    expect(strpos($styles, '.gio-checkout-logo {'))->toBeGreaterThan((int) strpos($styles, '.gio-checkout-note {'))
        ->and(str_ends_with(trim($styles), trim('.gio-checkout-logo { height: 1.5em; width: auto; flex: none; }'."\n".$layout->styleRules())))->toBeTrue()
        ->and(baseSheet($styles, $layout))->toContain('.gio-checkout-logo {');
})->with(fn () => CheckoutLayout::cases());

it('hands the visitor, the disk and the fallback reader the same bytes with a chooser drawn', function () {
    pageStyled(['hide_lone_cash_choice' => false]);

    $response = $this->get('/promo');

    $served = (new FallbackPageCacheReader($this->cacheDirectory))->resolve('/promo', 'GET');

    expect($response->getContent())->toContain('name="payment_method"')
        ->and(file_get_contents($this->cache->htmlPath('promo')))->toBe($response->getContent())
        ->and(file_get_contents($served['path']))->toBe($response->getContent());
});

/**
 * A page arranged in one of the two-column layouts, asking the questions the
 * designs draw, with a priced delivery choice carrying a detail line, a
 * payment choice, and the product list shown.
 */
function twoColumnPage(CheckoutLayout $layout, array $config = []): Page
{
    return pageStyled([
        'layout' => $layout->value,
        'fields' => [
            ['key' => 'name', 'label' => 'Your name', 'width' => 'half'],
            ['key' => 'phone', 'label' => 'Phone number', 'width' => 'half'],
            ['key' => 'address', 'label' => 'Address'],
        ],
        'delivery_options' => [
            ['label' => 'Inside Dhaka', 'amount' => 6000, 'detail' => '1–2 days'],
            ['label' => 'Outside Dhaka', 'amount' => 12000, 'detail' => '3–5 days'],
        ],
        'hide_lone_cash_choice' => false,
        ...$config,
    ]);
}

it('draws the split checkout as the form beside a grey order panel, headed and hinted in the design\'s words', function () {
    $page = twoColumnPage(CheckoutLayout::Split);
    $product = $page->refresh()->products->first();

    $html = $this->get('/promo')->assertOk()->getContent();
    $markup = checkoutMarkup($html);
    $styles = styleBlock($html);

    /**
     * Four headlines with no badge — over the order panel, the questions, the
     * delivery choice and the payment choice — and one hint under the button
     * after the button's own note, each in the design's words, Bangla first.
     * The delivery headline sits between the questions and the priced rows,
     * which still carry their detail line: the split is a roomy arrangement.
     */
    expect($markup)->toContain('<h3 class="gio-checkout-heading">আপনার অর্ডার / Your order</h3>')
        ->and($markup)->toContain('<h3 class="gio-checkout-heading">ডেলিভারি তথ্য / Delivery details</h3>')
        ->and($markup)->toContain('<h3 class="gio-checkout-heading">ডেলিভারি এলাকা / Delivery area</h3>')
        ->and($markup)->toContain('<h3 class="gio-checkout-heading">পেমেন্ট / Payment</h3>')
        ->and($markup)->toContain('<p class="gio-checkout-footnote">অর্ডার কনফার্ম করতে আমরা কল করব / We will call to confirm your order</p>')
        ->and($markup)->not->toContain('gio-checkout-number')
        ->and($markup)->toContain('<span class="gio-checkout-choice-detail">1–2 days</span>')
        ->and($markup)->toContain('gio-checkout-choice-label">ক্যাশ অন ডেলিভারি / Cash on Delivery<')
        ->and($markup)->toContain('class="gio-checkout-field gio-checkout-field-half"');

    $landmarks = [
        'class="gio-checkout-heading">আপনার অর্ডার',
        'class="gio-checkout-products"',
        $product->name,
        'class="gio-checkout-questions"',
        'class="gio-checkout-heading">ডেলিভারি তথ্য',
        'class="gio-checkout-fields"',
        'class="gio-checkout-heading">ডেলিভারি এলাকা',
        'class="gio-checkout-delivery"',
        'class="gio-checkout-heading">পেমেন্ট',
        'name="payment_method"',
        'class="gio-checkout-summary"',
        'data-gio-submit',
        'class="gio-checkout-note"',
        'class="gio-checkout-footnote"',
    ];

    $positions = array_map(fn (string $landmark): int => (int) strpos($markup, $landmark), $landmarks);
    $sorted = $positions;
    sort($sorted);

    foreach ($landmarks as $landmark) {
        expect($markup)->toContain($landmark);
    }

    expect($positions)->toBe($sorted);

    /**
     * The arrangement is the sheet's: the form is a grid, the questions take
     * the left column across every row, and the products, the summary, the
     * button and the lines beneath it take the right one, over a panel that
     * is the form's own pseudo-element — grey mixed from the tokens, bordered
     * from the border token, never a colour of its own. On a phone the panel
     * stacks under the form on one column, hiding and reordering nothing.
     */
    $block = CheckoutLayout::Split->styleRules();

    expect($styles)->toContain($block)
        ->and($block)->toContain('.gio-checkout-split .gio-checkout-form { display: grid;')
        ->and($block)->toContain('.gio-checkout-split .gio-checkout-form::before { content: "";')
        ->and($block)->toContain('background: color-mix(in srgb, var(--gio-text) 3%, var(--gio-surface));')
        ->and($block)->toContain('.gio-checkout-split .gio-checkout-questions { grid-column: 1; grid-row: 1 / -1;')
        ->and($block)->toContain('.gio-checkout-split .gio-checkout-summary { grid-column: 4;')
        ->and($block)->toContain('.gio-checkout-split .gio-checkout-submit { grid-column: 4;')
        ->and($block)->toContain('@media (max-width: 37.5rem) {')
        ->and($block)->toContain('.gio-checkout-split .gio-checkout-form { grid-template-columns: 1.25rem minmax(0, 1fr) 1.25rem;')
        ->and($block)->not->toContain('display: none')
        ->and($block)->not->toMatch('/[^a-z-]order:/');
});

it('draws the split reversed checkout as product cards beside a compact form, counting the bundle and naming the delivery charge live', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket', 'price' => 99000]);
    $boots = Product::factory()->create(['name' => 'Hiking Boots', 'price' => 149000]);
    $scarf = Product::factory()->create(['name' => 'Wool Scarf', 'price' => 29000]);

    pickingPage([$jacket, $boots, $scarf], [$jacket->getKey(), $boots->getKey()], [
        'layout' => CheckoutLayout::SplitReversed->value,
        'fields' => [['key' => 'name', 'label' => 'Your name', 'width' => 'half']],
        'delivery_options' => [
            ['label' => 'Inside Dhaka', 'amount' => 6000, 'detail' => '1–2 days'],
            ['label' => 'Outside Dhaka', 'amount' => 12000],
        ],
        'hide_lone_cash_choice' => false,
    ]);

    $html = $this->get('/promo')->assertOk()->getContent();
    $markup = checkoutMarkup($html);
    $script = checkoutScript($html);

    /**
     * The eyebrow over the cards counts what opens ticked and the footnote
     * under the button names the delivery charge the form opens on, each
     * figure wrapped so the script moves it as the customer ticks and
     * chooses — the same way the button carries the total, which this
     * design puts on the button. A narrow-column arrangement: the delivery
     * choice is a segmented control with no room for a detail line, and a
     * half-width question fills its row.
     */
    expect($markup)->toContain('<p class="gio-checkout-eyebrow">বান্ডেল · <span data-gio-count>2</span>টি নির্বাচিত / Bundle · <span data-gio-count>2</span> selected</p>')
        ->and($markup)->toContain('<h3 class="gio-checkout-heading">অর্ডার করতে ফর্মটি পূরণ করুন / Fill in the form to order</h3>')
        ->and($markup)->toContain('<p class="gio-checkout-footnote">ডেলিভারি <span data-gio-delivery>'.Money::format(6000).'</span> সহ / Delivery <span data-gio-delivery>'.Money::format(6000).'</span> included</p>')
        ->and($markup)->toContain('data-gio-submit-wording="অর্ডার করুন / Order Now · {total}"')
        ->and($markup)->toContain('>অর্ডার করুন / Order Now · '.Money::format(99000 + 149000 + 6000).'</button>')
        ->and($markup)->not->toContain('gio-checkout-choice-detail')
        ->and($markup)->not->toContain('gio-checkout-field-half')
        ->and($markup)->not->toContain('gio-checkout-number')
        ->and($markup)->toContain('<label class="gio-checkout-choice gio-checkout-delivery-choice">')
        ->and($markup)->toContain('<input class="gio-checkout-delivery-tick gio-checkout-tick"')
        ->and($markup)->toContain('<label class="gio-checkout-choice gio-checkout-payment-choice">');

    /** Every figure named twice moves twice: the script writes to every slot carrying it, not the first. */
    expect($script)->toContain("set('count', String(count));")
        ->and($script)->toContain("form.querySelectorAll('[data-gio-' + name + ']').forEach(");

    $block = CheckoutLayout::SplitReversed->styleRules();

    /**
     * Cards on a grey panel to the left with the subtotal pinned beneath
     * them, the form on the right with the button and footnote pinned
     * beneath it; the delivery choice a segmented control whose radios are
     * clipped from sight and never removed, so the keyboard still reaches
     * them; the payment choice plain radios with no box.
     */
    expect(styleBlock($html))->toContain($block)
        ->and($block)->toContain('.gio-checkout-split-reversed .gio-checkout-form::before { content: "";')
        ->and($block)->toContain('.gio-checkout-split-reversed .gio-checkout-summary { grid-column: 2; grid-row: 3 / 7; align-self: end;')
        ->and($block)->toContain('.gio-checkout-split-reversed .gio-checkout-questions { grid-column: 5;')
        ->and($block)->toContain('.gio-checkout-split-reversed .gio-checkout-delivery { display: flex; gap: 1px;')
        ->and($block)->toContain('.gio-checkout-split-reversed .gio-checkout-delivery-choice:has(:checked) { background: var(--gio-accent);')
        ->and($block)->toContain('.gio-checkout-split-reversed .gio-checkout-delivery-tick { position: absolute; width: 1px; height: 1px;')
        ->and($block)->toContain('.gio-checkout-split-reversed .gio-checkout-payment-choice { padding: .375rem 0; border: 0;')
        ->and($block)->toContain('.gio-checkout-split-reversed .gio-checkout-image { width: 4.5rem; height: 4.5rem; }')
        ->and($block)->toContain('@media (max-width: 37.5rem) {')
        ->and($block)->not->toContain('display: none')
        ->and($block)->not->toMatch('/[^a-z-]order:/');
});

it('counts every product into the bundle eyebrow where the page offers no choice', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket', 'price' => 99000]);
    $boots = Product::factory()->create(['name' => 'Hiking Boots', 'price' => 149000]);

    $page = pageStyled(['layout' => CheckoutLayout::SplitReversed->value]);
    $page->products()->attach([$jacket->getKey(), $boots->getKey()]);

    $markup = checkoutMarkup($this->get('/promo')->assertOk()->getContent());

    /** No picker means everything sells, and the count says so from the first paint. */
    expect($markup)->toContain('<span data-gio-count>3</span>');
});

it('draws the dark panel checkout as a navy summary beside a white form, in the layout\'s fixed navy and the design\'s words', function () {
    twoColumnPage(CheckoutLayout::DarkPanel);

    $html = $this->get('/promo')->assertOk()->getContent();
    $markup = checkoutMarkup($html);
    $styles = styleBlock($html);

    /**
     * An eyebrow over the products in the navy panel and a headline over the
     * form, the methods named by their short labels in centred boxes, the
     * total on the button, and — a roomy arrangement — the delivery detail
     * drawn and half-width questions paired.
     */
    expect($markup)->toContain('<p class="gio-checkout-eyebrow">আপনার অর্ডার / Order summary</p>')
        ->and($markup)->toContain('<h3 class="gio-checkout-heading">ডেলিভারি তথ্য / Delivery details</h3>')
        ->and($markup)->not->toContain('gio-checkout-number')
        ->and($markup)->toContain('gio-checkout-choice-label">COD<')
        ->and($markup)->not->toContain('Cash on Delivery<')
        ->and($markup)->toContain('<span class="gio-checkout-choice-detail">1–2 days</span>')
        ->and($markup)->toContain('class="gio-checkout-field gio-checkout-field-half"')
        ->and($markup)->toContain('data-gio-submit-wording="অর্ডার করুন / Order Now · {total}"');

    $block = CheckoutLayout::DarkPanel->styleRules();

    /**
     * The navy set is the layout's and not a token: written in this block,
     * shipped only to a page arranged in it, and absent from the base every
     * other page carries. The products and the totals sit over the navy
     * pseudo-element with the controls inverted to white; the form's inputs
     * are filled from the tokens.
     */
    expect($styles)->toContain($block)
        ->and($block)->toContain('.gio-checkout-dark-panel .gio-checkout-form::before { content: ""; grid-column: 1 / 4; grid-row: 1 / -1; align-self: stretch; background: #0f1d4f; }')
        ->and($block)->toContain('.gio-checkout-dark-panel .gio-checkout-product { gap: .75rem; border-color: #33468a; background: transparent; color: #fff; }')
        ->and($block)->toContain('.gio-checkout-dark-panel .gio-checkout-pick { accent-color: #fff; }')
        ->and($block)->toContain('.gio-checkout-dark-panel .gio-checkout-eyebrow { color: #9fb0e8; }')
        ->and($block)->toContain('.gio-checkout-dark-panel .gio-checkout-total { margin: 0; padding: .375rem 0 0; border-top: 0; font-size: 1.375rem; color: #fff; }')
        ->and($block)->toContain('.gio-checkout-dark-panel .gio-checkout-input { padding: .75rem; background: color-mix(in srgb, var(--gio-text) 3%, var(--gio-surface)); }')
        ->and($block)->toContain('.gio-checkout-dark-panel .gio-checkout-payment-choice { justify-content: center;')
        ->and($block)->toContain('@media (max-width: 37.5rem) {')
        ->and($block)->not->toContain('display: none')
        ->and($block)->not->toMatch('/[^a-z-]order:/')
        ->and(baseSheet($styles, CheckoutLayout::DarkPanel))->not->toContain('#0f1d4f');
});

it('draws the compact embed checkout as a narrow card, titled and counted in the design\'s words, with the delivery and the payment each a segmented control', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket', 'price' => 99000]);
    $boots = Product::factory()->create(['name' => 'Hiking Boots', 'price' => 149000]);
    $scarf = Product::factory()->create(['name' => 'Wool Scarf', 'price' => 29000]);

    pickingPage([$jacket, $boots, $scarf], [$jacket->getKey(), $boots->getKey()], [
        'layout' => CheckoutLayout::CompactEmbed->value,
        'fields' => [['key' => 'name', 'label' => 'Your name', 'width' => 'half']],
        'delivery_options' => [
            ['label' => 'Inside Dhaka', 'amount' => 6000, 'detail' => '1–2 days'],
            ['label' => 'Outside Dhaka', 'amount' => 12000],
        ],
        'hide_lone_cash_choice' => false,
    ]);

    $html = $this->get('/promo')->assertOk()->getContent();
    $markup = checkoutMarkup($html);

    /**
     * A title over the list with the count of what opens ticked at the far
     * end of the same row, the figure wrapped so the script keeps it truthful
     * as the customer ticks. A narrow-column arrangement: the methods are
     * named by their short labels, the delivery choice is a segmented
     * control with no room for a detail line, and a half-width question
     * fills its row.
     */
    expect($markup)->toContain('<h3 class="gio-checkout-heading">দ্রুত অর্ডার / Quick order</h3>')
        ->and($markup)->toContain('<p class="gio-checkout-count"><span data-gio-count>2</span>টি আইটেম / <span data-gio-count>2</span> items</p>')
        ->and($markup)->toContain('gio-checkout-choice-label">COD<')
        ->and($markup)->not->toContain('Cash on Delivery<')
        ->and($markup)->not->toContain('gio-checkout-choice-detail')
        ->and($markup)->not->toContain('gio-checkout-field-half')
        ->and($markup)->not->toContain('gio-checkout-number')
        ->and($markup)->not->toContain('gio-checkout-empty')
        ->and($markup)->toContain('<input class="gio-checkout-delivery-tick gio-checkout-tick"')
        ->and($markup)->toContain('<input class="gio-checkout-payment-tick gio-checkout-tick"');

    preg_match('/<div class="gio-checkout-section" data-gio-panel="[a-z]+">(.*?)<div class="gio-checkout-products">/s', $markup, $titleRow);

    /** The count follows the title inside the products section, so the sheet can set the two on one row. */
    expect($titleRow[1] ?? '')->toMatch('/<h3 class="gio-checkout-heading">[^<]*<\/h3>\s*<p class="gio-checkout-count">/');

    $block = CheckoutLayout::CompactEmbed->styleRules();

    /**
     * A card at the design's narrow width with the design's tight spacing,
     * the list boxed with hairlines between its rows, the delivery and the
     * payment each a segmented control whose radios are clipped from sight
     * and never removed, the totals on one line with the total at its far
     * end, and nothing hidden or reordered on a phone.
     */
    expect(styleBlock($html))->toContain($block)
        ->and($block)->toContain('.gio-checkout-compact-embed .gio-checkout-card { max-width: 25rem; padding: 1.25rem; }')
        ->and($block)->toContain('.gio-checkout-compact-embed .gio-checkout-section { display: grid; grid-template-columns: minmax(0, 1fr) auto;')
        ->and($block)->toContain('.gio-checkout-compact-embed .gio-checkout-products { grid-column: 1 / -1; gap: 0; border: 1px solid var(--gio-border); }')
        ->and($block)->toContain('.gio-checkout-compact-embed .gio-checkout-delivery { display: flex; gap: 1px;')
        ->and($block)->toContain('.gio-checkout-compact-embed .gio-checkout-delivery-choice:has(:checked) { background: var(--gio-accent); color: var(--gio-surface); }')
        ->and($block)->toContain('.gio-checkout-compact-embed .gio-checkout-delivery-tick { position: absolute; width: 1px; height: 1px;')
        ->and($block)->toContain('.gio-checkout-compact-embed .gio-checkout-payment { display: flex; gap: 1px;')
        ->and($block)->toContain('.gio-checkout-compact-embed .gio-checkout-payment-choice:has(:checked) { background: var(--gio-accent); color: var(--gio-surface); }')
        ->and($block)->toContain('.gio-checkout-compact-embed .gio-checkout-payment-tick { position: absolute; width: 1px; height: 1px;')
        ->and($block)->toContain('.gio-checkout-compact-embed .gio-checkout-quantity-step { width: 1.375rem; height: 1.375rem;')
        ->and($block)->toContain('.gio-checkout-compact-embed .gio-checkout-summary { display: flex;')
        ->and($block)->toContain('.gio-checkout-compact-embed .gio-checkout-total { margin: 0 0 0 auto; padding: 0; border-top: 0; font-size: 1rem; }')
        ->and($block)->toContain('.gio-checkout-compact-embed .gio-checkout-submit { margin: 0; padding: .8125rem; font-size: .875rem; }')
        ->and($block)->toContain('@media (max-width: 37.5rem) {')
        ->and($block)->toContain('.gio-checkout-compact-embed .gio-checkout-card { padding: 1.25rem 1rem; }')
        ->and($block)->not->toContain('display: none')
        ->and($block)->not->toMatch('/[^a-z-]order:/');
});

it('draws the chips checkout as pills to tap on and off, a line for nothing ticked, and eyebrows over the list, the delivery and the payment', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket', 'price' => 99000]);
    $boots = Product::factory()->create(['name' => 'Hiking Boots', 'price' => 149000]);

    pickingPage([$jacket, $boots], [], [
        'layout' => CheckoutLayout::Chips->value,
        'fields' => [
            ['key' => 'name', 'label' => 'Your name', 'width' => 'half'],
            ['key' => 'phone', 'label' => 'Phone number', 'width' => 'half'],
        ],
        'delivery_options' => [
            ['label' => 'Inside Dhaka', 'amount' => 6000, 'detail' => '1–2 days'],
            ['label' => 'Outside Dhaka', 'amount' => 12000],
        ],
        'hide_lone_cash_choice' => false,
    ]);

    $html = $this->get('/promo')->assertOk()->getContent();
    $markup = checkoutMarkup($html);
    $styles = styleBlock($html);

    /**
     * Three eyebrows in the design's words, the methods named in full by
     * plain radios, the delivery choice a segmented control with no room for
     * a detail line, and half-width questions paired: the card is wide.
     */
    expect($markup)->toContain('<p class="gio-checkout-eyebrow">আইটেম বাছুন / Select items</p>')
        ->and($markup)->toContain('<p class="gio-checkout-eyebrow">ডেলিভারি / Delivery</p>')
        ->and($markup)->toContain('<p class="gio-checkout-eyebrow">পেমেন্ট / Payment</p>')
        ->and($markup)->toContain('gio-checkout-choice-label">ক্যাশ অন ডেলিভারি / Cash on Delivery<')
        ->and($markup)->not->toContain('gio-checkout-choice-detail')
        ->and($markup)->toContain('class="gio-checkout-field gio-checkout-field-half"')
        ->and($markup)->not->toContain('gio-checkout-number');

    preg_match('/<div class="gio-checkout-section" data-gio-panel="[a-z]+">(.*?)<div class="gio-checkout-questions">/s', $markup, $products);

    /**
     * The empty-state line sits right after the list it is about and before
     * the refusal for an order of nothing, drawn in the markup whether or
     * not anything is ticked: the sheet shows it only while a list that can
     * be ticked has nothing ticked, so a visitor whose scripts never run
     * still reads why the total is zero.
     */
    expect($products[1] ?? '')->toMatch('/<\/div>\s*<p class="gio-checkout-empty">কোনো প্রোডাক্ট সিলেক্ট করা হয়নি \/ Nothing selected<\/p>\s*<p class="gio-checkout-error gio-checkout-pick-error"/')
        ->and(baseSheet($styles, CheckoutLayout::Chips))->toContain('.gio-checkout-empty { display: none;')
        ->and(baseSheet($styles, CheckoutLayout::Chips))->toContain('.gio-checkout-products:has(.gio-checkout-pick):not(:has(:checked)) + .gio-checkout-empty { display: block; }');

    $block = CheckoutLayout::Chips->styleRules();

    /**
     * The list is a row of pills that fill with the accent when ticked and
     * grow a stepper; the line beneath the empty list is ruled off; the
     * delivery choice is segmented and the payment choice plain; the totals
     * sit beside the button in the ruled-off footer, the total set large;
     * and nothing is hidden or reordered on a phone.
     */
    expect($styles)->toContain($block)
        ->and($block)->toContain('.gio-checkout-chips .gio-checkout-card { max-width: 40rem; padding: 2.25rem; }')
        ->and($block)->toContain('.gio-checkout-chips .gio-checkout-products { display: flex; flex-wrap: wrap; gap: .5rem; }')
        ->and($block)->toContain('.gio-checkout-chips .gio-checkout-product:has(:checked) { border-color: var(--gio-accent); background: var(--gio-accent); color: var(--gio-surface); }')
        ->and($block)->toContain('.gio-checkout-chips .gio-checkout-empty { margin: 1rem 0 0; padding: 1rem 0 0; border-top: 1px solid var(--gio-border); }')
        ->and($block)->toContain('.gio-checkout-chips .gio-checkout-delivery { display: flex; gap: 1px;')
        ->and($block)->toContain('.gio-checkout-chips .gio-checkout-delivery-choice:has(:checked) { background: var(--gio-accent); color: var(--gio-surface); }')
        ->and($block)->toContain('.gio-checkout-chips .gio-checkout-payment-choice { padding: .375rem 0; border: 0; background: transparent; }')
        ->and($block)->toContain('.gio-checkout-chips .gio-checkout-summary { grid-column: 1; grid-row: 3;')
        ->and($block)->toContain('.gio-checkout-chips .gio-checkout-submit { grid-column: 2; grid-row: 3;')
        ->and($block)->toContain('.gio-checkout-chips .gio-checkout-total { margin: 0; padding: 0; border-top: 0; font-size: 1.5rem; }')
        ->and($block)->toContain('@media (max-width: 37.5rem) {')
        ->and($block)->not->toContain('display: none')
        ->and($block)->not->toMatch('/[^a-z-]order:/');
});

it('draws the bento grid checkout as three bordered cells with numbered eyebrows and a dark bar carrying the totals and the button', function () {
    twoColumnPage(CheckoutLayout::BentoGrid);

    $html = $this->get('/promo')->assertOk()->getContent();
    $markup = checkoutMarkup($html);
    $styles = styleBlock($html);

    /**
     * An eyebrow over each cell, numbered in the design's words, the methods
     * named by their short labels in a two-by-two grid, the delivery choice
     * segmented with no room for a detail line, and half-width questions
     * paired: a cell has the room.
     */
    expect($markup)->toContain('<p class="gio-checkout-eyebrow">০১ · প্রোডাক্ট / 01 · Products</p>')
        ->and($markup)->toContain('<p class="gio-checkout-eyebrow">০২ · আপনার তথ্য / 02 · Your details</p>')
        ->and($markup)->toContain('<p class="gio-checkout-eyebrow">০৩ · পেমেন্ট / 03 · Payment</p>')
        ->and($markup)->toContain('gio-checkout-choice-label">COD<')
        ->and($markup)->not->toContain('Cash on Delivery<')
        ->and($markup)->not->toContain('gio-checkout-choice-detail')
        ->and($markup)->toContain('class="gio-checkout-field gio-checkout-field-half"')
        ->and($markup)->not->toContain('gio-checkout-number');

    $block = CheckoutLayout::BentoGrid->styleRules();

    /**
     * The cells sit on the page's ground with no card around them: each
     * section is a bordered cell, the products on the left and the two
     * others stacked on the right. The bar beneath is the form's own
     * pseudo-element filled from the text token, with the totals on the left
     * and the button on the right and the button's own hover — the bar's
     * quieter grey and that hover are the layout's, written here and absent
     * from the base.
     * Nothing hidden or reordered on a phone.
     */
    expect($styles)->toContain($block)
        ->and($block)->toContain('.gio-checkout-bento-grid .gio-checkout-card { max-width: 57.5rem; padding: 0; border: 0; background: transparent; }')
        ->and($block)->toContain('.gio-checkout-bento-grid .gio-checkout-form::after { content: ""; grid-column: 1 / -1; grid-row: 3 / 7; align-self: stretch; background: var(--gio-text); }')
        ->and($block)->toContain('.gio-checkout-bento-grid .gio-checkout-section { grid-column: 1 / 3; grid-row: 1; margin: 0; padding: 1.25rem; border: 1px solid var(--gio-border); background: var(--gio-surface); }')
        ->and($block)->toContain('.gio-checkout-bento-grid .gio-checkout-questions { display: flex; flex-direction: column;')
        ->and($block)->toContain('.gio-checkout-bento-grid .gio-checkout-delivery { display: flex; gap: 1px;')
        ->and($block)->toContain('.gio-checkout-bento-grid .gio-checkout-payment { grid-template-columns: 1fr 1fr; gap: .5rem; }')
        ->and($block)->toContain('.gio-checkout-bento-grid .gio-checkout-summary { display: flex;')
        ->and($block)->toContain('.gio-checkout-bento-grid .gio-checkout-total { margin: 0; padding: 0; border-top: 0; font-size: 1.125rem; color: var(--gio-surface); }')
        ->and($block)->toContain('.gio-checkout-bento-grid .gio-checkout-submit { grid-column: 4; grid-row: 4;')
        ->and($block)->toContain('.gio-checkout-bento-grid .gio-checkout-submit:hover { background: #4f79e6; }')
        ->and($block)->toContain('@media (max-width: 37.5rem) {')
        ->and($block)->toContain('.gio-checkout-bento-grid .gio-checkout-submit { grid-column: 2; grid-row: 7; justify-self: stretch; width: 100%; margin: .875rem 0 0; }')
        ->and($block)->not->toContain('display: none')
        ->and($block)->not->toMatch('/[^a-z-]order:/')
        ->and(baseSheet($styles, CheckoutLayout::BentoGrid))->not->toContain('#b9bec9')
        ->and(baseSheet($styles, CheckoutLayout::BentoGrid))->not->toContain('#4f79e6');
});

it('draws the invoice checkout as a titled bill: headed columns over the item rows, underlined answers and a receipt of totals', function () {
    $page = twoColumnPage(CheckoutLayout::Invoice);
    $product = $page->refresh()->products->first();

    $html = $this->get('/promo')->assertOk()->getContent();
    $markup = checkoutMarkup($html);
    $styles = styleBlock($html);

    /**
     * A title over the bill and a heading over each of its three columns, in
     * the design's words; the methods named at length, because a bill has the
     * room; a delivery option's detail line drawn under its name for the same
     * reason; and half-width questions paired.
     */
    expect($markup)->toContain('<h3 class="gio-checkout-heading">অর্ডার ফর্ম / Order form</h3>')
        ->and($markup)->toContain('<span class="gio-checkout-column-heading">Item</span><span class="gio-checkout-column-heading">Qty</span><span class="gio-checkout-column-heading">Amount</span>')
        ->and($markup)->toContain('gio-checkout-choice-label">ক্যাশ অন ডেলিভারি / Cash on Delivery<')
        ->and($markup)->toContain('<span class="gio-checkout-choice-detail">1–2 days</span>')
        ->and($markup)->toContain('class="gio-checkout-field gio-checkout-field-half"')
        ->and($markup)->not->toContain('gio-checkout-number')
        ->and($markup)->not->toContain('gio-checkout-empty');

    /**
     * The button asks the Customer to agree to the bill rather than to order
     * from it, and says so in the design's words with no figure on it: a
     * silent Merchant gets the layout's own wording, and nothing rides on the
     * button for the script to refill.
     */
    expect($markup)->toContain('data-gio-submit>অর্ডার কনফার্ম করুন / Place order</button>')
        ->and($markup)->not->toContain('data-gio-submit-wording');

    /** No order number anywhere: there is no Order to number until the Capture. */
    expect($markup)->not->toContain('#GP-')
        ->and($markup)->not->toContain('GP-0001');

    preg_match('/<div class="gio-checkout-section" data-gio-panel="[a-z]+">(.*?)<div class="gio-checkout-products">/s', $markup, $head);

    /**
     * The headings follow the title and precede the list they head, so the
     * sheet can place the four of them in one grid — and a customer listening
     * rather than looking hears the columns named before the rows.
     */
    expect($head[1] ?? '')->toMatch('/<h3 class="gio-checkout-heading">[^<]*<\/h3>\s*<span class="gio-checkout-column-heading">/');

    /**
     * And they are the only spans the section opens with, which is what the
     * sheet leans on: `:first-of-type` puts the first heading over the name
     * and `:last-of-type` aligns the last to the right, and both read the
     * spans of the section rather than the headings. Draw a fourth span
     * before the list and the columns silently move — fix the block with it.
     */
    expect(substr_count($head[1] ?? '', '<span'))->toBe(3)
        ->and(substr_count($head[1] ?? '', '<span class="gio-checkout-column-heading">'))->toBe(3);

    $block = CheckoutLayout::Invoice->styleRules();

    /**
     * The bill: a title ruled off in the design's two-pixel ink, the section
     * and every row the same four-column grid so the headings sit over the
     * columns beneath them, the list's own top border drawing the one
     * continuous rule under the headings, answers on underlines with no box
     * around them, the totals a right-aligned monospace receipt ruled off
     * above the total, and a button in the text token that turns the accent
     * on hover. Nothing hidden or reordered on a phone: the table drops to
     * two columns and the headings are clipped, never removed.
     */
    expect($styles)->toContain($block)
        ->and($block)->toContain('.gio-checkout-invoice .gio-checkout-card { max-width: 38.75rem; padding: 2.5rem 2rem; }')
        ->and($block)->toContain('.gio-checkout-invoice .gio-checkout-heading { grid-column: 1 / -1; margin: 0 0 1.75rem; padding: 0 0 .625rem; border-bottom: 2px solid var(--gio-text); font-size: 1.25rem; }')
        ->and($block)->toContain('.gio-checkout-invoice .gio-checkout-section { display: grid; grid-template-columns: 1.5rem minmax(0, 1fr) 4.75rem 4.5rem;')
        ->and($block)->toContain('.gio-checkout-invoice .gio-checkout-product { display: grid; grid-template-columns: 1.5rem minmax(0, 1fr) 4.75rem 4.5rem;')
        ->and($block)->toContain('.gio-checkout-invoice .gio-checkout-column-heading:first-of-type { grid-column: 2; }')
        ->and($block)->toContain('.gio-checkout-invoice .gio-checkout-column-heading:last-of-type { text-align: right; }')
        ->and($block)->toContain('.gio-checkout-invoice .gio-checkout-products { grid-column: 1 / -1; gap: 0; border-top: 1px solid var(--gio-border); }')
        ->and($block)->toContain('.gio-checkout-invoice .gio-checkout-input { padding: .5rem 0; border: 0; border-bottom: 1px solid color-mix(in srgb, var(--gio-muted) 45%, var(--gio-border)); border-radius: 0; background: transparent; font-size: .9375rem; }')
        ->and($block)->toContain('.gio-checkout-invoice .gio-checkout-summary { display: flex; flex-direction: column; align-items: flex-end; grid-column: 1; grid-row: 3;')
        ->and($block)->toContain('font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }')
        ->and($block)->toContain('.gio-checkout-invoice .gio-checkout-total { margin: .25rem 0 0; padding: .375rem 0 0; border-top: 1px solid var(--gio-text); font-size: 1rem; }')
        ->and($block)->toContain('.gio-checkout-invoice .gio-checkout-submit { justify-content: space-between; grid-column: 1; grid-row: 7; margin: 0; padding: 1rem 1.375rem; background: var(--gio-text); color: var(--gio-surface); }')
        ->and($block)->toContain('.gio-checkout-invoice .gio-checkout-submit:hover { background: var(--gio-accent); }')
        ->and($block)->toContain('@media (max-width: 37.5rem) {')
        ->and($block)->toContain('.gio-checkout-invoice .gio-checkout-column-heading { position: absolute; width: 1px; height: 1px;')
        ->and($block)->not->toContain('display: none')
        ->and($block)->not->toMatch('/[^a-z-]order:/');

    /**
     * The document is the one every layout writes: the bill's rows are the
     * product rows, in the order they were always in, and the totals are the
     * summary the sheet lifts above the questions rather than a second one.
     */
    expect($markup)->toContain($product->name)
        ->and(substr_count($markup, 'data-gio-total'))->toBe(1);
});

it('draws the stepper checkout as a rail over one white panel, worded step by step and totalled on the button', function () {
    $page = twoColumnPage(CheckoutLayout::Stepper);
    $product = $page->refresh()->products->first();

    $html = $this->get('/promo')->assertOk()->getContent();
    $markup = checkoutMarkup($html);
    $styles = styleBlock($html);

    /** A headline over each of the three panels, in the design's words and with no badge: the rail carries the numbers. */
    expect($markup)->toContain('<h3 class="gio-checkout-heading">কোন প্রোডাক্টগুলো নিবেন? / Choose your items</h3>')
        ->and($markup)->toContain('<h3 class="gio-checkout-heading">কোথায় পাঠাবো? / Where should we deliver?</h3>')
        ->and($markup)->toContain('<h3 class="gio-checkout-heading">পেমেন্ট ও কনফার্ম / Payment &amp; confirm</h3>')
        ->and($markup)->not->toContain('gio-checkout-number');

    /**
     * The words for the rail and for Next and Back ride on the form, because
     * the controls they sit on are the script's and are in no page's markup:
     * a rail that does nothing and a Next that goes nowhere would be a form
     * making promises it cannot keep for a visitor whose scripts never run.
     */
    expect($markup)->toContain('data-gio-cue-stepper-products-step="প্রোডাক্ট"')
        ->and($markup)->toContain('data-gio-cue-stepper-details-step="ডেলিভারি"')
        ->and($markup)->toContain('data-gio-cue-stepper-payment-step="পেমেন্ট"')
        ->and($markup)->toContain('data-gio-cue-stepper-next="পরবর্তী / Next →"')
        ->and($markup)->toContain('data-gio-cue-stepper-back="← Back"')
        ->and($markup)->not->toContain('gio-checkout-steps')
        ->and($markup)->not->toContain('gio-checkout-nav')
        ->and($markup)->not->toContain('gio-checkout-step-number');

    /** The design puts the live total inside the last step's button, and a silent merchant gets it. */
    $total = Money::format((int) $product->price + 6000);

    expect($markup)->toContain('data-gio-submit-wording="'.OrderButton::DEFAULT_WORDING.' · '.OrderButton::TOTAL_PLACEHOLDER.'"')
        ->and($markup)->toContain(OrderButton::DEFAULT_WORDING.' · '.$total.'</button>');

    $block = CheckoutLayout::Stepper->styleRules();

    /**
     * The card gives up its border and its ground so the rail the script
     * builds above the form sits on the section's own; the form is the design's
     * white panel, and its children are the panels one at a time. The one
     * `display: none` in the block is what `hidden` means — an author's
     * `display` beats the browser's, so a layout that hides by script has to
     * say so — and nothing in the markup carries the attribute.
     */
    expect($styles)->toContain($block)
        ->and($block)->toContain('.gio-checkout-stepper .gio-checkout-card { max-width: 37.5rem; padding: 0; border: 0; background: transparent; }')
        ->and($block)->toContain('.gio-checkout-stepper .gio-checkout-form { display: grid; gap: 1rem; align-content: start; min-height: 18.75rem; padding: 1.5rem; border: 1px solid var(--gio-border); background: var(--gio-surface); }')
        ->and($block)->toContain('.gio-checkout-stepper .gio-checkout-questions { display: contents; }')
        ->and($block)->toContain('.gio-checkout-stepper .gio-checkout-step-number { display: grid; place-items: center;')
        ->and($block)->toContain('.gio-checkout-stepper .gio-checkout-step-number[data-gio-done] { border-color: var(--gio-accent); background: var(--gio-accent); color: var(--gio-surface); }')
        ->and($block)->toContain('.gio-checkout-stepper .gio-checkout-step-line[data-gio-done] { background: var(--gio-accent); }')
        ->and($block)->toContain('.gio-checkout-stepper [hidden] { display: none; }')
        ->and(substr_count($block, 'display: none'))->toBe(1)
        ->and($block)->toContain('.gio-checkout-stepper .gio-checkout-step-label { position: absolute; width: 1px; height: 1px;')
        ->and($block)->not->toMatch('/[^a-z-]order:/');

    /** And the document is the one every layout writes: every product, every question, the totals. */
    expect($markup)->toContain($product->name)
        ->and(substr_count($markup, 'data-gio-total'))->toBe(1);
});

it('draws the accordion checkout as three panels under one border with a total bar at the foot', function () {
    $page = twoColumnPage(CheckoutLayout::Accordion);
    $product = $page->refresh()->products->first();

    $html = $this->get('/promo')->assertOk()->getContent();
    $markup = checkoutMarkup($html);
    $styles = styleBlock($html);

    /**
     * A headline per panel in the design's words, which the script lifts into
     * the header button it builds inside it. The heading stays a heading, so
     * the words are in the document whether or not the script ever runs.
     */
    expect($markup)->toContain('<h3 class="gio-checkout-heading">প্রোডাক্ট / Products</h3>')
        ->and($markup)->toContain('<h3 class="gio-checkout-heading">ডেলিভারি / Delivery</h3>')
        ->and($markup)->toContain('<h3 class="gio-checkout-heading">পেমেন্ট / Payment</h3>')
        ->and($markup)->not->toContain('gio-checkout-number');

    /**
     * The header button, its summary and its chevron are the script's, and so
     * is every word in a summary: the accordion owns the three headers on the
     * Words tab and nothing else, so it carries no cue on the form.
     */
    expect($markup)->not->toContain('gio-checkout-panel-head')
        ->and($markup)->not->toContain('gio-checkout-panel-summary')
        ->and($markup)->not->toContain('data-gio-cue-');

    /** The button is the bar's, beside the total rather than carrying it. */
    expect($markup)->toContain('data-gio-submit>অর্ডার করুন / Order Now</button>')
        ->and($markup)->not->toContain('data-gio-submit-wording');

    $block = CheckoutLayout::Accordion->styleRules();

    /**
     * One border around the whole of it, which is the card's; each panel
     * painted in the surface over a card painted in the bar's ink, so what is
     * left beneath the last panel reads as the footer bar the design draws —
     * a pseudo-element could not, because how many panels a page draws is not
     * something the sheet knows. A shut panel keeps its header and loses its
     * padding; the one `display: none` is what `hidden` means.
     */
    expect($styles)->toContain($block)
        ->and($block)->toContain('.gio-checkout-accordion .gio-checkout-card { max-width: 38.75rem; padding: 0; background: color-mix(in srgb, var(--gio-text) 3%, var(--gio-surface)); }')
        ->and($block)->toContain('.gio-checkout-accordion .gio-checkout-questions { display: contents; }')
        ->and($block)->toContain('border-bottom: 1px solid var(--gio-border); background: var(--gio-surface); }')
        ->and($block)->toContain('.gio-checkout-accordion .gio-checkout-section[data-gio-shut] { padding-bottom: 0; }')
        ->and($block)->toContain('.gio-checkout-accordion .gio-checkout-panel-head[aria-expanded="true"] { background: color-mix(in srgb, var(--gio-text) 3%, var(--gio-surface)); }')
        ->and($block)->toContain('.gio-checkout-accordion .gio-checkout-total { margin: 0; padding: 0; border-top: 0; font-size: 1.375rem; }')
        ->and($block)->toContain('.gio-checkout-accordion .gio-checkout-submit { grid-column: 4; width: auto; margin: 1rem 0; padding: .8125rem 1.75rem; }')
        ->and($block)->toContain('.gio-checkout-accordion [hidden] { display: none; }')
        ->and(substr_count($block, 'display: none'))->toBe(1)
        ->and($block)->toContain('.gio-checkout-accordion .gio-checkout-panel-summary { position: absolute; width: 1px; height: 1px;')
        ->and($block)->not->toMatch('/[^a-z-]order:/');

    expect($markup)->toContain($product->name)
        ->and(substr_count($markup, 'data-gio-total'))->toBe(1);
});

it('writes every panel of a behaving layout in document order and hides none of them by markup', function (CheckoutLayout $layout) {
    $page = twoColumnPage($layout);
    $product = $page->refresh()->products->first();

    $markup = checkoutMarkup($this->get('/promo')->assertOk()->getContent());

    /** The form and everything under it, which is all the behaviour ever touches. */
    $form = substr($markup, (int) strpos($markup, '<form'));

    /**
     * The half of ADR-0015 that the behaviour is allowed on: a visitor whose
     * scripts never run reads all three panels, in the order the document
     * writes them, and orders from the form as it stands. Nothing in the form
     * is shut, opened or waived — no `hidden`, no `novalidate`, no state a
     * header or a step would carry — because every one of those is the
     * script's to apply, and applying it in the markup would take the order
     * away from the visitor who cannot undo it.
     */
    expect($layout->behaves())->toBeTrue()
        ->and($form)->not->toMatch('/\shidden[\s=>]/')
        ->and($form)->not->toContain('novalidate')
        ->and($form)->not->toContain('aria-expanded')
        ->and($form)->not->toContain('data-gio-shut')
        ->and($form)->not->toContain('data-gio-done');

    $panels = ['data-gio-panel="products"', 'data-gio-panel="details"', 'data-gio-panel="payment"'];

    $positions = array_map(fn (string $panel): int => (int) strpos($markup, $panel), $panels);
    $sorted = $positions;
    sort($sorted);

    foreach ($panels as $panel) {
        expect($markup)->toContain($panel);
    }

    /** And every panel holds what it always held: the products, the questions, the methods. */
    expect($positions)->toBe($sorted)
        ->and($markup)->toContain($product->name)
        ->and($markup)->toContain('name="phone"')
        ->and($markup)->toContain('name="delivery_option"')
        ->and($markup)->toContain('name="payment_method"');
})->with([
    'stepper' => CheckoutLayout::Stepper,
    'accordion' => CheckoutLayout::Accordion,
]);

it('draws a layout\'s own words in the merchant\'s where there are any, the design\'s where there are none, and never one the words tab would refuse', function (CheckoutLayout $layout, WordSlot $reworded, string $words, string $drawn, WordSlot $refused, WordSlot $untouched) {
    twoColumnPage($layout, ['words' => [
        $reworded->value => $words,
        $refused->value => str_repeat('x', 200),
    ]]);

    $markup = checkoutMarkup($this->get('/promo')->assertOk()->getContent());

    /**
     * The merchant's sentence stands where they wrote one, the design's
     * where they did not, and a sentence too long to draw falls back to the
     * design's silently — the tab having already refused it where there was
     * a merchant to tell.
     */
    expect($markup)->toContain($drawn)
        ->and($markup)->not->toContain(str_repeat('x', 200))
        ->and($markup)->toContain(e($refused->defaultValue()))
        ->and($markup)->toContain(e($untouched->defaultValue()))
        ->and(Page::where('slug', 'promo')->sole()->checkout_config->words->slot($reworded))->toBe($words)
        ->and(Page::where('slug', 'promo')->sole()->checkout_config->words->slot($refused))->toBe($refused->defaultValue());
})->with([
    'split' => [CheckoutLayout::Split, WordSlot::SplitHint, 'আমরা ফোন করব / Expect our call', '<p class="gio-checkout-footnote">আমরা ফোন করব / Expect our call</p>', WordSlot::SplitOrderHeadline, WordSlot::SplitPaymentHeadline],
    'split reversed' => [CheckoutLayout::SplitReversed, WordSlot::SplitReversedBundleEyebrow, 'কী নিলেন / Picked {count}', '<p class="gio-checkout-eyebrow">কী নিলেন / Picked <span data-gio-count>1</span></p>', WordSlot::SplitReversedFormHeadline, WordSlot::SplitReversedFormHeadline],
    'dark panel' => [CheckoutLayout::DarkPanel, WordSlot::DarkPanelFormHeadline, 'কোথায় পাঠাব / Where to send it', '<h3 class="gio-checkout-heading">কোথায় পাঠাব / Where to send it</h3>', WordSlot::DarkPanelSummaryEyebrow, WordSlot::DarkPanelSummaryEyebrow],
    'bento grid' => [CheckoutLayout::BentoGrid, WordSlot::BentoGridDetailsEyebrow, 'আপনি কে / Who you are', '<p class="gio-checkout-eyebrow">আপনি কে / Who you are</p>', WordSlot::BentoGridProductsEyebrow, WordSlot::BentoGridPaymentEyebrow],
    'compact embed' => [CheckoutLayout::CompactEmbed, WordSlot::CompactEmbedItemCount, 'নিলেন {count} / Took {count}', '<p class="gio-checkout-count">নিলেন <span data-gio-count>1</span> / Took <span data-gio-count>1</span></p>', WordSlot::CompactEmbedTitle, WordSlot::CompactEmbedTitle],
    'chips' => [CheckoutLayout::Chips, WordSlot::ChipsDeliveryEyebrow, 'কোথায় / Where to', '<p class="gio-checkout-eyebrow">কোথায় / Where to</p>', WordSlot::ChipsSelectEyebrow, WordSlot::ChipsPaymentEyebrow],
    'invoice' => [CheckoutLayout::Invoice, WordSlot::InvoiceQuantityHeading, 'কয়টি', '<span class="gio-checkout-column-heading">কয়টি</span>', WordSlot::InvoiceTitle, WordSlot::InvoiceAmountHeading],
    'stepper' => [CheckoutLayout::Stepper, WordSlot::StepperDetailsHeadline, 'কোথায় দিব / Where to', '<h3 class="gio-checkout-heading">কোথায় দিব / Where to</h3>', WordSlot::StepperProductsHeadline, WordSlot::StepperPaymentHeadline],
    'a stepper control' => [CheckoutLayout::Stepper, WordSlot::StepperNext, 'এগিয়ে যান', 'data-gio-cue-stepper-next="এগিয়ে যান"', WordSlot::StepperBack, WordSlot::StepperDetailsStep],
    'accordion' => [CheckoutLayout::Accordion, WordSlot::AccordionDetailsHeadline, 'কোথায় / Where', '<h3 class="gio-checkout-heading">কোথায় / Where</h3>', WordSlot::AccordionProductsHeadline, WordSlot::AccordionPaymentHeadline],
]);

it('draws each layout\'s own words on that layout alone', function (CheckoutLayout $layout) {
    /**
     * The slot's own name, marked so no word is a substring of another and
     * nothing else on the page reads as one. Short, because a word on a
     * control is refused above two dozen characters and a slot the Config
     * dropped would look here exactly like a slot the layout never drew.
     */
    $said = fn (WordSlot $slot): string => '~'.$slot->value;

    $everyWord = [];

    foreach (WordSlot::cases() as $slot) {
        $everyWord[$slot->value] = $said($slot);
    }

    twoColumnPage($layout, ['words' => $everyWord]);

    $page = Page::where('slug', 'promo')->sole();
    $markup = checkoutMarkup($this->get('/promo')->assertOk()->getContent());

    /**
     * A merchant who has worded every slot of every layout sees, on any one
     * page, exactly the words of the layout it is arranged in: the rest are
     * kept in the Config for the day the page is arranged their way.
     */
    foreach (WordSlot::cases() as $slot) {
        expect($page->checkout_config->words->slot($slot))->toBe($said($slot), $slot->value.' stored')
            ->and(str_contains($markup, $said($slot)))->toBe($layout->draws($slot), $slot->value.' on '.$layout->value);
    }
})->with(fn () => CheckoutLayout::cases());

it('keeps every layout\'s block two classes deep, each rule under the layout\'s own class', function (CheckoutLayout $layout) {
    $block = $layout->styleRules();

    if ($block === '') {
        expect($block)->toBe('');

        return;
    }

    preg_match_all('/([^{}]+)\{[^{}]*\}/', $block, $rules);

    /**
     * The one place the sheet outranks a merchant at two classes, and the
     * ceiling: every selector is the layout's class and one component class,
     * so a merchant who names an ancestor of their own wins. Never three
     * classes, never a child combinator, never an id, never `!important`.
     */
    expect($rules[1])->not->toBeEmpty();

    foreach ($rules[1] as $selectors) {
        foreach (explode(',', $selectors) as $selector) {
            $selector = trim($selector);

            expect($selector)->toStartWith('.'.$layout->modifierClass().' ')
                ->and(preg_match_all('/\.gio-checkout-[a-z-]+/', $selector))->toBeLessThanOrEqual(2)
                ->and($selector)->not->toContain('>')
                ->and($selector)->not->toContain('#');
        }
    }

    expect($block)->not->toContain('!important');
})->with(fn () => CheckoutLayout::cases());

it('puts the refusal for an order of nothing inside the product section it is about', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket', 'price' => 99000]);
    $boots = Product::factory()->create(['name' => 'Hiking Boots', 'price' => 149000]);

    pickingPage([$jacket, $boots], [], ['layout' => CheckoutLayout::Split->value]);

    $markup = checkoutMarkup($this->get('/promo')->assertOk()->getContent());

    /**
     * Beside the boxes it is about, inside the section that holds them, so
     * a two-column arrangement places the products and their refusal in one
     * move and the questions never sit between them.
     */
    preg_match('/<div class="gio-checkout-section" data-gio-panel="[a-z]+">(.*?)<div class="gio-checkout-questions">/s', $markup, $products);

    expect($products[1] ?? '')->toContain('<p class="gio-checkout-error gio-checkout-pick-error" data-gio-error-for="selected"></p>')
        ->and(substr_count($markup, 'data-gio-error-for="selected"'))->toBe(1);
});
