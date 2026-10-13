<?php

use App\Enums\CategoryTheme;
use App\Enums\SystemPage;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Menu;
use App\Models\Page;
use App\Models\Product;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\User;
use App\Support\Settings\HeaderSettings;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * `bongotea:install` stands an installation up — migrated, linked, its
 * Permissions registered, its first Owner made — and opens the store blank
 * or on the demo. It is safe to run again: it only ever adds what is
 * missing, so nothing a Merchant has since edited is put back, and the demo
 * is never laid over a store with content of its own.
 */
it('opens a demo install on its System Pages and demo Pages, each drawn from its Blocks', function () {
    $this->artisan('bongotea:install', installOptions())->assertSuccessful();

    foreach (SystemPage::cases() as $role) {
        expect(Page::query()->system($role)->published()->exists())->toBeTrue();
    }

    $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('page.blocks', fn ($blocks): bool => collect($blocks)->pluck('type')->all() === [
            'hero_carousel', 'promise_strip', 'category_tiles', 'category_panels', 'how_to_order', 'video_reviews', 'text_reviews',
        ])
        ->where('page.blocks.0.props.slides.0.title', 'আপনার ব্যবসার জন্য ভালো চা, সহজে অর্ডার করুন')
        ->where('page.blocks.0.props.slides.0.primary', ['label' => 'বাল্ক অর্ডার করুন', 'href' => '/bulk-order'])
        ->where('page.blocks.1.props.items.0.text', '২০+ কেজিতে ডিস্ট্রিবিউটর দাম')
        ->where('page.blocks.3.anchor', 'products')
        ->where('page.blocks.3.props.categories.0.slug', 'tea-stall-premium')
        ->where('page.blocks.4.anchor', 'how')
    );

    $expectedBlocks = [
        '/bulk-order' => ['bulk_order_form'],
        '/my-orders' => ['my_orders'],
        '/pricing' => ['hero_carousel', 'price_calculator', 'price_matrix', 'delivery_chart'],
        '/contact' => ['hero_carousel', 'contact_cards', 'contact_form', 'map'],
    ];

    foreach ($expectedBlocks as $path => $types) {
        $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('page.blocks', fn ($blocks): bool => collect($blocks)->pluck('type')->all() === $types)
        );
    }

    expect(Page::query()->count())->toBe(19);
});

