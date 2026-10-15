<?php

use App\Enums\SystemPage;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use App\Support\Settings\HeaderSettings;
use App\Support\Settings\PaymentSettings;
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
        pageBlock('chips_row', ['hide_on_desktop' => true, 'heading' => 'যাঁদের জন্য আমরা', 'chips' => [['label' => 'চায়ের দোকান'], ['label' => 'হোটেল', 'link' => '/who-its-for']]]),
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
                    'props' => ['heading' => 'যাঁদের জন্য আমরা', 'chips' => [['label' => 'চায়ের দোকান', 'href' => null], ['label' => 'হোটেল', 'href' => '/who-its-for']]],
                ],
            ])
            ->where('seo.title', 'Bongotea — ব্যবসার জন্য পাইকারি চা')
            ->where('seo.canonical', url('/'))
        );
});

it('draws chips stored as bare words, from before chips could link, as unlinked chips', function () {
    Page::factory()->published()->withBlocks([
        pageBlock('chips_row', ['heading' => 'যাঁদের জন্য আমরা', 'chips' => ['চায়ের দোকান', ' ', 'হোটেল']]),
    ])->create(['slug' => 'about']);

    $this->get('/about')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.blocks.0.props.chips', [['label' => 'চায়ের দোকান', 'href' => null], ['label' => 'হোটেল', 'href' => null]])
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
                'secondary_label' => 'দাম দেখুন', 'secondary_link' => 'javascript:alert(1)',
                'more_buttons' => [['label' => 'রেসিপি', 'link' => '/tea-recipes'], ['label' => 'চাপুন', 'link' => 'javascript:alert(1)']]],
            ['title' => ''],
        ]]),
        fn (): array => ['layout' => 'carousel', 'slides' => [[
            'eyebrow' => 'বাল্ক দাম', 'title' => 'বেশি নিলে দাম কম', 'text' => 'সব চা মিলিয়ে',
            'imageUrl' => url('/storage/pages/slide.jpg'),
            'primary' => ['label' => 'বাল্ক অর্ডার করুন', 'href' => '/bulk-order'],
            'secondary' => null,
            'more' => [['label' => 'রেসিপি', 'href' => '/tea-recipes']],
        ]]],
    ],
    'hero laid out as a banner' => [
        pageBlock('hero_carousel', ['layout' => 'banner', 'slides' => [['eyebrow' => 'আমাদের সম্পর্কে', 'title' => 'বিশ্বস্ত চা সরবরাহকারী']]]),
        ['layout' => 'banner', 'slides' => [['eyebrow' => 'আমাদের সম্পর্কে', 'title' => 'বিশ্বস্ত চা সরবরাহকারী', 'text' => null, 'imageUrl' => null, 'primary' => null, 'secondary' => null, 'more' => []]]],
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
    'value cards, a card button only where both its words and link are given' => [
        pageBlock('value_cards', ['cards' => [
            ['icon' => '✓', 'title' => 'বাছাই করা পাতা', 'text' => 'প্রতিটি চালান পরখ করা।', 'button_label' => 'গ্রেড দেখুন', 'button_link' => '/tea-grades'],
            ['title' => 'পরিষ্কার দাম', 'button_label' => 'দাম দেখুন'],
        ]]),
        ['eyebrow' => null, 'heading' => null, 'cards' => [
            ['icon' => '✓', 'title' => 'বাছাই করা পাতা', 'text' => 'প্রতিটি চালান পরখ করা।', 'button' => ['label' => 'গ্রেড দেখুন', 'href' => '/tea-grades']],
            ['icon' => null, 'title' => 'পরিষ্কার দাম', 'text' => null, 'button' => null],
        ]],
    ],
    'call-to-action band, with more buttons after the first two' => [
        pageBlock('cta_band', ['heading' => 'আপনার দোকানের জন্য চা লাগবে?', 'text' => 'আজই অর্ডার দিন।', 'primary_label' => 'বাল্ক অর্ডার করুন', 'primary_link' => '/bulk-order', 'secondary_label' => 'যোগাযোগ করুন', 'secondary_link' => '/contact',
            'more_buttons' => [['label' => 'রেসিপি দেখুন', 'link' => '/tea-recipes'], ['label' => 'লিংক নেই']]]),
        ['heading' => 'আপনার দোকানের জন্য চা লাগবে?', 'text' => 'আজই অর্ডার দিন।', 'primary' => ['label' => 'বাল্ক অর্ডার করুন', 'href' => '/bulk-order'], 'secondary' => ['label' => 'যোগাযোগ করুন', 'href' => '/contact'],
            'more' => [['label' => 'রেসিপি দেখুন', 'href' => '/tea-recipes']]],
    ],
    'table, each row cut or padded to the headings and blank rows left out' => [
        pageBlock('table', ['heading' => 'এক নজরে গ্রেড', 'columns' => ['গ্রেড', 'দানা', ' '],
            'rows' => [['cells' => ['লিফ', 'মাঝারি', 'বাড়তি']], ['cells' => ['CD']], ['cells' => ['', ' ']]],
            'footnote' => 'স্বাদ পানির পরিমাণের ওপর নির্ভর করে।', 'button_label' => 'গ্রেড জানুন', 'button_link' => '/tea-grades']),
        ['eyebrow' => null, 'heading' => 'এক নজরে গ্রেড', 'text' => null, 'columns' => ['গ্রেড', 'দানা'],
            'rows' => [['cells' => ['লিফ', 'মাঝারি']], ['cells' => ['CD', '']]],
            'footnote' => 'স্বাদ পানির পরিমাণের ওপর নির্ভর করে।', 'button' => ['label' => 'গ্রেড জানুন', 'href' => '/tea-grades']],
    ],
    'info cards, with their own anchors, lists, note and button' => [
        pageBlock('info_cards', ['heading' => 'চায়ের প্রধান গ্রেড', 'columns' => '3', 'cards' => [
            ['badge' => '১', 'anchor' => 'leaf', 'title' => 'লিফ', 'subtitle' => 'ভারসাম্যপূর্ণ নিয়মিত চায়ের জন্য', 'text' => 'মাঝারি দানার চা।',
                'lists' => [['label' => 'বৈশিষ্ট্য', 'items' => ['মাঝারি দানা', ' ']], ['label' => 'খালি', 'items' => []]],
                'note_label' => 'কার জন্য', 'note' => 'চায়ের দোকান', 'button_label' => 'লিফ দেখুন', 'button_link' => '/tea-grades#leaf'],
            ['anchor' => 'Not An Anchor', 'title' => 'CD', 'note_label' => 'শুধু শিরোনাম'],
            ['title' => ''],
        ]]),
        ['eyebrow' => null, 'heading' => 'চায়ের প্রধান গ্রেড', 'text' => null, 'columns' => 3, 'cards' => [
            ['badge' => '১', 'anchor' => 'leaf', 'eyebrow' => null, 'title' => 'লিফ', 'subtitle' => 'ভারসাম্যপূর্ণ নিয়মিত চায়ের জন্য', 'text' => 'মাঝারি দানার চা।',
                'lists' => [['label' => 'বৈশিষ্ট্য', 'items' => ['মাঝারি দানা']]],
                'note' => ['label' => 'কার জন্য', 'text' => 'চায়ের দোকান'], 'button' => ['label' => 'লিফ দেখুন', 'href' => '/tea-grades#leaf']],
            ['badge' => null, 'anchor' => null, 'eyebrow' => null, 'title' => 'CD', 'subtitle' => null, 'text' => null, 'lists' => [], 'note' => null, 'button' => null],
        ]],
    ],
    'info cards with a column count it does not offer, drawn two across' => [
        pageBlock('info_cards', ['columns' => '7', 'cards' => [['title' => 'লিফ']]]),
        ['eyebrow' => null, 'heading' => null, 'text' => null, 'columns' => 2, 'cards' => [
            ['badge' => null, 'anchor' => null, 'eyebrow' => null, 'title' => 'লিফ', 'subtitle' => null, 'text' => null, 'lists' => [], 'note' => null, 'button' => null],
        ]],
    ],
    'recipe cards' => [
        pageBlock('recipe_cards', ['heading' => 'দুধ চা', 'recipes' => [
            ['name' => 'দুধ চা', 'english_name' => 'Milk Tea', 'anchor' => 'milk-tea', 'tea' => 'BOP / PD', 'serves' => '১ কাপ', 'time' => '৫–৭ মিনিট', 'taste' => 'ঘন ও মোলায়েম',
                'ingredients' => [['item' => 'পানি', 'quantity' => '১২০ মিলি'], ['item' => '']],
                'steps' => ['পানি ফুটিয়ে নিন।', ''],
                'tips' => [['label' => 'ব্যবসায়িক টিপস', 'text' => 'লিকার আগে বানিয়ে রাখুন।'], ['label' => 'খালি', 'text' => '']]],
            ['name' => ' '],
        ]]),
        ['eyebrow' => null, 'heading' => 'দুধ চা', 'text' => null, 'recipes' => [
            ['name' => 'দুধ চা', 'englishName' => 'Milk Tea', 'anchor' => 'milk-tea', 'tea' => 'BOP / PD', 'serves' => '১ কাপ', 'time' => '৫–৭ মিনিট', 'taste' => 'ঘন ও মোলায়েম',
                'ingredients' => [['item' => 'পানি', 'quantity' => '১২০ মিলি']],
                'steps' => ['পানি ফুটিয়ে নিন।'],
                'tips' => [['label' => 'ব্যবসায়িক টিপস', 'text' => 'লিকার আগে বানিয়ে রাখুন।']]],
        ]],
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
    'contact form, its topics trimmed with blanks and repeats left out' => [
        pageBlock('contact_form', ['heading' => 'মেসেজ পাঠান', 'text' => '', 'topics' => ['অর্ডার', ' দাম ', '', 'অর্ডার', 'ডেলিভারি']]),
        ['heading' => 'মেসেজ পাঠান', 'text' => null, 'topics' => ['অর্ডার', 'দাম', 'ডেলিভারি']],
    ],
    'delivery chart, its wording and whether cash on delivery is taken, since it draws the shared Delivery Bands' => [
        pageBlock('delivery_chart', ['heading' => 'ডেলিভারি চার্জ', 'text' => '']),
        ['heading' => 'ডেলিভারি চার্জ', 'text' => null, 'offersCashOnDelivery' => true],
    ],
]);

