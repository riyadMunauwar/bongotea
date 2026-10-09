<?php

use App\Enums\CategoryTheme;
use App\Enums\SystemPage;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tag;
use App\Support\Settings\AnnouncementSettings;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * `bongotea:install` opens a fresh installation ready to sell and looking
 * like the mockup, and is safe to run again: it only ever adds what is
 * missing, so nothing a Merchant has since edited is put back.
 */
it('opens a fresh install on the mockup Pages, each drawn from its Blocks', function () {
    $this->artisan('bongotea:install')->assertSuccessful();

    foreach (SystemPage::cases() as $role) {
        expect(Page::query()->system($role)->published()->exists())->toBeTrue();
    }

    $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('page.blocks', fn ($blocks): bool => collect($blocks)->pluck('type')->all() === [
            'hero_carousel', 'promise_strip', 'category_tiles', 'category_panels', 'how_to_order', 'video_reviews', 'text_reviews',
        ])
        ->where('page.blocks.0.props.slides.0.title', 'আপনার ব্যবসার জন্য ভালো চা, সহজে অর্ডার করুন')
        ->where('page.blocks.0.props.slides.0.primary', ['label' => 'বাল্ক অর্ডার করুন', 'href' => '/bulk-order'])
        ->where('page.blocks.3.anchor', 'products')
        ->where('page.blocks.3.props.categories.0.slug', 'premium')
        ->where('page.blocks.4.anchor', 'how')
    );

    $expectedBlocks = [
        '/bulk-order' => ['bulk_order_form'],
        '/my-orders' => ['my_orders'],
        '/pricing' => ['hero_carousel', 'price_calculator', 'price_matrix', 'delivery_chart'],
        '/about' => ['hero_carousel', 'story', 'journey', 'value_cards', 'chips_row', 'cta_band'],
        '/contact' => ['hero_carousel', 'contact_cards', 'contact_form', 'map'],
    ];

    foreach ($expectedBlocks as $path => $types) {
        $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('page.blocks', fn ($blocks): bool => collect($blocks)->pluck('type')->all() === $types)
        );
    }
});

it('stocks the mockup catalogue: three themed Categories, its Tags, and eighteen Products on six-step Tiers', function () {
    $this->artisan('bongotea:install')->assertSuccessful();

    expect(Category::query()->orderBy('sort_order')->get(['slug', 'name', 'theme_key'])->toArray())->toBe([
        ['slug' => 'premium', 'name' => 'প্রিমিয়াম', 'theme_key' => CategoryTheme::Premium->value],
        ['slug' => 'gold', 'name' => 'গোল্ড', 'theme_key' => CategoryTheme::Gold->value],
        ['slug' => 'silver', 'name' => 'সিলভার', 'theme_key' => CategoryTheme::Silver->value],
    ])
        ->and(Tag::query()->orderBy('sort_order')->pluck('name')->all())->toBe(['দুধ চায়ের জন্য', 'রং চায়ের জন্য', 'কড়া লিকার', 'রেস্টুরেন্ট ও হোটেল'])
        ->and(Brand::query()->pluck('name')->all())->toBe(['Bongotea'])
        ->and(Product::query()->published()->count())->toBe(18);

    foreach (['premium', 'gold', 'silver'] as $slug) {
        expect(Category::query()->where('slug', $slug)->sole()->products()->count())->toBe(6);
    }

    $ranges = [[1, 5], [6, 10], [11, 15], [16, 20], [21, 50], [51, null]];
    $ladder = fn (array $taka): array => array_map(
        fn (array $range, int $price): array => ['min' => $range[0], 'max' => $range[1], 'price' => $price * 100],
        $ranges,
        $taka,
    );

    /** One Product of each discount kind the mockup worked its prices out by: a percentage, and taka off. */
    $premium = Product::query()->where('name', 'বঙ্গোটি প্রিমিয়াম')->sole();
    $gold = Product::query()->where('name', 'বঙ্গোটি গোল্ড')->sole();

    expect($premium->tiers)->toBe($ladder([620, 589, 558, 539, 521, 496]))
        ->and($premium->price)->toBe(62000)
        ->and($premium->badge)->toBe('সবচেয়ে জনপ্রিয়')
        ->and($premium->category->slug)->toBe('premium')
        ->and($gold->tiers)->toBe($ladder([550, 520, 490, 470, 450, 430]))
        ->and($gold->tags()->orderBy('sort_order')->pluck('name')->all())->toBe(['দুধ চায়ের জন্য', 'রং চায়ের জন্য']);
});

