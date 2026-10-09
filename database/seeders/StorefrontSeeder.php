<?php

namespace Database\Seeders;

use App\Enums\MenuLinkType;
use App\Enums\PageStatus;
use App\Enums\SystemPage;
use App\Models\Page;
use App\Models\Setting;
use App\Support\Pages\Blocks\BulkOrderForm;
use App\Support\Pages\Blocks\CallToActionBand;
use App\Support\Pages\Blocks\CategoryPanels;
use App\Support\Pages\Blocks\CategoryTiles;
use App\Support\Pages\Blocks\ChipsRow;
use App\Support\Pages\Blocks\ContactCards;
use App\Support\Pages\Blocks\ContactForm;
use App\Support\Pages\Blocks\DeliveryChart;
use App\Support\Pages\Blocks\HeroCarousel;
use App\Support\Pages\Blocks\HowToOrder;
use App\Support\Pages\Blocks\Journey;
use App\Support\Pages\Blocks\MapAndAddress;
use App\Support\Pages\Blocks\MyOrders;
use App\Support\Pages\Blocks\PriceCalculator;
use App\Support\Pages\Blocks\PriceMatrix;
use App\Support\Pages\Blocks\PromiseStrip;
use App\Support\Pages\Blocks\Story;
use App\Support\Pages\Blocks\TextReviews;
use App\Support\Pages\Blocks\ValueCards;
use App\Support\Pages\Blocks\VideoReviews;
use App\Support\Pages\PageBlock;
use App\Support\Settings\MenuSettings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The Pages a fresh store opens with, in the mockup's Blocks and copy: the
 * three System Pages (ADR-0030), the Pricing, About and Contact Pages, and
 * the Menus that link them.
 *
 * Running it again adds only what is missing and leaves everything a
 * Merchant has edited, renamed or deleted as they left it: a System Page is
 * found by its role, and the starter Pages and Menus are created together,
 * once, on a store that has none of its own.
 *
 * The Menus are saved here rather than left to `MenuSettings`' defaults
 * because only now are there Pages to point at: an entry that names a Page
 * follows it through a renamed slug, where a typed address would not. The
 * Store, Announcement and Delivery Band Settings are not written: their
 * defaults already are the mockup's, and a row would freeze today's
 * defaults over any later change to them (`.ai/rules/settings.md`).
 */
class StorefrontSeeder extends Seeder
{
    private const BULK_ORDER_LINK = '/bulk-order';

    private const PRICING_LINK = '/pricing';

    private const CONTACT_LINK = '/contact';

    public function run(): void
    {
        $systemPages = [
            'home' => $this->systemPage(SystemPage::Home, 'হোম', 'home', self::homeBlocks()),
            'bulk_order' => $this->systemPage(SystemPage::BulkOrder, 'বাল্ক অর্ডার', 'bulk-order', [
                self::block(BulkOrderForm::class, [
                    'heading' => 'একসাথে চা অর্ডার করুন',
                    'text' => 'যে যে চা লাগবে, শুধু কত কেজি লাগবে সেটি দিন। দাম নিজে থেকেই হিসাব হবে।',
                    'quick_chips' => true,
                    'show_tier_list' => true,
                ]),
            ]),
            'my_orders' => $this->systemPage(SystemPage::MyOrders, 'আমার অর্ডার', 'my-orders', [
                self::block(MyOrders::class, [
                    'heading' => 'আমার অর্ডার',
                    'text' => 'মোবাইল নম্বর দিয়ে ঢুকুন। পাসওয়ার্ড লাগবে না।',
                ]),
            ]),
        ];

        if (self::hasStarterContent()) {
            return;
        }

        DB::transaction(function () use ($systemPages): void {
            $pages = [
                ...$systemPages,
                'pricing' => self::page('দাম হিসাব', 'pricing', self::pricingBlocks()),
                'about' => self::page('আমাদের সম্পর্কে', 'about', self::aboutBlocks()),
                'contact' => self::page('যোগাযোগ', 'contact', self::contactBlocks()),
            ];

            $menus = (new MenuSettings)->with(self::menus(array_map(fn (Page $page): int => $page->id, $pages)));

            Setting::query()->create(['key' => MenuSettings::key(), 'value' => $menus->toArray()]);
        });
    }