it('tells the delivery chart cash on delivery is not taken once the Merchant switches it off', function () {
    app(PaymentSettings::class)->with(['cashOnDeliveryEnabled' => false])->save();
    Page::factory()->published()->withBlocks([pageBlock('delivery_chart')])->create(['slug' => 'delivery']);

    $this->get('/delivery')->assertInertia(fn (Assert $page) => $page->where('page.blocks.0.props.offersCashOnDelivery', false));
});

it('points the header at the Bulk Order, Packet Basket and My Orders System Pages by role', function () {
    Page::factory()->system(SystemPage::Home)->create();
    Page::factory()->system(SystemPage::BulkOrder)->create(['slug' => 'paikari']);
    Page::factory()->system(SystemPage::PacketBasket)->create(['slug' => 'jhuri']);
    Page::factory()->system(SystemPage::MyOrders)->create(['slug' => 'amar-order']);

    $this->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->where('links.bulkOrder', '/paikari')
            ->where('links.packetBasket', '/jhuri')
            ->where('links.myOrders', '/amar-order')
        );
});

it('leaves a header button at its installed slug while its System Page is a draft', function () {
    Page::factory()->system(SystemPage::Home)->create();
    Page::factory()->system(SystemPage::BulkOrder)->create(['slug' => 'paikari', 'status' => 'draft']);

    $this->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('links.bulkOrder', '/bulk-order')->where('links.packetBasket', '/packet-basket'));
});

it('links a Menu entry naming Home to the bare domain', function () {
    $home = Page::factory()->system(SystemPage::Home)->create();

    $menu = Menu::factory()->create();
    MenuItem::factory()->for($menu)->page($home)->create(['label' => 'হোম']);
    app(HeaderSettings::class)->with(['menuId' => $menu->id])->save();

    $this->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('menus.header.0.href', '/'));
});