/**
 * Store, Announcement and the Delivery Bands already default to the mockup,
 * so only the Menus are written: they are what can now name the installed
 * Pages rather than typed addresses.
 */
it('opens on the mockup Settings, with the Menus pointing at the installed Pages', function () {
    $this->artisan('bongotea:install')->assertSuccessful();

    expect(Setting::query()->pluck('key')->all())->toBe(['menus']);

    $pricing = Page::query()->where('slug', 'pricing')->sole();

    expect(Setting::query()->find('menus')->value['header'][2])->toMatchArray(['label' => 'দাম হিসাব', 'type' => 'page', 'page_id' => $pricing->id]);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('store.name', 'Bongotea')
        ->where('announcement.message', '৫১+ কেজিতে প্রতিটি চায়ে সর্বোচ্চ ছাড়')
        ->where('menus.header.1.children.0.href', '/#category-premium')
        ->where('menus.header.2.href', '/pricing')
        ->where('menus.header.3.children.3.href', '/my-orders')
        ->where('menus.header.4.href', '/contact')
    );
});

it('adds nothing twice and puts back nothing a Merchant edited, renamed or deleted when run again', function () {
    $this->artisan('bongotea:install')->assertSuccessful();

    $home = Page::query()->system(SystemPage::Home)->sole();
    $home->update(['title' => 'আমাদের দোকান', 'slug' => 'shop', 'blocks' => [['type' => 'faq', 'data' => ['heading' => 'প্রশ্ন', 'items' => [['question' => 'কেন?', 'answer' => 'এজন্য।']]]]]]);
    Page::query()->where('slug', 'about')->sole()->update(['slug' => 'about-us']);
    Page::query()->where('slug', 'contact')->sole()->delete();
    Tag::query()->where('slug', 'hotel')->sole()->delete();

    $premium = Product::query()->where('name', 'বঙ্গোটি প্রিমিয়াম')->sole();
    $premium->update(['name' => 'প্রিমিয়াম ২০২৭', 'tiers' => [['min' => 1, 'max' => null, 'price' => 70000]]]);
    $premium->tags()->detach();
    Product::query()->where('name', 'বঙ্গোটি সেভার')->sole()->delete();

    Category::query()->where('slug', 'gold')->sole()->update(['theme_key' => CategoryTheme::Garden->value]);
    app(AnnouncementSettings::class)->with(['enabled' => false, 'message' => 'ঈদ মোবারক'])->save();

    $this->artisan('bongotea:install')->assertSuccessful();

    expect($home->fresh()->title)->toBe('আমাদের দোকান')
        ->and($home->fresh()->blocks[0]['type'])->toBe('faq')
        ->and(Page::query()->orderBy('id')->pluck('slug')->all())->toBe(['shop', 'bulk-order', 'my-orders', 'pricing', 'about-us'])
        ->and($premium->fresh()->name)->toBe('প্রিমিয়াম ২০২৭')
        ->and($premium->fresh()->price)->toBe(70000)
        ->and($premium->tags()->count())->toBe(0)
        ->and(Product::withTrashed()->count())->toBe(18)
        ->and(Product::query()->count())->toBe(17)
        ->and(Category::query()->count())->toBe(3)
        ->and(Category::query()->where('slug', 'gold')->sole()->theme_key)->toBe(CategoryTheme::Garden->value)
        ->and(Tag::query()->pluck('slug')->all())->toBe(['milk', 'plain', 'strong'])
        ->and(Brand::query()->count())->toBe(1)
        ->and(Setting::query()->find('announcement')->value)->toMatchArray(['enabled' => false, 'message' => 'ঈদ মোবারক']);
});
