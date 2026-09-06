<?php

use App\Models\Page;
use App\Models\Product;
use App\Shortcodes\Node;
use App\Support\Checkout\CheckoutConfig;
use App\Support\Checkout\StyleToken;
use App\Support\Checkout\StyleTokenKind;
use App\Support\PageCache\FallbackPageCacheReader;
use Illuminate\Support\Facades\Log;
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
    Storage::fake('public');

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

    /** Nothing is ticked, so nothing is being bought yet. */
    expect($html)->toContain('৳0.00');
});

/**
 * The one <style> block a Checkout emits, without the markup around it.
 *
 * Read out of the served page rather than off a class, because what is being
 * pinned is what a visitor's browser receives.
 */
function styleBlock(string $html): string
{
    preg_match('/<style>(.*?)<\/style>/s', $html, $block);

    return $block[1] ?? '';
}

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

it('leaves the checkout losable to a merchant\'s own stylesheet', function () {
    pageStyled(['style_tokens' => ['accent' => '#ff0000']]);

    $styles = styleBlock($this->get('/promo')->getContent());

    preg_match_all('/(^|\})([^{}]+)\{/', $styles, $selectors);

    /**
     * The tokens are declared at one class, on the root, with nothing raising
     * them — so a merchant redeclaring them, or simply setting the property
     * outright, wins from their own stylesheet without escalating.
     */
    expect($styles)->not->toContain('!important');

    foreach ($selectors[2] as $selector) {
        expect(trim($selector))->not->toContain('#');
    }

    expect(array_filter($selectors[2], fn (string $selector): bool => trim($selector) === '.gio-checkout'))
        ->not->toBeEmpty();
});

it('never lets a stored style value break out of the block it is written into', function () {
    pageStyled(['style_tokens' => [
        'accent' => 'red; } body { display: none } .x {',
        'radius' => '4pt',
        'input_size' => '9',
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
        ->and(styleBlock($html))->toContain('--gio-input-size: '.StyleToken::InputSize->defaultValue().';');
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
        ->and($field[1])->toContain('accent-color: var(--gio-accent)');
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
| Layouts and width
|--------------------------------------------------------------------------
|
| How the Checkout is arranged and how wide it sits. Two layouts drawn from one
| stylesheet, questions that pair up on a wide screen and never on a phone, and
| a width that is a token rather than a third layout.
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

it('draws the products in the stacked layout and omits the panel in the form-only one', function () {
    pageStyled([], slug: 'stacked');
    pageStyled(['layout' => 'form_only'], slug: 'bare');

    $stacked = checkoutMarkup($this->get('/stacked')->assertOk()->getContent());
    $bare = checkoutMarkup($this->get('/bare')->assertOk()->getContent());

    /**
     * A form-only page sold the product in its own markup above the fold, so
     * the panel is gone entirely rather than hidden: nothing is drawn, no
     * picture is fetched, and none of those bytes are written into the cached
     * page.
     */
    expect($stacked)->toContain('class="gio-checkout-products"')
        ->and($bare)->not->toContain('class="gio-checkout-products"')
        ->and($bare)->not->toContain('gio-checkout-product-head')
        ->and($bare)->not->toContain('data-gio-quantity');
});

it('posts one of everything a form-only checkout sells', function () {
    $page = pageStyled(['layout' => 'form_only']);
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

it('totals a form-only checkout off the same line markers as a stacked one', function () {
    $page = pageStyled(['layout' => 'form_only']);

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

it('draws every question, the delivery choice, the summary and the error slots in both layouts', function (string $layout) {
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
     * one loop for both layouts is what prevents.
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
})->with(['stacked', 'form_only']);

it('ships one stylesheet whichever layout the page is arranged in', function () {
    pageStyled([], slug: 'stacked');
    pageStyled(['layout' => 'form_only'], slug: 'bare');

    /**
     * Byte for byte. These styles ride inside every cached page, so a layout
     * carrying a sheet of its own would charge every visitor for an arrangement
     * their page is not using — and a third layout would charge them twice.
     */
    expect(styleBlock($this->get('/bare')->getContent()))
        ->toBe(styleBlock($this->get('/stacked')->getContent()));
});

it('names the layout on the checkout root in both layouts', function (string $layout, string $class) {
    pageStyled(['layout' => $layout]);

    /**
     * A modifier beside the base class, always emitted, so a merchant writing
     * their own CSS has a stable thing to hang it on and a layout added later
     * is a rule rather than a copy.
     */
    expect($this->get('/promo')->getContent())->toContain('class="gio-checkout '.$class.'"');
})->with([
    'stacked' => ['stacked', 'gio-checkout-stacked'],
    'form only' => ['form_only', 'gio-checkout-form-only'],
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

it('never asks a form-only checkout which products to buy', function () {
    $page = pageStyled(['layout' => 'form_only', 'product_picker' => true]);
    $page->products()->attach(Product::factory()->create());

    $html = $this->get('/promo')->assertOk()->getContent();

    /**
     * There is nothing on screen to tick, so there is no question. The merchant
     * keeps their switch for the day they go back to the stacked layout; what
     * it must never do meanwhile is draw a checkbox nobody can find, or — worse
     * — leave a rule standing that demands a choice the form never offered.
     */
    expect($html)->not->toContain('name="selected[]"')
        ->and($page->checkout_config->productPicker)->toBeTrue()
        ->and($page->checkout_config->picksProducts())->toBeFalse()
        ->and($page->refresh()->offersProductChoice())->toBeFalse();
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
