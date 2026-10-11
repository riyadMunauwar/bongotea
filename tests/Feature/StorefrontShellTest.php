<?php

use App\Enums\SystemPage;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\Product;
use App\Support\Settings\DeliveryChargeSettings;
use App\Support\Settings\HeaderSettings;
use App\Support\Settings\StoreSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Inertia\Ssr\SsrRenderFailed;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Home is a System Page, so the bare domain answers only once it exists.
 */
beforeEach(function (): void {
    Page::factory()->system(SystemPage::Home)->create();
});

/**
 * A Menu assigned to the header.
 */
function headerMenu(): Menu
{
    $menu = Menu::factory()->create();
    app(HeaderSettings::class)->with(['menuId' => $menu->id])->save();

    return $menu;
}

it('renders the home page inside the storefront shell, with the store, announcement and links shared', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('page')
            ->where('store.name', 'Bongotea')
            ->where('store.tagline', 'ব্যবসার জন্য পাইকারি চা')
            ->where('store.logoUrl', null)
            ->where('store.hotline', ['number' => '01700000000', 'href' => 'tel:+8801700000000'])
            ->where('store.whatsapp', ['number' => '01700000000', 'href' => 'https://wa.me/8801700000000'])
            ->where('store.email', 'hello@bongotea.com')
            ->where('store.address', 'শ্রীমঙ্গল, মৌলভীবাজার')
            ->where('store.hours', 'সকাল ৯টা – রাত ৯টা')
            ->where('announcement', ['pill' => 'অফার', 'message' => '৫১+ কেজিতে প্রতিটি চায়ে সর্বোচ্চ ছাড়'])
            ->where('links', ['bulkOrder' => '/bulk-order', 'myOrders' => '/my-orders', 'checkout' => '/checkout', 'search' => '/search', 'searchSuggestions' => '/search/suggestions', 'signInCode' => '/sign-in/code', 'signIn' => '/sign-in', 'signOut' => '/sign-out', 'enquiry' => '/enquiries'])
        );
});

it('shares what the Merchant saved for the store and the announcement', function () {
    app(StoreSettings::class)->with([
        'name' => 'Cha Ghor',
        'tagline' => '',
        'hotline' => '01812-345678',
        'whatsapp' => '',
        'logo' => 'store/logo.png',
    ])->save();

    app(HeaderSettings::class)->with(['announcementPill' => '', 'announcementMessage' => 'ঈদে ফ্রি ডেলিভারি'])->save();

    $this->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->where('store.name', 'Cha Ghor')
            ->where('store.hotline', ['number' => '01812-345678', 'href' => 'tel:+8801812345678'])
            ->where('store.whatsapp', null)
            ->where('store.logoUrl', fn (string $url): bool => str_ends_with($url, '/storage/store/logo.png'))
            ->where('announcement', ['pill' => null, 'message' => 'ঈদে ফ্রি ডেলিভারি'])
            ->where('seo.title', 'Cha Ghor')
        );
});

it('shares no announcement when the bar is switched off', function () {
    app(HeaderSettings::class)->with(['announcementEnabled' => false])->save();

    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('announcement', null));
});

it('draws the header without a nav when no Menu is assigned, and keeps the mockup footer', function () {
    $this->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->where('menus.header', [])
            ->where('menus.footer.blurb', 'চায়ের দোকান, রেস্টুরেন্ট ও ব্যবসার জন্য বাছাই করা চা। বেশি নিলে প্রতি কেজির দাম কম।')
            ->has('menus.footer.columns', 2)
            ->where('menus.footer.columns.0.heading', 'অর্ডার')
            ->where('menus.footer.columns.0.links.1.href', '/#products')
            ->has('menus.footer.bottomLinks', 2)
        );
});

