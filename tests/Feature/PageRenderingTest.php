<?php

use App\Enums\MenuLinkType;
use App\Enums\SystemPage;
use App\Models\Page;
use App\Support\Settings\MenuSettings;
use App\Support\Settings\StoreSettings;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Pages built from Blocks, as a buyer reaches them: Home at the bare domain,
 * every other published Page at its slug, and each Block handed to the
 * storefront as the props its component draws — never the stored data
 * passed through unread.
 *
 * @param  array<string, mixed>  $data
 * @return array{type: string, data: array<string, mixed>}
 */
function pageBlock(string $type, array $data = []): array
{
    return ['type' => $type, 'data' => $data];
}

it('serves Home at the bare domain with its Blocks, anchors and screen switches', function () {
    Page::factory()->system(SystemPage::Home)->withBlocks([
        pageBlock('promise_strip', [
            'anchor' => 'promises',
            'hide_on_mobile' => true,
            'items' => [['icon' => '৳', 'title' => 'বেশি নিলে বেশি ছাড়', 'text' => '৫১+ কেজিতে সর্বোচ্চ ২০%']],
        ]),
        pageBlock('chips_row', ['hide_on_desktop' => true, 'heading' => 'যাঁদের জন্য আমরা', 'chips' => ['চায়ের দোকান', 'হোটেল']]),
    ])->create();

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('page')
            ->where('page.title', 'Home')
            ->where('page.blocks', [
                [
                    'type' => 'promise_strip',
                    'anchor' => 'promises',
                    'hideOnMobile' => true,
                    'hideOnDesktop' => false,
                    'props' => ['items' => [['icon' => '৳', 'title' => 'বেশি নিলে বেশি ছাড়', 'text' => '৫১+ কেজিতে সর্বোচ্চ ২০%']]],
                ],
                [
                    'type' => 'chips_row',
                    'anchor' => null,
                    'hideOnMobile' => false,
                    'hideOnDesktop' => true,
                    'props' => ['heading' => 'যাঁদের জন্য আমরা', 'chips' => ['চায়ের দোকান', 'হোটেল']],
                ],
            ])
            ->where('seo.title', 'Bongotea — ব্যবসার জন্য পাইকারি চা')
            ->where('seo.canonical', url('/'))
        );
});

it('serves a published Page at its slug, saying what its SEO fields say', function () {
    Page::factory()->published()->create(['title' => 'দাম হিসাব', 'slug' => 'pricing']);
    Page::factory()->published()->create([
        'title' => 'Eid',
        'slug' => 'eid-offer',
        'seo_title' => 'ঈদের অফার',
        'seo_description' => 'ঈদে ফ্রি ডেলিভারি',
        'seo_image' => 'seo/eid.jpg',
    ]);

    $this->get('/pricing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('page')
            ->where('page.blocks', [])
            ->where('seo.title', 'দাম হিসাব — Bongotea')
            ->where('seo.description', app(StoreSettings::class)->description)
            ->where('seo.canonical', url('/pricing'))
        );

    $this->get('/eid-offer?utm_source=facebook')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('seo.title', 'ঈদের অফার')
            ->where('seo.description', 'ঈদে ফ্রি ডেলিভারি')
            ->where('seo.image', url('/storage/seo/eid.jpg'))
            ->where('seo.canonical', url('/eid-offer'))
        );
});

it('sends Home asked for by its slug to the bare domain', function () {
    Page::factory()->system(SystemPage::Home)->create(['slug' => 'home']);

    $this->get('/home')->assertRedirect('/')->assertStatus(301);
});

it('answers 404 for a draft Page, an unknown slug, and a Home not yet published', function () {
    Page::factory()->create(['slug' => 'eid-offer']);
    Page::factory()->system(SystemPage::Home)->create(['status' => 'draft']);

    $this->get('/eid-offer')->assertNotFound();
    $this->get('/nothing-here')->assertNotFound();
    $this->get('/')->assertNotFound();
});

it('leaves out a Block of a kind the store no longer has', function () {
    Page::factory()->published()->withBlocks([
        pageBlock('retired_kind', ['heading' => 'gone']),
        pageBlock('faq', ['items' => [['question' => 'ডেলিভারি কতদিনে?', 'answer' => 'ঢাকায় ২৪–৪৮ ঘণ্টায়।']]]),
    ])->create(['slug' => 'faq']);

    $this->get('/faq')
        ->assertInertia(fn (Assert $page) => $page
            ->has('page.blocks', 1)
            ->where('page.blocks.0.type', 'faq')
        );
});