    /**
     * A System Page is found by its role, which can never change or be
     * deleted (ADR-0030), so one is created exactly once whatever the
     * Merchant has done to it since.
     *
     * @param  list<array{type: string, data: array<string, mixed>}>  $blocks
     */
    private function systemPage(SystemPage $role, string $title, string $slug, array $blocks): Page
    {
        return Page::query()->system($role)->first()
            ?? Page::query()->create([...self::published($title, $slug, $blocks), 'system_role' => $role]);
    }

    /**
     * Whether the store has begun its own Pages or Menus. The Pricing, About
     * and Contact Pages and the Menus that link them are starter content,
     * created together in one go on a store that has neither, so a Page the
     * Merchant deleted or moved to another slug is never put back beside it.
     */
    private static function hasStarterContent(): bool
    {
        return Page::query()->whereNull('system_role')->exists()
            || Setting::query()->whereKey(MenuSettings::key())->exists();
    }

    /**
     * @param  list<array{type: string, data: array<string, mixed>}>  $blocks
     */
    private static function page(string $title, string $slug, array $blocks): Page
    {
        return Page::query()->create(self::published($title, $slug, $blocks));
    }

    /**
     * @param  list<array{type: string, data: array<string, mixed>}>  $blocks
     * @return array<string, mixed>
     */
    private static function published(string $title, string $slug, array $blocks): array
    {
        return ['title' => $title, 'slug' => $slug, 'status' => PageStatus::Published, 'blocks' => $blocks];
    }

    /**
     * @param  class-string<PageBlock>  $kind
     * @param  array<string, mixed>  $data
     * @return array{type: string, data: array<string, mixed>}
     */
    private static function block(string $kind, array $data): array
    {
        return ['type' => $kind::type(), 'data' => $data];
    }

    /**
     * A dark page header: the first slide of a hero, drawn alone.
     *
     * @return array{type: string, data: array<string, mixed>}
     */
    private static function banner(string $eyebrow, string $title, string $text): array
    {
        return self::block(HeroCarousel::class, [
            'layout' => 'banner',
            'slides' => [['eyebrow' => $eyebrow, 'title' => $title, 'text' => $text]],
        ]);
    }