it('resolves each header link type, following a Page by reference', function () {
    $pricing = Page::factory()->published()->create(['slug' => 'dam']);
    $menu = headerMenu();

    MenuItem::factory()->for($menu)->page($pricing)->create(['label' => 'দাম', 'position' => 0]);
    MenuItem::factory()->for($menu)->anchor('matrix', $pricing)->create(['label' => 'ধাপ', 'position' => 1]);
    MenuItem::factory()->for($menu)->anchor('how')->create(['label' => 'কীভাবে', 'position' => 2]);
    MenuItem::factory()->for($menu)->create(['label' => 'ফেসবুক', 'url' => 'https://facebook.com/bongotea', 'position' => 3]);
    MenuItem::factory()->for($menu)->create(['label' => 'ভেতরে', 'url' => url('/about'), 'position' => 4]);
    MenuItem::factory()->for($menu)->create(['label' => 'ফোন', 'url' => 'tel:+8801700000000', 'note' => 'সারাদিন', 'position' => 5]);

    $pricing->update(['slug' => 'pricing']);

    $this->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->where('menus.header', [
                ['label' => 'দাম', 'href' => '/pricing', 'external' => false, 'note' => null, 'children' => []],
                ['label' => 'ধাপ', 'href' => '/pricing#matrix', 'external' => false, 'note' => null, 'children' => []],
                ['label' => 'কীভাবে', 'href' => '/#how', 'external' => false, 'note' => null, 'children' => []],
                ['label' => 'ফেসবুক', 'href' => 'https://facebook.com/bongotea', 'external' => true, 'note' => null, 'children' => []],
                ['label' => 'ভেতরে', 'href' => url('/about'), 'external' => false, 'note' => null, 'children' => []],
                ['label' => 'ফোন', 'href' => 'tel:+8801700000000', 'external' => false, 'note' => 'সারাদিন', 'children' => []],
            ])
        );
});

it('carries three levels: a Heading, its dropdown and a flyout', function () {
    $menu = headerMenu();

    $tea = MenuItem::factory()->for($menu)->heading()->create(['label' => 'আমাদের চা']);
    $premium = MenuItem::factory()->under($tea)->create(['label' => 'প্রিমিয়াম', 'url' => '/category/premium', 'note' => 'সেরা']);
    MenuItem::factory()->under($premium)->create(['label' => 'দার্জিলিং', 'url' => '/tea/darjeeling']);

    $this->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->where('menus.header', [
                ['label' => 'আমাদের চা', 'href' => null, 'external' => false, 'note' => null, 'children' => [
                    ['label' => 'প্রিমিয়াম', 'href' => '/category/premium', 'external' => false, 'note' => 'সেরা', 'children' => [
                        ['label' => 'দার্জিলিং', 'href' => '/tea/darjeeling', 'external' => false, 'note' => null, 'children' => []],
                    ]],
                ]],
            ])
        );
});

it('drops links to unpublished or deleted Pages, bad URLs, and Headings left childless', function () {
    $draft = Page::factory()->create();
    $gone = Page::factory()->published()->create();
    $menu = headerMenu();

    MenuItem::factory()->for($menu)->page($draft)->create(['label' => 'খসড়া']);
    MenuItem::factory()->for($menu)->page($gone)->create(['label' => 'গায়েব']);
    MenuItem::factory()->for($menu)->create(['label' => 'খারাপ', 'url' => 'javascript:alert(1)']);
    $empty = MenuItem::factory()->for($menu)->heading()->create(['label' => 'খালি']);
    MenuItem::factory()->under($empty)->page($draft)->create(['label' => 'ঈদ']);
    MenuItem::factory()->for($menu)->heading()->create(['label' => 'শুধু শিরোনাম']);
    MenuItem::factory()->for($menu)->create(['label' => 'থাকে', 'url' => '/ok']);

    $gone->delete();

    $this->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->where('menus.header', [
                ['label' => 'থাকে', 'href' => '/ok', 'external' => false, 'note' => null, 'children' => []],
            ])
        );
});

it('shares no payment methods with every page, since the footer no longer names them', function () {
    $this->get('/')->assertInertia(fn (Assert $page) => $page->missing('paymentMethods'));
});