it('stocks the demo catalogue: three themed Categories, its Tags, and fifteen Products on three Tiers', function () {
    $this->artisan('bongotea:install', installOptions())->assertSuccessful();

    expect(Category::query()->orderBy('sort_order')->get(['slug', 'name', 'theme_key'])->toArray())->toBe([
        ['slug' => 'tea-stall-premium', 'name' => 'টি স্টল প্রিমিয়াম', 'theme_key' => CategoryTheme::Premium->value],
        ['slug' => 'tea-stall-classic', 'name' => 'টি স্টল ক্লাসিক', 'theme_key' => CategoryTheme::Gold->value],
        ['slug' => 'family-blend', 'name' => 'ফ্যামিলি ব্লেন্ড', 'theme_key' => CategoryTheme::Garden->value],
    ])
        ->and(Tag::query()->orderBy('sort_order')->pluck('name')->all())->toBe(['দুধ চায়ের জন্য', 'রং চায়ের জন্য', 'কড়া লিকার', 'রেস্টুরেন্ট ও হোটেল'])
        ->and(Brand::query()->pluck('name')->all())->toBe(['Bongotea'])
        ->and(Product::query()->published()->count())->toBe(15);

    foreach (['tea-stall-premium', 'tea-stall-classic', 'family-blend'] as $slug) {
        expect(Category::query()->where('slug', $slug)->sole()->products()->count())->toBe(5);
    }

    $leaf = Product::query()->where('slug', 'tea-stall-premium-leaf')->sole();

    expect($leaf->name)->toBe('টি স্টল প্রিমিয়াম লিফ')
        ->and($leaf->category->slug)->toBe('tea-stall-premium')
        ->and($leaf->mrp)->toBe(64000)
        ->and($leaf->price)->toBe(60000)
        ->and($leaf->tiers)->toBe([
            ['min' => 1, 'max' => 9, 'price' => 60000],
            ['min' => 10, 'max' => 19, 'price' => 57500],
            ['min' => 20, 'max' => null, 'price' => 55000],
        ])
        ->and($leaf->badge)->toBe('সবচেয়ে জনপ্রিয়')
        ->and($leaf->tags()->orderBy('sort_order')->pluck('name')->all())->toBe(['দুধ চায়ের জন্য', 'রং চায়ের জন্য', 'রেস্টুরেন্ট ও হোটেল']);

    /** Every fixed Characteristic is filled, and custom ones follow them. */
    foreach (['strength', 'tea_type', 'grade', 'particle_size', 'liquor_colour', 'liquor_body', 'aroma', 'infusion', 'cups_per_kilogram', 'package_weight'] as $characteristic) {
        expect($leaf->{$characteristic})->not->toBeNull();
    }

    expect($leaf->grade)->toBe('লিফ')
        ->and($leaf->particle_size)->toBe('বড়')
        ->and($leaf->custom_characteristics)->not->toBeEmpty()
        ->and($leaf->custom_characteristics[0])->toHaveKeys(['label', 'value']);

    /** Every demo tea is sized from its Grade and keeps the common Storage tips, not a custom row saying the same. */
    expect(Product::query()->where('slug', 'tea-stall-premium-dust')->sole()->particle_size)->toBe('খুব ছোট');

    Product::query()->each(function (Product $product): void {
        expect($product->particle_size)->not->toBeNull()
            ->and($product->storage_tips)->toBe(Product::commonStorageTips())
            ->and(collect($product->custom_characteristics)->pluck('label'))->not->toContain('সংরক্ষণ');
    });
});

/**
 * Store, the announcement, the blurb and the Delivery Bands already default
 * to the mockup, so only the header's and footer's Menus are written: they
 * are what can name the installed Pages and Categories rather than typed
 * addresses. The header's and footer's rows hold their Menus alone, so the
 * announcement and the blurb keep their defaults.
 */
it('opens the demo on header and footer Menus pointing at its Pages and Categories', function () {
    $this->artisan('bongotea:install', installOptions())->assertSuccessful();

    $header = Menu::query()->where('name', 'Main menu')->sole();
    $order = Menu::query()->where('name', 'Footer — অর্ডার')->sole();
    $guide = Menu::query()->where('name', 'Footer — চা জ্ঞানকোষ')->sole();
    $brand = Menu::query()->where('name', 'Footer — বঙ্গ টি')->sole();
    $bottom = Menu::query()->where('name', 'Footer bottom line')->sole();

    expect(Setting::query()->orderBy('key')->pluck('key')->all())->toBe(['footer', 'header'])
        ->and(Setting::query()->find('header')->value)->toBe(['menuId' => $header->id])
        ->and(Setting::query()->find('footer')->value)->toBe([
            'columns' => [
                ['heading' => 'অর্ডার', 'menuId' => $order->id],
                ['heading' => 'চা জ্ঞানকোষ', 'menuId' => $guide->id],
                ['heading' => 'বঙ্গ টি', 'menuId' => $brand->id],
            ],
            'bottomMenuId' => $bottom->id,
        ])
        ->and(Menu::query()->count())->toBe(5);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('announcement.message', '২০+ কেজিতে প্রতিটি চায়ে ডিস্ট্রিবিউটর দাম')
        ->where('menus.header', fn ($links): bool => collect($links)->pluck('label')->all() === [
            'হোম', 'আমাদের চা', 'চা জ্ঞানকোষ', 'দাম হিসাব', 'অর্ডার', 'আমাদের সম্পর্কে', 'যোগাযোগ',
        ])
        ->where('menus.header.1.href', null)
        ->where('menus.header.1.children.0.href', '/category/tea-stall-premium')
        ->where('menus.header.1.children.2.href', '/category/family-blend')
        ->has('menus.header.2.children', 8)
        ->where('menus.header.2.children.2.href', '/tea-grades')
        ->where('menus.header.3.href', '/pricing')
        ->where('menus.header.4.children.0.href', '/#how')
        ->has('menus.header.5.children', 6)
        ->where('menus.header.6.href', '/contact')
        ->has('menus.footer.columns', 3)
        ->where('menus.footer.columns.0.links.1.href', '/#products')
        ->has('menus.footer.columns.1.links', 7)
        ->where('menus.footer.bottomLinks', [
            ['label' => 'আমাদের সম্পর্কে', 'href' => '/about', 'external' => false, 'note' => null],
            ['label' => 'চা সম্পর্কিত প্রশ্ন', 'href' => '/faq', 'external' => false, 'note' => null],
            ['label' => 'যোগাযোগ', 'href' => '/contact', 'external' => false, 'note' => null],
        ])
    );
});