it('hands each content Block kind the props its component draws', function (array $stored, array $expected) {
    Page::factory()->published()->withBlocks([$stored])->create(['slug' => 'kinds']);

    $this->get('/kinds')
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.blocks.0.type', $stored['type'])
            ->where('page.blocks.0.props', $expected)
        );
})->with([
    'hero carousel, with an image, buttons, and a link no Menu may hold dropped' => [
        pageBlock('hero_carousel', ['layout' => 'carousel', 'slides' => [
            ['eyebrow' => 'বাল্ক দাম', 'title' => 'বেশি নিলে দাম কম', 'text' => 'সব চা মিলিয়ে', 'image' => 'pages/slide.jpg',
                'primary_label' => 'বাল্ক অর্ডার করুন', 'primary_link' => '/bulk-order',
                'secondary_label' => 'দাম দেখুন', 'secondary_link' => 'javascript:alert(1)'],
            ['title' => ''],
        ]]),
        fn (): array => ['layout' => 'carousel', 'slides' => [[
            'eyebrow' => 'বাল্ক দাম', 'title' => 'বেশি নিলে দাম কম', 'text' => 'সব চা মিলিয়ে',
            'imageUrl' => url('/storage/pages/slide.jpg'),
            'primary' => ['label' => 'বাল্ক অর্ডার করুন', 'href' => '/bulk-order'],
            'secondary' => null,
        ]]],
    ],
    'hero laid out as a banner' => [
        pageBlock('hero_carousel', ['layout' => 'banner', 'slides' => [['eyebrow' => 'আমাদের সম্পর্কে', 'title' => 'বিশ্বস্ত চা সরবরাহকারী']]]),
        ['layout' => 'banner', 'slides' => [['eyebrow' => 'আমাদের সম্পর্কে', 'title' => 'বিশ্বস্ত চা সরবরাহকারী', 'text' => null, 'imageUrl' => null, 'primary' => null, 'secondary' => null]]],
    ],
    'how-to-order steps, reading the video id out of a link' => [
        pageBlock('how_to_order', ['eyebrow' => 'কীভাবে অর্ডার করবেন', 'heading' => 'মাত্র ৩ ধাপে অর্ডার করুন', 'text' => null,
            'steps' => [['title' => 'চা ও কেজি বেছে নিন', 'text' => 'দাম নিজে থেকেই হিসাব হবে।', 'chip' => 'দাম সাথে সাথে দেখবেন']],
            'cta_label' => 'এখনই অর্ডার শুরু করুন', 'cta_link' => '/bulk-order',
            'video' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10', 'show_hotline' => true]),
        ['eyebrow' => 'কীভাবে অর্ডার করবেন', 'heading' => 'মাত্র ৩ ধাপে অর্ডার করুন', 'text' => null,
            'steps' => [['title' => 'চা ও কেজি বেছে নিন', 'text' => 'দাম নিজে থেকেই হিসাব হবে।', 'chip' => 'দাম সাথে সাথে দেখবেন']],
            'cta' => ['label' => 'এখনই অর্ডার শুরু করুন', 'href' => '/bulk-order'], 'videoId' => 'dQw4w9WgXcQ', 'showHotline' => true],
    ],
    'video reviews' => [
        pageBlock('video_reviews', ['eyebrow' => 'ভিডিও রিভিউ', 'heading' => 'ক্রেতাদের মুখেই শুনুন', 'hint' => 'সত্যিকারের দোকানি',
            'reviews' => [['name' => 'রহিম উদ্দিন', 'place' => 'চায়ের দোকান, মিরপুর', 'product' => 'স্ট্রং', 'duration' => '১:২৪', 'quote' => 'কেজিতে ৳৬০ কম পড়ছে', 'video' => 'https://youtu.be/dQw4w9WgXcQ']]]),
        ['eyebrow' => 'ভিডিও রিভিউ', 'heading' => 'ক্রেতাদের মুখেই শুনুন', 'hint' => 'সত্যিকারের দোকানি',
            'reviews' => [['name' => 'রহিম উদ্দিন', 'place' => 'চায়ের দোকান, মিরপুর', 'product' => 'স্ট্রং', 'duration' => '১:২৪', 'quote' => 'কেজিতে ৳৬০ কম পড়ছে', 'videoId' => 'dQw4w9WgXcQ', 'imageUrl' => null]]],
    ],
    'text reviews, verified only where switched on' => [
        pageBlock('text_reviews', ['heading' => 'দোকানিরা যা বলছেন', 'rating_summary' => '৪.৭/৫', 'note' => '২,৫০০+ দোকান',
            'reviews' => [
                ['name' => 'রহিম উদ্দিন', 'place' => 'মিরপুর', 'product' => 'স্ট্রং', 'text' => 'লিকার কড়া।', 'verified' => true],
                ['name' => 'সালমা বেগম', 'place' => 'চট্টগ্রাম', 'product' => 'স্পেশাল', 'text' => 'রং ভালো।'],
            ]]),
        ['heading' => 'দোকানিরা যা বলছেন', 'ratingSummary' => '৪.৭/৫', 'note' => '২,৫০০+ দোকান', 'reviews' => [
            ['name' => 'রহিম উদ্দিন', 'place' => 'মিরপুর', 'product' => 'স্ট্রং', 'text' => 'লিকার কড়া।', 'verified' => true],
            ['name' => 'সালমা বেগম', 'place' => 'চট্টগ্রাম', 'product' => 'স্পেশাল', 'text' => 'রং ভালো।', 'verified' => false],
        ]],
    ],
    'story, its rich text sanitised' => [
        pageBlock('story', ['eyebrow' => 'আমাদের ভাবনা', 'quote' => 'কম বুঝলেও সহজে চায়ের অর্ডার করা যাবে।', 'body' => '<p>বাগান থেকে <strong>বাছাই</strong></p><script>alert(1)</script>']),
        ['eyebrow' => 'আমাদের ভাবনা', 'quote' => 'কম বুঝলেও সহজে চায়ের অর্ডার করা যাবে।', 'bodyHtml' => '<p>বাগান থেকে <strong>বাছাই</strong></p>'],
    ],
    'journey' => [
        pageBlock('journey', ['eyebrow' => 'চায়ের যাত্রা', 'heading' => 'বাগান থেকে আপনার দোকানে', 'steps' => [['title' => 'বাগান থেকে পাতা', 'text' => 'কচি পাতা দিয়ে শুরু।', 'image' => 'pages/garden.jpg']]]),
        fn (): array => ['eyebrow' => 'চায়ের যাত্রা', 'heading' => 'বাগান থেকে আপনার দোকানে', 'steps' => [['title' => 'বাগান থেকে পাতা', 'text' => 'কচি পাতা দিয়ে শুরু।', 'imageUrl' => url('/storage/pages/garden.jpg')]]],
    ],
    'value cards' => [
        pageBlock('value_cards', ['cards' => [['icon' => '✓', 'title' => 'বাছাই করা পাতা', 'text' => 'প্রতিটি চালান পরখ করা।']]]),
        ['eyebrow' => null, 'heading' => null, 'cards' => [['icon' => '✓', 'title' => 'বাছাই করা পাতা', 'text' => 'প্রতিটি চালান পরখ করা।']]],
    ],
    'call-to-action band' => [
        pageBlock('cta_band', ['heading' => 'আপনার দোকানের জন্য চা লাগবে?', 'text' => 'আজই অর্ডার দিন।', 'primary_label' => 'বাল্ক অর্ডার করুন', 'primary_link' => '/bulk-order', 'secondary_label' => 'যোগাযোগ করুন', 'secondary_link' => '/contact']),
        ['heading' => 'আপনার দোকানের জন্য চা লাগবে?', 'text' => 'আজই অর্ডার দিন।', 'primary' => ['label' => 'বাল্ক অর্ডার করুন', 'href' => '/bulk-order'], 'secondary' => ['label' => 'যোগাযোগ করুন', 'href' => '/contact']],
    ],
    'contact cards' => [
        pageBlock('contact_cards', ['whatsapp_note' => 'সাধারণত ১০ মিনিটে উত্তর', 'email_note' => '', 'raised' => true]),
        ['whatsappNote' => 'সাধারণত ১০ মিনিটে উত্তর', 'emailNote' => null, 'raised' => true],
    ],
    'map, framing only a Google Maps embed' => [
        pageBlock('map', ['embed_url' => 'https://evil.example/maps/embed?pb=1', 'hours' => [['days' => 'শুক্রবার', 'hours' => 'বিকেল ৩টা – রাত ৯টা']]]),
        ['embedUrl' => null, 'hours' => [['days' => 'শুক্রবার', 'hours' => 'বিকেল ৩টা – রাত ৯টা']]],
    ],
    'map with its embed' => [
        pageBlock('map', ['embed_url' => 'https://www.google.com/maps/embed?pb=!1m18']),
        ['embedUrl' => 'https://www.google.com/maps/embed?pb=!1m18', 'hours' => []],
    ],
    'faq, a question without an answer left out' => [
        pageBlock('faq', ['heading' => 'সাধারণ প্রশ্ন', 'items' => [['question' => 'ডেলিভারি কতদিনে?', 'answer' => 'ঢাকায় ২৪–৪৮ ঘণ্টায়।'], ['question' => 'খালি?', 'answer' => '']]]),
        ['eyebrow' => null, 'heading' => 'সাধারণ প্রশ্ন', 'items' => [['question' => 'ডেলিভারি কতদিনে?', 'answer' => 'ঢাকায় ২৪–৪৮ ঘণ্টায়।']]],
    ],
]);

it('points the header at the Bulk Order and My Orders System Pages by role', function () {
    Page::factory()->system(SystemPage::Home)->create();
    Page::factory()->system(SystemPage::BulkOrder)->create(['slug' => 'paikari']);
    Page::factory()->system(SystemPage::MyOrders)->create(['slug' => 'amar-order']);

    $this->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->where('links.bulkOrder', '/paikari')
            ->where('links.myOrders', '/amar-order')
        );
});

it('leaves a header button at its installed slug while its System Page is a draft', function () {
    Page::factory()->system(SystemPage::Home)->create();
    Page::factory()->system(SystemPage::BulkOrder)->create(['slug' => 'paikari', 'status' => 'draft']);

    $this->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('links.bulkOrder', '/bulk-order'));
});

it('links a Menu entry naming Home to the bare domain', function () {
    $home = Page::factory()->system(SystemPage::Home)->create();

    app(MenuSettings::class)->with([
        'header' => [['label' => 'হোম', 'type' => MenuLinkType::Page->value, 'page_id' => $home->id]],
    ])->save();

    $this->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('menus.header.0.href', '/'));
});