it('sets the title, description, canonical and share tags server-side', function () {
    app(StoreSettings::class)->with(['shareImage' => 'store/share.jpg'])->save();

    $home = url('/');
    $shareImage = url('/storage/store/share.jpg');

    $response = $this->get('/?utm_source=facebook');

    $response->assertInertia(fn (Assert $page) => $page
        ->where('seo.title', 'Bongotea — ব্যবসার জন্য পাইকারি চা')
        ->where('seo.canonical', $home)
        ->where('seo.image', $shareImage)
    );

    $response
        ->assertSee('<html lang="bn">', false)
        ->assertSee('<title>Bongotea — ব্যবসার জন্য পাইকারি চা</title>', false)
        ->assertSee('<meta data-inertia="description" name="description" content="চায়ের দোকান, রেস্টুরেন্ট ও ব্যবসার জন্য বাছাই করা চা। বেশি নিলে প্রতি কেজির দাম কম।">', false)
        ->assertSee("<link data-inertia=\"canonical\" rel=\"canonical\" href=\"{$home}\">", false)
        ->assertSee("<meta data-inertia=\"og:url\" property=\"og:url\" content=\"{$home}\">", false)
        ->assertSee('<meta data-inertia="og:title" property="og:title" content="Bongotea — ব্যবসার জন্য পাইকারি চা">', false)
        ->assertSee("property=\"og:image\" content=\"{$shareImage}\"", false)
        ->assertSee('<meta data-inertia="twitter:card" name="twitter:card" content="summary_large_image">', false);
});

it('still renders the page in the browser when the SSR process is down', function () {
    config([
        'inertia.ssr.enabled' => true,
        'inertia.ssr.ensure_bundle_exists' => false,
    ]);

    Event::fake([SsrRenderFailed::class]);
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    $this->get('/')
        ->assertOk()
        ->assertSee('<script data-page="app" type="application/json">', false)
        ->assertSee('<div id="app"></div>', false)
        ->assertSee('<title>Bongotea — ব্যবসার জন্য পাইকারি চা</title>', false);

    Event::assertDispatched(SsrRenderFailed::class);
});

it('draws what the SSR process rendered when it answers', function () {
    config([
        'inertia.ssr.enabled' => true,
        'inertia.ssr.ensure_bundle_exists' => false,
    ]);

    Http::fake([
        '*/render' => Http::response([
            'head' => ['<title>From SSR</title>'],
            'body' => '<div data-server-rendered="true" id="app"><main>rendered</main></div>',
        ]),
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSee('<title>From SSR</title>', false)
        ->assertSee('<div data-server-rendered="true" id="app"><main>rendered</main></div>', false)
        ->assertDontSee('<div id="app"></div>', false);
});

it('shares every published Product\'s Tiers and availability, and the Delivery Bands, for the browser\'s Cart', function () {
    app(DeliveryChargeSettings::class)->with(['deliveryBands' => [
        ['min' => 1, 'max' => 10, 'fee' => 15000],
        ['min' => 11, 'max' => null, 'fee' => 0],
    ]])->save();

    $premium = Product::factory()->create(['tiers' => [
        ['min' => 1, 'max' => 5, 'price' => 62000],
        ['min' => 6, 'max' => null, 'price' => 58900],
    ]]);
    $unavailable = Product::factory()->unavailable()->create(['tiers' => [['min' => 1, 'max' => null, 'price' => 46000]]]);
    Product::factory()->draft()->create();
    Product::factory()->create()->delete();

    $this->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->where('pricing', [
                'deliveryBands' => [
                    ['min' => 1, 'max' => 10, 'fee' => 15000],
                    ['min' => 11, 'max' => null, 'fee' => 0],
                ],
                'products' => [
                    ['id' => $premium->id, 'isAvailable' => true, 'tiers' => [
                        ['min' => 1, 'max' => 5, 'price' => 62000],
                        ['min' => 6, 'max' => null, 'price' => 58900],
                    ]],
                    ['id' => $unavailable->id, 'isAvailable' => false, 'tiers' => [['min' => 1, 'max' => null, 'price' => 46000]]],
                ],
            ])
        );
});

it('tells Home it is Home, so it draws the Cart\'s summary bar, and no other Page', function () {
    Page::factory()->published()->create(['slug' => 'about']);

    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('page.isHome', true));
    $this->get('/about')->assertInertia(fn (Assert $page) => $page->where('page.isHome', false));
});