    /**
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    private static function homeBlocks(): array
    {
        $slide = fn (string $eyebrow, string $title, string $text): array => [
            'eyebrow' => $eyebrow,
            'title' => $title,
            'text' => $text,
            'primary_label' => 'বাল্ক অর্ডার করুন',
            'primary_link' => self::BULK_ORDER_LINK,
            'secondary_label' => 'চায়ের দাম দেখুন',
            'secondary_link' => self::PRICING_LINK,
        ];

        return [
            self::block(HeroCarousel::class, [
                'layout' => 'carousel',
                'slides' => [
                    $slide('চায়ের দোকান ও ব্যবসার জন্য', 'আপনার ব্যবসার জন্য ভালো চা, সহজে অর্ডার করুন', 'চায়ের দোকান, রেস্টুরেন্ট কিংবা ব্যবসার জন্য প্রয়োজন অনুযায়ী চা নিন। একসাথে বেশি নিলে প্রতি কেজির দাম আরও কম।'),
                    $slide('বাল্ক দাম', 'বেশি নিলে দাম কম', 'সব চা মিলিয়ে ৫১ কেজির বেশি নিলে প্রতি কেজি মাত্র ৳৪৩০।'),
                    $slide('সহজ অর্ডার', 'কম বুঝলেও সহজে চায়ের অর্ডার', 'কোন চা কত কেজি লাগবে দিন, বাকি হিসাব আমাদের।'),
                ],
            ]),
            self::block(PromiseStrip::class, [
                'items' => [
                    ['icon' => '৳', 'title' => 'বেশি নিলে বেশি ছাড়', 'text' => '৫১+ কেজিতে সর্বোচ্চ ২০%'],
                    ['icon' => '✓', 'title' => 'ক্যাশ অন ডেলিভারি', 'text' => 'চা হাতে পেয়ে টাকা দিন'],
                    ['icon' => '⇄', 'title' => 'সারা দেশে ডেলিভারি', 'text' => 'ঢাকায় ২৪–৪৮ ঘণ্টায়'],
                    ['icon' => '☎', 'title' => 'ফোনে অর্ডার নিশ্চিত', 'text' => 'অর্ডারের পর আমরা কল করি'],
                ],
            ]),
            self::block(CategoryTiles::class, [
                'heading' => 'মান অনুযায়ী কিনুন',
                'all_label' => 'সব চা দেখুন →',
                'all_link' => '/#products',
            ]),
            self::block(CategoryPanels::class, [
                'anchor' => 'products',
                'eyebrow' => 'আমাদের চা',
                'heading' => 'যে চা দরকার, বেছে নিন',
            ]),
            self::block(HowToOrder::class, [
                'anchor' => 'how',
                'eyebrow' => 'কীভাবে অর্ডার করবেন',
                'heading' => 'মাত্র ৩ ধাপে অর্ডার করুন',
                'text' => 'কোনো অ্যাকাউন্ট খুলতে হবে না, অনলাইনে টাকাও দিতে হবে না।',
                'steps' => [
                    ['title' => 'চা ও কেজি বেছে নিন', 'text' => 'কোন চা কত কেজি লাগবে দিন, দাম নিজে থেকেই হিসাব হবে।', 'chip' => 'দাম সাথে সাথে দেখবেন'],
                    ['title' => 'নাম ও ঠিকানা দিন', 'text' => 'শুধু নাম, মোবাইল নম্বর আর ডেলিভারির ঠিকানা।', 'chip' => 'মাত্র ৩টি তথ্য'],
                    ['title' => 'আমরা ফোন করে পৌঁছে দেব', 'text' => 'অর্ডার নিশ্চিত করে চা পাঠাবো। টাকা দিন হাতে পেয়ে।', 'chip' => 'ক্যাশ অন ডেলিভারি'],
                ],
                'cta_label' => 'এখনই অর্ডার শুরু করুন',
                'cta_link' => self::BULK_ORDER_LINK,
                'show_hotline' => true,
            ]),
            self::block(VideoReviews::class, [
                'eyebrow' => 'ভিডিও রিভিউ',
                'heading' => 'ক্রেতাদের মুখেই শুনুন',
                'hint' => 'সত্যিকারের দোকানি, সত্যিকারের অভিজ্ঞতা',
                'reviews' => [
                    ['name' => 'রহিম উদ্দিন', 'place' => 'চায়ের দোকান, মিরপুর', 'product' => 'স্ট্রং', 'duration' => '১:২৪', 'quote' => 'কেজিতে ৳৬০ কম পড়ছে'],
                    ['name' => 'সালমা বেগম', 'place' => 'রেস্টুরেন্ট, চট্টগ্রাম', 'product' => 'স্পেশাল', 'duration' => '০:৫৮', 'quote' => 'অতিথিরা রং দেখে খুশি'],
                    ['name' => 'কামরুল হাসান', 'place' => 'অফিস ক্যান্টিন, গাজীপুর', 'product' => 'গোল্ড', 'duration' => '১:১০', 'quote' => 'দুই দিনে ডেলিভারি'],
                    ['name' => 'নাসির আহমেদ', 'place' => 'হোটেল, সিলেট', 'product' => 'প্রিমিয়াম', 'duration' => '১:৪২', 'quote' => 'প্রতিবার একই স্বাদ'],
                ],
            ]),
            self::block(TextReviews::class, [
                'heading' => 'দোকানিরা যা বলছেন',
                'rating_summary' => '৪.৭/৫ · ১,৪১৮টি রিভিউ',
                'note' => '২,৫০০+ দোকান ও রেস্টুরেন্ট নিয়মিত অর্ডার করে',
                'reviews' => [
                    ['name' => 'রহিম উদ্দিন', 'place' => 'চায়ের দোকান, মিরপুর', 'product' => 'স্ট্রং', 'text' => '১৫ কেজি একসাথে নিলে কেজিতে ভালো ছাড় পাই। লিকার কড়া, ক্রেতারা খুশি।', 'verified' => true],
                    ['name' => 'সালমা বেগম', 'place' => 'রেস্টুরেন্ট, চট্টগ্রাম', 'product' => 'স্পেশাল', 'text' => 'অতিথিদের জন্য স্পেশাল চা নিই। রং আর সুবাস দুটোই প্রতিবার একই রকম।', 'verified' => true],
                    ['name' => 'কামরুল হাসান', 'place' => 'অফিস ক্যান্টিন, গাজীপুর', 'product' => 'গোল্ড ও রেগুলার', 'text' => 'ফোনে অর্ডার নিশ্চিত করে, দুই দিনে চা পৌঁছে যায়। ক্যাশ অন ডেলিভারি সুবিধা।', 'verified' => true],
                ],
            ]),
        ];
    }

    /**
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    private static function pricingBlocks(): array
    {
        return [
            self::banner('বাল্ক দাম', 'বেশি নিলে দাম কম', 'প্রিমিয়াম, গোল্ড ও সিলভার, প্রতিটি চায়ের নিজস্ব দাম আছে। তিন সেগমেন্ট মিলিয়ে কার্টে মোট কত কেজি আছে, সেই ধাপ অনুযায়ী প্রতিটি চায়ের ছাড়ের দাম বসবে।'),
            self::block(PriceCalculator::class, [
                'heading' => 'হিসাব করে দেখুন',
                'text' => 'চা ও কেজি বেছে নিন, প্রতিটির দাম নিচে দেখুন',
            ]),
            self::block(PriceMatrix::class, [
                'heading' => 'কোন চায়ে কত ছাড়',
                'text' => 'কার্টের মোট কেজি অনুযায়ী প্রতিটি চায়ের দাম ও ছাড়',
            ]),
            self::block(DeliveryChart::class, [
                'heading' => 'ডেলিভারি চার্জ',
                'text' => 'কার্টের মোট কেজি যত বেশি, প্রতি কেজিতে ডেলিভারি খরচ তত কম।',
            ]),
        ];
    }

    /**
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    private static function aboutBlocks(): array
    {
        return [
            self::banner('আমাদের সম্পর্কে', 'চায়ের দোকানের জন্য একটি বিশ্বস্ত চা সরবরাহকারী', 'বাগান থেকে বাছাই করা চা, ন্যায্য দামে, সরাসরি আপনার দোকানে।'),
            self::block(Story::class, [
                'eyebrow' => 'আমাদের ভাবনা',
                'quote' => 'কম বুঝলেও সহজে চায়ের অর্ডার করা যাবে।',
                'body' => '<p>Bongotea শুরু হয়েছে একটি সহজ ভাবনা থেকে: চায়ের দোকান, রেস্টুরেন্ট আর ছোট ব্যবসাগুলো যেন ভালো মানের চা ন্যায্য দামে, ঝামেলা ছাড়াই পায়।</p>'
                    .'<p>আমরা বাগান থেকে পাতা বাছাই করি, মান যাচাই করি, তারপর সরাসরি আপনার ঠিকানায় পাঠাই। অর্ডার করতে কোনো জটিলতা নেই। চা আর কেজি বেছে নিন, বাকিটা আমরা ফোন করে নিশ্চিত করি।</p>',
            ]),
            self::block(Journey::class, [
                'eyebrow' => 'চায়ের যাত্রা',
                'heading' => 'বাগান থেকে আপনার দোকানে',
                'steps' => [
                    ['title' => 'বাগান থেকে পাতা', 'text' => 'ভালো বাগানের কচি পাতা দিয়ে শুরু।'],
                    ['title' => 'স্বাদ পরখ ও বাছাই', 'text' => 'প্রতি চালানের রং, ঘ্রাণ ও স্বাদ যাচাই।'],
                    ['title' => 'পরিষ্কার প্যাকিং', 'text' => 'ঘ্রাণ ধরে রাখতে মজবুত প্যাকেটে ভরা।'],
                    ['title' => 'আপনার দোকানে', 'text' => 'ফোনে নিশ্চিত করে সারা দেশে ডেলিভারি।'],
                ],
            ]),
            self::block(ValueCards::class, [
                'cards' => [
                    ['icon' => '✓', 'title' => 'বাছাই করা পাতা', 'text' => 'প্রতিটি চালান স্বাদ, রং ও ঘ্রাণ পরখ করে তবেই প্যাক করা হয়।'],
                    ['icon' => '৳', 'title' => 'পরিষ্কার দাম', 'text' => 'মোট কেজি অনুযায়ী এক দাম। লুকানো কোনো খরচ নেই।'],
                    ['icon' => '→', 'title' => 'সময়মতো ডেলিভারি', 'text' => 'ফোনে নিশ্চিত করার পর সারা দেশে পাঠানো হয়, টাকা দিন হাতে পেয়ে।'],
                ],
            ]),
            self::block(ChipsRow::class, [
                'heading' => 'যাঁদের জন্য আমরা',
                'chips' => ['চায়ের দোকান', 'রেস্টুরেন্ট', 'হোটেল', 'অফিস', 'মুদি দোকান', 'চা রিসেলার', 'ক্যাটারিং'],
            ]),
            self::block(CallToActionBand::class, [
                'heading' => 'আপনার দোকানের জন্য চা লাগবে?',
                'text' => 'আজই অর্ডার দিন অথবা ফোনে কথা বলুন।',
                'primary_label' => 'বাল্ক অর্ডার করুন',
                'primary_link' => self::BULK_ORDER_LINK,
                'secondary_label' => 'যোগাযোগ করুন',
                'secondary_link' => self::CONTACT_LINK,
            ]),
        ];
    }

    /**
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    private static function contactBlocks(): array
    {
        return [
            self::banner('যোগাযোগ', 'চা নিয়ে যেকোনো প্রশ্ন? আমরা আছি', 'অর্ডার, দাম বা ডেলিভারি নিয়ে জানতে ফোন করুন, হোয়াটসঅ্যাপে মেসেজ দিন অথবা নিচের ফর্মটি পূরণ করুন।'),
            self::block(ContactCards::class, [
                'whatsapp_note' => 'সাধারণত ১০ মিনিটে উত্তর',
                'email_note' => '২৪ ঘণ্টার মধ্যে উত্তর',
                'raised' => true,
            ]),
            self::block(ContactForm::class, [
                'heading' => 'মেসেজ পাঠান',
                'text' => 'আমরা ফোন করে উত্তর দেব।',
                'topics' => ['অর্ডার', 'দাম', 'ডেলিভারি', 'অন্যান্য'],
            ]),
            self::block(MapAndAddress::class, [
                'hours' => [
                    ['days' => 'শনি – বৃহস্পতি', 'hours' => 'সকাল ৯টা – রাত ৯টা'],
                    ['days' => 'শুক্রবার', 'hours' => 'বিকেল ৩টা – রাত ৯টা'],
                ],
            ]),
        ];
    }

    /**
     * The mockup's Menus, every entry for an installed Page naming that Page.
     *
     * @param  array<string, int>  $pageIds
     * @return array<string, mixed>
     */
    private static function menus(array $pageIds): array
    {
        $page = fn (string $label, string $key, ?string $note = null): array => self::entry($label, MenuLinkType::Page, ['page_id' => $pageIds[$key], 'note' => $note]);
        $home = fn (string $label, string $anchor, ?string $note = null): array => self::entry($label, MenuLinkType::Anchor, ['page_id' => $pageIds['home'], 'anchor' => $anchor, 'note' => $note]);

        return [
            'header' => [
                $page('হোম', 'home'),
                self::entry('আমাদের চা', MenuLinkType::Dropdown, ['children' => [
                    $home('প্রিমিয়াম', 'category-premium', 'সেরা বাছাই পাতা'),
                    $home('গোল্ড', 'category-gold', 'মান ও দামের ভারসাম্য'),
                    $home('সিলভার', 'category-silver', 'সাশ্রয়ী দৈনিক চা'),
                ]]),
                $page('দাম হিসাব', 'pricing'),
                self::entry('কীভাবে অর্ডার করবেন', MenuLinkType::Dropdown, ['children' => [
                    $home('অর্ডারের ধাপ', 'how', '৩ ধাপে সহজ অর্ডার'),
                    $page('দাম ও বাল্ক ছাড়', 'pricing', 'বেশি নিলে কেজিপ্রতি দাম কম'),
                    $page('বাল্ক অর্ডার করুন', 'bulk_order'),
                    $page('আমার অর্ডার ও ডেলিভারি', 'my_orders'),
                ]]),
                $page('যোগাযোগ', 'contact'),
            ],
            'footerColumns' => [
                ['heading' => 'অর্ডার', 'links' => [
                    $page('হোম', 'home'),
                    $home('আমাদের চা', 'products'),
                    $page('দাম হিসাব', 'pricing'),
                    $page('বাল্ক অর্ডার', 'bulk_order'),
                    $page('আমার অর্ডার', 'my_orders'),
                ]],
                ['heading' => 'Bongotea', 'links' => [
                    $page('আমাদের সম্পর্কে', 'about'),
                    $page('যোগাযোগ', 'contact'),
                    $home('কীভাবে অর্ডার করবেন', 'how'),
                ]],
            ],
            'footerBottomLinks' => [
                $page('আমাদের সম্পর্কে', 'about'),
                $page('যোগাযোগ', 'contact'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    private static function entry(string $label, MenuLinkType $type, array $target): array
    {
        return ['label' => $label, 'type' => $type->value, 'page_id' => null, 'anchor' => null, 'url' => null, 'note' => null, ...$target];
    }
}