it('opens a blank store on its System Pages alone, linked from the header and one footer column', function () {
    $this->artisan('bongotea:install', installOptions(['--demo' => false, '--blank' => true]))
        ->expectsOutputToContain('The store is open, blank.')
        ->assertSuccessful();

    expect(Page::query()->orderBy('id')->pluck('slug')->all())->toBe(['home', 'bulk-order', 'my-orders'])
        ->and(Product::withTrashed()->exists())->toBeFalse()
        ->and(Category::query()->exists())->toBeFalse()
        ->and(Menu::query()->count())->toBe(2);

    $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('menus.header', fn ($links): bool => collect($links)->pluck('href')->all() === ['/', '/bulk-order', '/my-orders'])
        ->has('menus.footer.columns', 1)
        ->where('menus.footer.columns.0.heading', 'অর্ডার')
        ->where('menus.footer.bottomLinks', [])
    );
});

it('makes the first Owner from the answers given', function () {
    $this->artisan('bongotea:install', installOptions())->assertSuccessful();

    $owner = User::query()->where('email', 'rumi@example.com')->sole();

    expect($owner->name)->toBe('Rumi')
        ->and($owner->isOwner())->toBeTrue()
        ->and(Hash::check('secret-password', $owner->password))->toBeTrue();
});

it('asks for the Owner and for blank or demo when nobody gave them', function () {
    $this->artisan('bongotea:install')
        ->expectsQuestion('Owner\'s name', 'Rumi')
        ->expectsQuestion('Owner\'s email address', 'rumi@example.com')
        ->expectsQuestion('Owner\'s password', 'secret-password')
        ->expectsQuestion('The password again', 'secret-password')
        ->expectsChoice('Open the store blank or with demo data?', 'blank', [
            'demo' => 'Demo — the catalogue, about and tea-guide Pages, and full Menus',
            'blank' => 'Blank — only the System Pages, to build from the panel',
        ])
        ->assertSuccessful();

    expect(User::query()->sole()->isOwner())->toBeTrue()
        ->and(Product::query()->exists())->toBeFalse();
});

it('makes no Owner when there already is one, and asks for none', function () {
    $existing = User::factory()->owner()->create();

    $this->artisan('bongotea:install', ['--demo' => true, '--no-interaction' => true])
        ->expectsOutputToContain('An Owner already exists')
        ->assertSuccessful();

    expect(User::query()->sole()->is($existing))->toBeTrue();
});

it('refuses an unattended run that does not say who the Owner is', function () {
    $this->artisan('bongotea:install', ['--demo' => true, '--no-interaction' => true])
        ->expectsOutputToContain('No Owner exists')
        ->assertFailed();

    expect(User::query()->exists())->toBeFalse()
        ->and(Product::query()->exists())->toBeFalse();
});

it('refuses an Owner answer that will not do', function (array $answers, string $problem) {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->artisan('bongotea:install', installOptions($answers))
        ->expectsOutputToContain($problem)
        ->assertFailed();

    expect(Role::owner()->users()->exists())->toBeFalse();
})->with([
    'an address that is not one' => [['--owner-email' => 'rumi'], 'must be a valid email address'],
    'an address another User has' => [['--owner-email' => 'taken@example.com'], 'has already been taken'],
    'a short password' => [['--owner-password' => 'short'], 'at least 8 characters'],
]);

