<?php

use App\Enums\PaymentMethod;
use App\Models\Page;
use App\Models\Product;
use App\Shortcodes\Node;
use App\Support\Checkout\CheckoutConfig;
use App\Support\Checkout\CheckoutLayout;
use App\Support\Checkout\FieldWidth;
use App\Support\Checkout\OrderButton;
use App\Support\Checkout\StyleToken;
use App\Support\Checkout\StyleTokenKind;
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

it('serves the stored document byte for byte', function () {
    $page = Page::factory()->published()->create(['slug' => 'promo']);

    /**
     * The merchant owns the whole document, `<head>` included. Anything we wrap
     * around it or inject into it looks to them like the product broke their
     * page.
     */
    expect($this->get('/promo')->getContent())->toBe($page->body);
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
        'input_size' => '1.125',
    ]]);

    $styles = styleBlock($this->get('/promo')->assertOk()->getContent());

    expect($styles)->toContain('--gio-accent: #ff0000;')
        ->and($styles)->toContain('--gio-button-background: #101010;')
        ->and($styles)->toContain('--gio-radius: 12px;')
        ->and($styles)->toContain('--gio-input-size: 1.125;')
        /**
         * Read by the rules that were already there rather than written into
         * them, which is what makes one redeclaration restyle every use.
         */
        ->and($styles)->toContain('background: var(--gio-button-background)')
        ->and($styles)->toContain('border-radius: var(--gio-radius)');
});

it('sizes an input\'s padding and its text off one scale', function () {
    pageStyled([]);

    preg_match('/\.gio-checkout-input \{([^}]*)\}/', styleBlock($this->get('/promo')->getContent()), $input);

    /**
     * The padding is measured against the font size the scale sets, so there is
     * no way to ask for a bigger box and get type too small to read back what
     * was typed into it.
     */
    expect($input[1])->toContain('var(--gio-input-size)')
        ->and($input[1])->toMatch('/padding:[^;]*em/')
        ->and(array_filter(
            StyleToken::cases(),
            fn (StyleToken $token): bool => $token->kind() === StyleTokenKind::Scale,
        ))->toHaveCount(1);
});

it('restyles one question without generating a selector for it', function () {
    pageStyled(['fields' => [
        ['key' => 'name', 'label' => 'Your name', 'style_tokens' => ['border' => '#ff0000']],
        ['key' => 'phone', 'label' => 'Phone number'],
    ]]);

    $html = $this->get('/promo')->assertOk()->getContent();

    /**
     * The override rides on the field's own wrapper and the cascade keeps it
     * there. Nothing names the field in the style block, which is what stops
     * the style editor out-specifying the merchant's own stylesheet.
     */
    expect(styleBlock($html))->not->toContain('#ff0000')
        ->and(substr_count($html, '--gio-border: #ff0000;'))->toBe(1);
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
        'input_size' => '9',
        'error' => 'reddish',
        'selected' => 'var(--gio-error)',
    ]]);

    $html = $this->get('/promo')->assertOk()->getContent();

    /**
     * The Checkout tab refuses all three with a message. This is the same
     * refusal made again as the Config is read, for the Config nobody typed
     * on that tab — silent, and falling back to the default rather than
     * rendering a page whose styling has been rewritten by its own data.
     */
    expect($html)->not->toContain('body {')
        ->and(styleBlock($html))->toContain('--gio-accent: '.StyleToken::Accent->defaultValue().';')
        ->and(styleBlock($html))->toContain('--gio-radius: '.StyleToken::Radius->defaultValue().';')
        ->and(styleBlock($html))->toContain('--gio-input-size: '.StyleToken::InputSize->defaultValue().';')
        ->and(styleBlock($html))->toContain('--gio-error: '.StyleToken::Error->defaultValue().';')
        /** A `var()` is refused like any other non-colour, so the fallback is the accent and not whatever was named. */
        ->and(styleBlock($html))->toContain('--gio-selected: var(--gio-accent);');
});