it('refuses to be told both blank and demo', function () {
    $this->artisan('bongotea:install', installOptions(['--blank' => true]))
        ->expectsOutputToContain('Choose one of --demo and --blank')
        ->assertFailed();

    expect(User::query()->exists())->toBeFalse();
});

it('refuses an unattended run on an empty store that does not say blank or demo', function () {
    $this->artisan('bongotea:install', installOptions(['--demo' => false, '--no-interaction' => true]))
        ->expectsOutputToContain('Give --demo or --blank')
        ->assertFailed();

    expect(Menu::query()->exists())->toBeFalse();
});

it('lays no demo over a store that already has content of its own', function () {
    Page::factory()->published()->create(['slug' => 'eid-offer']);

    $this->artisan('bongotea:install', installOptions())
        ->expectsOutputToContain('already has its own content')
        ->assertSuccessful();

    expect(Product::query()->exists())->toBeFalse()
        ->and(Menu::query()->exists())->toBeFalse()
        ->and(Page::query()->pluck('slug')->sort()->values()->all())->toBe(['bulk-order', 'eid-offer', 'home', 'my-orders']);
});

it('adds nothing twice and puts back nothing a Merchant edited, renamed or deleted when run again', function () {
    $this->artisan('bongotea:install', installOptions())->assertSuccessful();

    $home = Page::query()->system(SystemPage::Home)->sole();
    $home->update(['title' => 'আমাদের দোকান', 'slug' => 'shop', 'blocks' => [['type' => 'faq', 'data' => ['heading' => 'প্রশ্ন', 'items' => [['question' => 'কেন?', 'answer' => 'এজন্য।']]]]]]);
    Page::query()->where('slug', 'about')->sole()->update(['slug' => 'about-us']);
    Page::query()->where('slug', 'contact')->sole()->delete();
    Tag::query()->where('slug', 'hotel')->sole()->delete();

    $leaf = Product::query()->where('slug', 'tea-stall-premium-leaf')->sole();
    $leaf->update(['name' => 'প্রিমিয়াম ২০২৭', 'mrp' => 70000, 'tiers' => [['min' => 1, 'max' => null, 'price' => 70000]]]);
    $leaf->tags()->detach();
    Product::query()->where('slug', 'tea-stall-classic-cd')->sole()->delete();

    Category::query()->where('slug', 'tea-stall-classic')->sole()->update(['theme_key' => CategoryTheme::Masala->value]);
    app(HeaderSettings::class)->with(['announcementEnabled' => false, 'announcementMessage' => 'ঈদ মোবারক'])->save();

    $this->artisan('bongotea:install', ['--no-interaction' => true])->assertSuccessful();

    expect($home->fresh()->title)->toBe('আমাদের দোকান')
        ->and($home->fresh()->blocks[0]['type'])->toBe('faq')
        ->and(Page::query()->where('slug', 'about-us')->exists())->toBeTrue()
        ->and(Page::query()->whereIn('slug', ['about', 'contact'])->exists())->toBeFalse()
        ->and(Page::query()->count())->toBe(18)
        ->and($leaf->fresh()->name)->toBe('প্রিমিয়াম ২০২৭')
        ->and($leaf->fresh()->price)->toBe(70000)
        ->and($leaf->tags()->count())->toBe(0)
        ->and(Product::withTrashed()->count())->toBe(15)
        ->and(Product::query()->count())->toBe(14)
        ->and(Category::query()->count())->toBe(3)
        ->and(Category::query()->where('slug', 'tea-stall-classic')->sole()->theme_key)->toBe(CategoryTheme::Masala->value)
        ->and(Tag::query()->pluck('slug')->all())->toBe(['milk', 'plain', 'strong'])
        ->and(Brand::query()->count())->toBe(1)
        ->and(Setting::query()->find('header')->value)->toMatchArray(['announcementEnabled' => false, 'announcementMessage' => 'ঈদ মোবারক'])
        ->and(Menu::query()->count())->toBe(5)
        ->and(User::query()->count())->toBe(1);
});