it('writes a refusal in the error token', function () {
    pageStyled(['style_tokens' => ['error' => '#ff8080']]);

    $styles = styleBlock($this->get('/promo')->assertOk()->getContent());

    preg_match('/\.gio-checkout-error \{([^}]*)\}/', $styles, $error);

    /**
     * A refusal beside a box reads the same token the banner does, so a
     * merchant whose page is dark lightens every failure message in one
     * place — the default is a red drawn for a white page, and on a dark one
     * it is the one thing in the form a customer cannot read.
     */
    expect($styles)->toContain('--gio-error: #ff8080;')
        ->and($error[1] ?? '')->toContain('color: var(--gio-error)')
        ->and($error[1] ?? '')->not->toContain('#b91c1c');
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
     * The banner paints its own ground in the error token and writes on it in
     * white, so what it says is legible on a page styled dark without the
     * merchant having touched a colour — the one failure message that must
     * never be invisible is the one that turned the whole order away.
     */
    expect($shown[1] ?? '')->toContain('background: var(--gio-error)')
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

it('lets a field override a token the checkout root also reads', function () {
    pageStyled(['fields' => [
        ['key' => 'name', 'label' => 'Your name', 'style_tokens' => ['text' => '#ff0000', 'font_size' => '2rem']],
    ]]);

    preg_match('/\.gio-checkout-field \{([^}]*)\}/', styleBlock($this->get('/promo')->getContent()), $field);

    /**
     * `color`, `font-size` and `accent-color` are inherited as computed values,
     * so a wrapper redeclaring the token behind one cannot reach back and
     * change what the root already worked out. Only a declaration on the
     * wrapper itself reads what the wrapper carries — which is why these three
     * are declared twice, and why overriding them on one question does
     * anything at all.
     */
    expect($field[1])->toContain('color: var(--gio-text)')
        ->and($field[1])->toContain('font-size: var(--gio-font-size)')
        ->and($field[1])->toContain('accent-color: var(--gio-selected)');
});

it('sizes the rows a customer picks from off the same scale as the boxes', function () {
    pageStyled([]);

    $styles = styleBlock($this->get('/promo')->getContent());

    preg_match('/\.gio-checkout-choice \{([^}]*)\}/', $styles, $choice);

    /**
     * A radio row is a box a thumb has to hit, so one size covers both. A form
     * asked for at Large that grew its text boxes and left its delivery options
     * where they were would be the mismatch the single scale exists to prevent.
     */
    expect($choice[1])->toContain('var(--gio-input-size)')
        ->and(substr_count($choice[1], 'var(--gio-input-size)'))->toBe(3);
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

it('draws no panel around the island', function () {
    pageStyled([]);

    $styles = styleBlock($this->get('/promo')->assertOk()->getContent());

    preg_match('/\.gio-checkout \{([^}]*)\}/', $styles, $root);

    /**
     * The island asserts no box. A merchant who wants one adds it in their own
     * stylesheet, which is what the flat single-class sheet exists to allow —
     * and with nothing to inset from, the root carries no padding either, so
     * the form sits flush with whatever element the shortcode was dropped in.
     */
    expect($root[1] ?? '')->not->toMatch('/(^|;)\s*border:/')
        ->and($root[1] ?? '')->not->toMatch('/(^|;)\s*padding:/')
        ->and($root[1] ?? '')->not->toContain('border-radius:');
});

it('borders each product row and rules off the sections around it', function () {
    pageStyled([]);

    $styles = styleBlock($this->get('/promo')->assertOk()->getContent());

    preg_match('/\.gio-checkout-product \{([^}]*)\}/', $styles, $row);
    preg_match('/\.gio-checkout-products \{([^}]*)\}/', $styles, $panel);
    preg_match('/\.gio-checkout-summary \{([^}]*)\}/', $styles, $summary);

    /**
     * A product row is a thing being bought, and it is boxed like the boxes a
     * customer types into: the same border token, the same width, the same
     * radius, so one redeclaration restyles all of them together.
     */
    expect($row[1] ?? '')->toContain('border: var(--gio-border-width) solid var(--gio-border)')
        ->and($row[1] ?? '')->toContain('border-radius: var(--gio-radius)');

    /**
     * Between the sections, a rule and nothing heavier: beneath the products
     * and above the summary, read from the same border tokens.
     */
    expect($panel[1] ?? '')->toContain('border-bottom: var(--gio-border-width) solid var(--gio-border)')
        ->and($summary[1] ?? '')->toContain('border-top: var(--gio-border-width) solid var(--gio-border)');
});

it('flanks a real numeric quantity with a minus and a plus', function () {
    $page = pageStyled([]);
    $product = $page->refresh()->products->first();

    $html = $this->get('/promo')->assertOk()->getContent();
    $markup = checkoutMarkup($html);

    preg_match('/<div class="gio-checkout-stepper">(.*?)<\/div>/s', $markup, $stepper);

    expect($stepper)->not->toBeEmpty();

    /**
     * Minus, the box, plus — in that order, so a thumb finds them where a cart
     * puts them. The buttons are `type="button"`: a button inside a form
     * submits it unless told otherwise, and a customer tapping plus must never
     * place an order.
     */
    expect($stepper[1])->toMatch('/<button class="gio-checkout-step" type="button"[^>]*data-gio-step="-1".*<input[^>]*data-gio-quantity.*<button class="gio-checkout-step" type="button"[^>]*data-gio-step="1"/s');

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
    preg_match_all('/<label class="gio-checkout-choice">(.*?)<\/label>/s', $delivery[1] ?? '', $rows);

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
     * same border token, width and radius. The words take what is left of the
     * row so the figure sits at the far end, and the figure never wraps — a
     * price broken across two lines is a price misread.
     */
    expect($row[1] ?? '')->toContain('border: var(--gio-border-width) solid var(--gio-border)')
        ->and($row[1] ?? '')->toContain('border-radius: var(--gio-radius)')
        ->and($words[1] ?? '')->toContain('flex: 1')
        ->and($words[1] ?? '')->toContain('min-width: 0')
        ->and($price[1] ?? '')->toContain('white-space: nowrap');
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
     * from the dot inside its radio: border, ring and a light tint of its
     * ground, all read from the one selected-state token. Where a browser
     * cannot read `:has()` the radio still shows, so nothing is lost that was
     * there before.
     */
    expect($chosen[1] ?? '')->toContain('border-color: var(--gio-selected)')
        ->and($chosen[1] ?? '')->toContain('box-shadow: 0 0 0 1px var(--gio-selected)')
        ->and($chosen[1] ?? '')->toMatch('/background: color-mix\([^;]*var\(--gio-selected\)[^;]*transparent\)/');

    preg_match_all('/<input type="radio"[^>]*name="delivery_option"[^>]*>/s', checkoutMarkup($html), $radios);

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
     * at a glance what they have committed to. One token paints the whole of
     * the selected state — the tick, the row's border and a tint of its
     * ground — for a product and a delivery row alike, so a merchant changes
     * what "picked" looks like in one place. The tick is `accent-color`,
     * which inherits as a computed value, so the field wrapper reads the
     * token itself the way it already does for text and size.
     */
    expect($picked[1] ?? '')->toContain('border-color: var(--gio-selected)')
        ->and($picked[1] ?? '')->toContain('box-shadow: 0 0 0 1px var(--gio-selected)')
        ->and($picked[1] ?? '')->toMatch('/background: color-mix\([^;]*var\(--gio-selected\)[^;]*transparent\)/')
        ->and($root[1] ?? '')->toContain('accent-color: var(--gio-selected)')
        ->and($field[1] ?? '')->toContain('accent-color: var(--gio-selected)');
});

it('lets the selected state follow the accent until a merchant says otherwise', function () {
    pageStyled([], slug: 'plain');
    pageStyled(['style_tokens' => ['selected' => '#ff0000']], slug: 'styled');
    pageStyled(['fields' => [
        ['key' => 'colour', 'label' => 'Colour', 'type' => 'radio', 'options' => [['value' => 'red'], ['value' => 'blue']], 'style_tokens' => ['selected' => '#00ff00']],
    ]], slug: 'one-question');

    $plain = styleBlock($this->get('/plain')->assertOk()->getContent());
    $styled = styleBlock($this->get('/styled')->assertOk()->getContent());
    $oneQuestion = $this->get('/one-question')->assertOk()->getContent();

    /**
     * Unset, the token is declared as the accent rather than as a fixed
     * colour, so a merchant who set only an accent gets a selected state to
     * match and a page published before the token existed looks the same as
     * it did. Set, it is one declaration on the root like every other token:
     * the block is the same length either way, and a question restyling it
     * rides on that question's own wrapper with no selector generated for it.
     */
    expect($plain)->toContain('--gio-selected: var(--gio-accent);')
        ->and($styled)->toContain('--gio-selected: #ff0000;')
        ->and(substr_count($styled, '{'))->toBe(substr_count($plain, '{'))
        ->and(styleBlock($oneQuestion))->toBe($plain)
        ->and(substr_count($oneQuestion, '--gio-selected: #00ff00;'))->toBe(1);
});

it('keeps a question\'s ticks in the accent that question was given', function () {
    pageStyled(['fields' => [
        ['key' => 'colour', 'label' => 'Colour', 'type' => 'radio', 'options' => [['value' => 'red'], ['value' => 'blue']], 'style_tokens' => ['accent' => '#ff0000']],
        ['key' => 'size', 'label' => 'Size', 'type' => 'radio', 'options' => [['value' => 's'], ['value' => 'm']], 'style_tokens' => ['accent' => '#ff0000', 'selected' => '#00ff00']],
    ]]);

    $html = $this->get('/promo')->assertOk()->getContent();

    /**
     * The root resolves `--gio-selected` against the accent once, and a
     * wrapper inherits that resolved colour — so a question redeclaring only
     * its accent would keep its focus ring and lose its ticks, on a page
     * published before the selected colour existed. The wrapper therefore
     * says again that an unset selected colour follows the accent, and only
     * where the question set an accent for it to follow. A question that set
     * both is emitted as it said.
     */
    expect($html)->toContain('style="--gio-accent: #ff0000; --gio-selected: var(--gio-accent);"')
        ->and($html)->toContain('style="--gio-accent: #ff0000; --gio-selected: #00ff00;"');
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
    expect($total[1] ?? '')->toContain('border-top: var(--gio-border-width) solid var(--gio-border)')
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

it('hides the product list on any layout, and draws everything else the layout draws', function (string $layout) {
    $page = pageStyled(['layout' => $layout, 'show_products' => false]);

    $html = $this->get('/promo')->assertOk()->getContent();

    /**
     * The switch is the merchant's and the layout is the merchant's, and one
     * says nothing about the other: hiding the list is a thing any arrangement
     * can do, so the arrangement keeps its class and its rules and loses only
     * the panel.
     */
    expect($html)->toContain('class="gio-checkout '.CheckoutLayout::from($layout)->modifierClass().'"')
        ->and(checkoutMarkup($html))->not->toContain('class="gio-checkout-products"')
        ->and($html)->toContain('name="quantities['.$page->products->first()->getKey().']" value="1"')
        ->and($html)->toContain('data-gio-subtotal')
        ->and($html)->toContain('data-gio-error-for="page"');
})->with(['stacked', 'split', 'compact', 'summary_first']);

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

    expect(str_replace(['value="retired"', 'data-page-slug="retired"'], ['value="switched"', 'data-page-slug="switched"'], $retiredHtml))->toBe($switchedHtml)
        ->and($retiredHtml)->not->toContain('Winter Jacket')
        ->and($retiredHtml)->toContain('gio-checkout-stacked')
        ->and($retiredHtml)->toContain('name="quantities['.$product->getKey().']" value="1"');
});

it('posts one of everything a checkout with its product list hidden sells', function () {
    $page = pageStyled(['show_products' => false]);
    $page->products()->attach(Product::factory()->create());

    $html = $this->get('/promo')->assertOk()->getContent();

    /**
     * The panel is what goes; the Order is not. Every product the page sells
     * still posts a quantity, keyed by product id like the stepper it stands in
     * for, so the Capture checks and writes exactly what it would from the
     * stacked form.
     */
    foreach ($page->refresh()->products as $product) {
        expect($html)->toContain('name="quantities['.$product->getKey().']" value="1"');
    }

    expect($page->products)->toHaveCount(2);
});

it('totals a checkout with its product list hidden off the same line markers as one showing it', function () {
    $page = pageStyled(['show_products' => false]);

    $html = $this->get('/promo')->assertOk()->getContent();

    /**
     * The script totals what it finds, and what it finds is the same in both
     * layouts. A layout the island had to branch on would be a second summary
     * to keep truthful, and a total reading low by anything is a parcel refused
     * at the door.
     */
    expect(substr_count(checkoutMarkup($html), 'data-gio-line'))->toBe($page->products->count())
        ->and($html)->toContain('data-unit-price="'.$page->products->first()->price.'"')
        ->and($html)->toContain('data-gio-subtotal')
        ->and($html)->toContain('data-gio-total');
});

it('draws every question, the delivery choice, the summary and the error slots in every layout', function (string $layout) {
    $page = pageStyled([
        'layout' => $layout,
        'fields' => [
            ['key' => 'phone', 'label' => 'Phone number'],
            ['key' => 'district', 'label' => 'District'],
            ['key' => 'note', 'label' => 'Gift note', 'type' => 'textarea'],
            ['key' => 'floor', 'label' => 'Floor', 'type' => 'number'],
            ['key' => 'size', 'label' => 'Size', 'type' => 'radio', 'options' => [['value' => 's'], ['value' => 'm']]],
            ['key' => 'wrap', 'label' => 'Gift wrap it', 'type' => 'checkbox'],
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
})->with(['stacked', 'split', 'compact', 'summary_first']);

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
         * first block to an appearance the day one is written.
         */
        foreach (CheckoutLayout::cases() as $other) {
            if ($other === $layout || $other->styleRules() === '') {
                continue;
            }

            expect($styles)->not->toContain($other->styleRules());
        }

        foreach (CheckoutLayout::cases() as $other) {
            if ($other !== $layout) {
                expect($styles)->not->toContain($other->modifierClass());
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
     * is a rule rather than a copy.
     */
    expect($this->get('/promo')->getContent())->toContain('class="gio-checkout '.$class.'"');
})->with([
    'stacked' => ['stacked', 'gio-checkout-stacked'],
    'split' => ['split', 'gio-checkout-split'],
    'compact' => ['compact', 'gio-checkout-compact'],
    'summary first' => ['summary_first', 'gio-checkout-summary-first'],
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

it('draws a compact checkout as one dense row per product', function () {
    $page = pageStyled(['layout' => 'compact']);
    $product = $page->refresh()->products->first();

    $html = $this->get('/promo')->assertOk()->getContent();
    $markup = checkoutMarkup($html);
    $styles = styleBlock($html);

    /**
     * Compact is an arrangement of the same markup and never a second template.
     * The customer still meets a picture, a name, a price and a stepper of
     * their own for every product the page sells — what a merchant chose is how
     * that row is drawn, not whether they can see what they are about to buy.
     */
    expect($markup)->toContain('class="gio-checkout-products"')
        ->and($markup)->toContain($product->name)
        ->and($markup)->toContain('gio-checkout-product-head')
        ->and($markup)->toContain('data-gio-quantity');

    /**
     * The density itself is the block, and the block is the whole of it: the
     * row laid out along one line, the picture cut down, the name and the price
     * beside each other, and the description out of the way. A second template
     * would be a second thing to keep truthful about a form that takes money.
     */
    expect($styles)->toContain('.gio-checkout-compact .gio-checkout-product { display: flex;')
        ->and($styles)->toContain('.gio-checkout-compact .gio-checkout-image {')
        ->and($styles)->toContain('.gio-checkout-compact .gio-checkout-price { font-size: 1em; }')
        ->and($styles)->toContain('.gio-checkout-compact .gio-checkout-description { display: none; }');
});

it('lets a compact page still ask which products to buy', function () {
    $page = pageStyled(['layout' => 'compact', 'product_picker' => true]);

    /** A choice of one is not a choice, so the page has to sell two before it asks. */
    $page->products()->attach(Product::factory()->create());

    $html = $this->get('/promo')->assertOk()->getContent();
    $markup = checkoutMarkup($html);

    /**
     * Compact lays a product row out along one line rather than down a grid,
     * and the tick has to survive the change of arrangement: the box is still
     * drawn, still beside the row it picks, and the stepper it reveals is still
     * revealed by it. The rule that does the revealing reads siblings, which is
     * what makes it hold in a flex row as it did in the grid — a picker that
     * quietly stopped hiding steppers would ask a customer how many of
     * something they have not chosen.
     */
    expect($markup)->toContain('name="selected[]"')
        ->and($markup)->toContain('class="gio-checkout-product gio-checkout-pickable"')
        ->and(styleBlock($html))->toContain('.gio-checkout-pick:not(:checked) ~ .gio-checkout-quantity { display: none; }')
        ->and($page->refresh()->checkout_config->picksProducts())->toBeTrue();
});

it('puts the compact block after the base sheet in the page it serves', function () {
    pageStyled(['layout' => 'compact']);

    $styles = styleBlock($this->get('/promo')->assertOk()->getContent());

    /**
     * The half of "last inside the one sheet" that could not be read out of a
     * served page while every block was empty, and can be now: compact narrows
     * the root's own padding and margin at one class, exactly as the base
     * declares them, so what makes it the one that applies is that it is
     * written afterwards. A block emitted before the base would leave a
     * compacted page drawn at the spacing it was compacted out of, with nothing
     * failing.
     */
    expect(strpos($styles, '.gio-checkout-compact {'))
        ->toBeGreaterThan(strpos($styles, '.gio-checkout-note {'))
        ->and(strpos($styles, '.gio-checkout-compact {'))
        ->toBeGreaterThan(strpos($styles, '.gio-checkout {'));
});

it('fills every question\'s row on a compact checkout, at every width and whatever the merchant chose', function () {
    $page = pageStyled([
        'layout' => 'compact',
        'fields' => [
            ['key' => 'name', 'label' => 'Your name', 'width' => 'half'],
            ['key' => 'phone', 'label' => 'Phone number', 'width' => 'half'],
        ],
    ]);

    $html = $this->get('/promo')->assertOk()->getContent();
    $styles = styleBlock($html);

    preg_match('/@media \(min-width: [^)]+\) \{.*?\} \}/s', $styles, $wide);

    /** Or the sheet below is the whole sheet, and what it is read for goes unread. */
    expect($wide)->not->toBeEmpty();

    $narrow = str_replace($wide[0], '', $styles);

    /**
     * Both halves of the pairing undone, and undone outside the media query, so
     * a compact page is one column at every screen size rather than at phone
     * sizes only. The span goes with the second column: a question still told
     * to cross two columns of a one-column grid would grow the second one back.
     */
    expect($narrow)->toContain('.gio-checkout-compact .gio-checkout-fields { grid-template-columns: 1fr;')
        ->and($narrow)->toContain('.gio-checkout-compact .gio-checkout-field { grid-column: auto; }');

    /**
     * And the width the merchant set is still on the wrapper and still in the
     * Config. Compact ignores it; it does not rewrite it — which is what a page
     * moved back off compact reads to pair its questions the way it always did.
     */
    expect(substr_count(checkoutMarkup($html), 'class="gio-checkout-field gio-checkout-field-half"'))->toBe(2)
        ->and($page->refresh()->checkout_config->fields[0]->width)->toBe(FieldWidth::Half);
});

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

it('leads a summary-first checkout with the total and leaves the questions beneath it', function () {
    $page = pageStyled([
        'layout' => 'summary_first',
        'fields' => [['key' => 'phone', 'label' => 'Phone number']],
    ]);

    $product = $page->refresh()->products->first();

    $html = $this->get('/promo')->assertOk()->getContent();
    $markup = checkoutMarkup($html);
    $styles = styleBlock($html);

    /**
     * The same island drawn by the same loop, and the bytes are still in the
     * order every other arrangement writes them: the products, the questions,
     * then the summary. What a summary-first page moves, it moves in the sheet
     * — a second template would be a second total to keep truthful about a form
     * that takes money, and the order the bytes are in is the order a customer
     * whose stylesheet never arrives reads them in.
     */
    expect($markup)->toContain('class="gio-checkout-products"')
        ->and($markup)->toContain($product->name)
        ->and($markup)->toContain('name="phone"')
        ->and($markup)->toContain('data-gio-total')
        ->and(strpos($markup, 'class="gio-checkout-fields"'))->toBeLessThan(strpos($markup, 'class="gio-checkout-summary"'));

    /**
     * The questions and the two things below them are what move, rather than
     * the summary being pulled to the top: a total follows the lines it totals,
     * and the rule the base draws above it is what sets it apart from them. So
     * the products keep the top of the island, the summary keeps its place
     * under them, and exactly three things are pushed below it — the questions,
     * the button and the line beneath the button. A fourth carrying an order
     * would be something drawn out of the place the arrangement puts it.
     */
    preg_match_all('/\.gio-checkout-summary-first (\.[a-z-]+) \{[^}]*order:/', $styles, $ordered);

    expect($styles)->toContain('.gio-checkout-summary-first .gio-checkout-form { display: flex; flex-direction: column; }')
        ->and($ordered[1])->toBe(['.gio-checkout-fields', '.gio-checkout-submit', '.gio-checkout-note']);
});

it('never puts a summary-first total behind an interaction', function () {
    pageStyled(['layout' => 'summary_first'], slug: 'summary-first');
    pageStyled([], slug: 'stacked');

    $html = $this->get('/summary-first')->assertOk()->getContent();
    $markup = checkoutMarkup($html);

    preg_match_all('/\.gio-checkout-summary-first [^{]*\{[^}]*\}/', styleBlock($html), $rules);

    /** Or the arrangement adds nothing, and what is read below goes unread. */
    expect($rules[0])->not->toBeEmpty();

    /**
     * Expanded, always, and nothing anywhere to collapse it: no disclosure in
     * the markup, and nothing in the block this arrangement adds that hides,
     * clips or measures anything. A page arranged this way leads with the
     * figure a customer is agreeing to hand over in cash at their door, and a
     * disclosure would put that figure behind a tap — on the one arrangement
     * chosen because the selling is already done.
     */
    expect($markup)->toContain('data-gio-subtotal')
        ->and($markup)->toContain('data-gio-total')
        ->and($markup)->not->toContain('<details')
        ->and($markup)->not->toContain('<summary')
        ->and(implode('', $rules[0]))->not->toContain('display: none')
        ->and(implode('', $rules[0]))->not->toContain('max-height')
        ->and(implode('', $rules[0]))->not->toContain('overflow');

    /**
     * And the arrangement asks for no script of its own: the island ships the
     * same bytes it ships to a stacked page, so a summary-first checkout on a
     * phone whose scripts never ran still leads with its total.
     */
    expect(checkoutScript($html))->toBe(checkoutScript($this->get('/stacked')->assertOk()->getContent()));
});

/**
 * The rules a split page's sheet carries, keyed by the selector that opens
 * each one.
 *
 * Read as a map rather than matched as bytes so that what a test pins is which
 * column a thing lands in, rather than the order the declarations happen to be
 * written in.
 *
 * @return array<string, string>
 */
function splitRules(string $styles): array
{
    preg_match_all('/(\.gio-checkout-split[^{]*)\{([^}]*)\}/', $styles, $rules, PREG_SET_ORDER);

    return array_combine(
        array_map(fn (array $rule): string => trim($rule[1]), $rules),
        array_map(fn (array $rule): string => trim($rule[2]), $rules),
    );
}

it('sets a split checkout\'s form beside what the customer is buying and keeps the total on screen', function () {
    $page = pageStyled([
        'layout' => 'split',
        'fields' => [['key' => 'phone', 'label' => 'Phone number']],
    ]);

    $product = $page->refresh()->products->first();

    $html = $this->get('/promo')->assertOk()->getContent();
    $markup = checkoutMarkup($html);
    $rules = splitRules(styleBlock($html));

    /**
     * The same island drawn by the same loop, and the bytes still in the order
     * every other arrangement writes them: the products, the questions, then
     * the summary. Two columns are made in the sheet — a second template would
     * be a second total to keep truthful about a form that takes money, and
     * this order is the one a customer whose stylesheet never arrives reads.
     */
    expect($markup)->toContain('class="gio-checkout-products"')
        ->and($markup)->toContain($product->name)
        ->and($markup)->toContain('name="phone"')
        ->and(strpos($markup, 'class="gio-checkout-products"'))->toBeLessThan(strpos($markup, 'class="gio-checkout-fields"'))
        ->and(strpos($markup, 'class="gio-checkout-fields"'))->toBeLessThan(strpos($markup, 'class="gio-checkout-summary"'));

    expect($rules['.gio-checkout-split .gio-checkout-form'])->toContain('display: grid');

    /**
     * The form column first and everything a customer answers, is refused in,
     * or taps kept in it. The failure slots are named outright rather than
     * left to auto-placement: they are `display: none` until the island writes
     * to them, so the first one a customer ever saw would be placed into
     * whatever cell happened to be free — which is the panel.
     */
    foreach (['.gio-checkout-fields', '.gio-checkout-error', '.gio-checkout-submit', '.gio-checkout-note'] as $answered) {
        expect($rules['.gio-checkout-split '.$answered])->toContain('grid-column: 1');
    }

    /**
     * And the panel beside it, in the order the bytes are in: what is being
     * bought above the figure it comes to, the summary opening directly under
     * the products rather than as far down the page as the form is long.
     */
    expect($rules['.gio-checkout-split .gio-checkout-products'])->toContain('grid-column: 2')
        ->and($rules['.gio-checkout-split .gio-checkout-products'])->toContain('grid-row: 1')
        ->and($rules['.gio-checkout-split .gio-checkout-summary'])->toContain('grid-column: 2')
        ->and($rules['.gio-checkout-split .gio-checkout-summary'])->toContain('grid-row: 2');

    /**
     * The pin, which is the whole reason for the arrangement: a customer
     * working down the questions never loses sight of the figure they are
     * agreeing to hand over in cash. It is on the summary rather than on the
     * panel above it because a sticky box is bounded by its own area, and the
     * area as tall as the form is the one the summary shares with it.
     */
    expect($rules['.gio-checkout-split .gio-checkout-summary'])->toContain('position: sticky')
        ->and($rules['.gio-checkout-split .gio-checkout-summary'])->toContain('align-self: start');
});

it('sizes a split checkout by the form column the merchant asked for', function () {
    pageStyled(['layout' => 'split', 'style_tokens' => ['max_width' => '24rem']]);

    $styles = styleBlock($this->get('/promo')->assertOk()->getContent());
    $rules = splitRules($styles);

    preg_match('/\.gio-checkout \{([^}]*)\}/', $styles, $root);

    /**
     * The width means here exactly what it means everywhere else: how wide the
     * part the customer types into sits. So it sizes the form column, and the
     * container is worked out from it rather than the other way round — a
     * merchant who set a narrow form and then chose split would otherwise get
     * that figure honoured as two columns too narrow to type in, with nothing
     * failing and nothing to tell them why.
     */
    expect($root[1] ?? '')->toContain('--gio-max-width: 24rem')
        ->and($rules['.gio-checkout-split'])->toContain('max-width: calc(var(--gio-max-width) +');

    /**
     * And the token is filled by the merchant and by nobody else. A layout
     * that quietly redeclared it here would be a default the checkout tab does
     * not show and the merchant cannot see they are fighting.
     */
    foreach ($rules as $declarations) {
        expect($declarations)->not->toContain('--gio-max-width:');
    }
});

it('reserves around a split checkout\'s form column exactly what it takes back out', function () {
    pageStyled(['layout' => 'split', 'style_tokens' => ['max_width' => '50%']]);

    $styles = styleBlock($this->get('/promo')->assertOk()->getContent());
    $rules = splitRules($styles);

    preg_match('/\.gio-checkout \{[^}]*padding: ([^;]+);/', $styles, $padding);
    preg_match('/max-width: calc\(var\(--gio-max-width\) \+ (\d+)rem/', $rules['.gio-checkout-split'], $reserved);
    preg_match('/minmax\(0, calc\(100% - (\d+)rem\)\)/', $rules['.gio-checkout-split .gio-checkout-form'], $taken);

    /** Or the arithmetic below is read off nothing, and agrees with itself for that reason. */
    expect($reserved)->not->toBeEmpty()
        ->and($taken)->not->toBeEmpty();

    /**
     * The container is the merchant's figure plus what stands beside it, and
     * the form column is the container's own box with that same thing taken
     * back out — so the figure comes out as the form column whatever unit it
     * was typed in. The width the merchant is offered may be a percentage, and
     * a percentage written into the track instead would be measured against
     * the container rather than against the element the shortcode sits in:
     * half of a half, honoured with nothing failing and nothing to tell them.
     */
    expect($rules['.gio-checkout-split .gio-checkout-form'])->not->toContain('var(--gio-max-width)');

    /**
     * The two figures are the same figure, because the root has no edges of
     * its own for them to differ by — no padding and no border, since the
     * island draws no panel. Read rather than assumed, because the file that
     * would break this arrangement is not the file this arrangement is
     * written in: a padding put back on the root has to be added to what the
     * container reserves, or the form column comes out narrower than the
     * merchant's figure by exactly that much.
     */
    expect($padding)->toBeEmpty()
        ->and((int) $reserved[1])->toBe((int) $taken[1]);
});

it('draws one rule between the products and the total wherever the two meet', function (CheckoutLayout $layout) {
    pageStyled(['layout' => $layout->value]);

    $styles = styleBlock($this->get('/promo')->assertOk()->getContent());

    preg_match('/\.'.$layout->modifierClass().' \.gio-checkout-products \{([^}]*)\}/', $styles, $panel);

    /**
     * The base rules the products off beneath them and the summary off above
     * it, which is one hairline between each pair of sections on a stacked
     * page. These two arrangements put the summary directly beneath the
     * products, so the base's two rules would meet as a double line — the
     * panel gives its own up, and the summary's stands.
     */
    expect($panel[1] ?? '')->toContain('border-bottom: 0')
        ->and($panel[1] ?? '')->toContain('padding: 0');
})->with(['split' => CheckoutLayout::Split, 'summary first' => CheckoutLayout::SummaryFirst]);

it('draws a split checkout as the stacked one below the breakpoint', function () {
    pageStyled(['layout' => 'split'], slug: 'split');
    pageStyled([], slug: 'stacked');

    $html = $this->get('/split')->assertOk()->getContent();
    $styles = styleBlock($html);
    $added = CheckoutLayout::Split->styleRules();

    /**
     * Every rule the arrangement adds is inside one media query, which is the
     * whole of the fallback: below it there is nothing to apply, so a customer
     * on a phone reads the products, the questions, the summary and the button
     * exactly as the base sheet draws them. There is no second arrangement to
     * maintain for the device most of this traffic arrives on.
     */
    preg_match('/^@media \(min-width: [^)]+\) \{(.+)\}$/s', trim($added), $inside);

    expect($inside)->not->toBeEmpty();

    foreach (array_filter(array_map(trim(...), explode('}', $inside[1]))) as $rule) {
        expect($rule)->toContain('.gio-checkout-split');
    }

    /** Nothing of the arrangement outside that one block, in the sheet actually served. */
    expect(str_replace($added, '', $styles))->not->toContain('gio-checkout-split');

    /**
     * And the arrangement asks for no script of its own, so a split page whose
     * scripts never ran takes the same order the stacked one does.
     */
    expect(checkoutScript($html))->toBe(checkoutScript($this->get('/stacked')->assertOk()->getContent()));
});

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
        ->toContain('<div class="gio-checkout-field">')
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

it('boxes the checkout at the width the page asks for', function () {
    pageStyled(['style_tokens' => ['max_width' => '48rem']]);

    $styles = styleBlock($this->get('/promo')->assertOk()->getContent());

    /**
     * Width is a token read by the rule that was already there, not a layout
     * and not a class. That is what makes "boxed at a maximum" and "full width"
     * the same declaration carrying a different value.
     */
    expect($styles)->toContain('--gio-max-width: 48rem;')
        ->and($styles)->toContain('max-width: var(--gio-max-width)');
});

it('fills the element the shortcode sits in when the width says so', function () {
    pageStyled(['style_tokens' => ['max_width' => '100%']]);

    /**
     * A percentage resolves against the element the merchant put [checkout] in,
     * which is exactly what full width means here: the form fills what it was
     * placed in and stops there.
     */
    expect(styleBlock($this->get('/promo')->getContent()))->toContain('--gio-max-width: 100%;');
});

it('refuses a width that would escape the element the shortcode sits in', function (string $width) {
    pageStyled(['style_tokens' => ['max_width' => $width]]);

    /**
     * A viewport unit is the width of the phone rather than of the container,
     * so a checkout dropped into a padded column would break out of the column
     * and out of the merchant's design. Refused on the Checkout tab with a
     * message and dropped again here without one, so the block is safe
     * whichever route wrote the Config.
     */
    expect(styleBlock($this->get('/promo')->assertOk()->getContent()))
        ->toContain('--gio-max-width: '.StyleToken::MaxWidth->defaultValue().';');
})->with([
    'the whole viewport' => '100vw',
    'the taller side of it' => '80vh',
    'a form no wider than nothing' => '0',
    'a value that would close the declaration' => '30rem; } body { display: none } .x {',
]);

it('lets a paired question be narrower than the box it types in', function () {
    pageStyled(['style_tokens' => ['max_width' => '20rem']]);

    $styles = styleBlock($this->get('/promo')->assertOk()->getContent());

    preg_match('/\.gio-checkout-field \{([^}]*)\}/', $styles, $field);

    /**
     * A grid item's `min-width` is `auto`, which is its content's min-content
     * width — and an input's is about twenty characters however wide we draw
     * it. Two columns narrower than that would push the grid past the checkout
     * and out of the element the merchant put the shortcode in, which is the
     * one thing the island must never do. It is reachable by boxing the form
     * narrow and opening it on a desktop, so the floor is removed rather than
     * argued with.
     */
    expect($field[1])->toContain('min-width: 0;')
        ->and($styles)->toContain('--gio-max-width: 20rem;');
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
     * the way the design does. No template rides on the button either: there
     * is no placeholder for the script to fill.
     */
    expect($markup)->toContain('data-gio-submit>অর্ডার করুন / Order Now</button>')
        ->and($markup)->toContain('<p class="gio-checkout-note">পার্সেল হাতে পেয়ে নগদে পরিশোধ করুন / Pay in cash when the parcel reaches you.</p>')
        ->and($markup)->not->toContain('data-gio-submit-wording')
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
    expect($markup)->toContain('data-gio-submit>অর্ডার করুন / Order Now</button>')
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
    preg_match_all('/<label class="gio-checkout-choice">(.*?)<\/label>/s', $payment[1] ?? '', $rows);

    /**
     * One row, already picked, drawn as a delivery row is: the control, then
     * the logo, then the words — and inside the grid of questions, so every
     * layout arranges it with them. The radio is a real one in a real form,
     * so a visitor whose script never runs still posts a method.
     */
    expect($payment[1] ?? '')->toContain('<legend class="gio-checkout-label">Payment</legend>')
        ->and($rows[1])->toHaveCount(1)
        ->and($rows[1][0])->toMatch('/type="radio"[^>]*name="payment_method"[^>]*value="cash_on_delivery"[^>]*checked required>.*<img class="gio-checkout-logo" src="[^"]*\/images\/payment-methods\/cash-on-delivery\.svg" alt="".*gio-checkout-choice-label">ক্যাশ অন ডেলিভারি \/ Cash on Delivery</s')
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

it('keeps the layout block last when a chooser adds its own', function () {
    pageStyled(['hide_lone_cash_choice' => false, 'layout' => CheckoutLayout::Compact->value]);

    $styles = styleBlock($this->get('/promo')->assertOk()->getContent());

    /** The layout still sits last so that what it narrows it narrows by order, the chooser's rule included. */
    expect(strpos($styles, '.gio-checkout-logo {'))->toBeLessThan((int) strpos($styles, '.gio-checkout-compact {'))
        ->and(baseSheet($styles, CheckoutLayout::Compact))->toContain('.gio-checkout-logo {')
        ->and(baseSheet($styles, CheckoutLayout::Compact))->not->toContain('.gio-checkout-compact');
});

it('hands the visitor, the disk and the fallback reader the same bytes with a chooser drawn', function () {
    pageStyled(['hide_lone_cash_choice' => false]);

    $response = $this->get('/promo');

    $served = (new FallbackPageCacheReader($this->cacheDirectory))->resolve('/promo', 'GET');

    expect($response->getContent())->toContain('name="payment_method"')
        ->and(file_get_contents($this->cache->htmlPath('promo')))->toBe($response->getContent())
        ->and(file_get_contents($served['path']))->toBe($response->getContent());
});
