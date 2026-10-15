<?php

namespace Database\Seeders;

use App\Enums\OrderKind;
use App\Enums\PageStatus;
use App\Enums\SystemPage;
use App\Models\Cart;
use App\Models\Page;
use App\Models\Setting;
use App\Support\Pages\Blocks\CartBlock;
use App\Support\Pages\Blocks\CategoryPanels;
use App\Support\Pages\Blocks\CategoryTiles;
use App\Support\Pages\Blocks\HeroCarousel;
use App\Support\Pages\Blocks\HowToOrder;
use App\Support\Pages\Blocks\MyOrders;
use App\Support\Pages\Blocks\PromiseStrip;
use App\Support\Pages\Blocks\TextReviews;
use App\Support\Pages\Blocks\VideoReviews;
use App\Support\Pages\PageBlock;
use App\Support\Settings\HeaderSettings;
use Illuminate\Database\Seeder;

/**
 * The System Pages every store opens with, blank or demo (ADR-0030): home
 * and the customer's orders, in the mockup's Blocks and copy.
 *
 * A System Page is found by its role, which can never change or be deleted,
 * so each is created exactly once and whatever the Merchant has done to it
 * since is left as it is. Its live Blocks read the catalogue as it stands,
 * so on a blank store they simply draw nothing until there are Products.
 * The ordinary Pages and the Menus are `DemoStoreSeeder`'s and
 * `BlankStoreSeeder`'s.
 *
 * The two starting Carts, খুচরা অর্ডার করুন and পাইকারি অর্ডার করুন, are made
 * here too (ADR-0038), once each and in the sort order the header draws
 * them, each on an ordinary Page of its own and wired to the header's
 * buttons.
 */
class StorefrontSeeder extends Seeder
{
    private const BULK_ORDER_LINK = '/bulk-order';

    private const PRICING_LINK = '/pricing';

    public function run(): void
    {
        $retail = $this->startingCart(OrderKind::Retail, 'খুচরা অর্ডার করুন', 0);
        $bulk = $this->startingCart(OrderKind::Bulk, 'পাইকারি অর্ডার করুন', 1);

        $this->systemPage(SystemPage::Home, 'হোম', 'home', self::homeBlocks());

        $this->cartPage($bulk, 'পাইকারি অর্ডার', 'bulk-order', [
            self::block(CartBlock::class, [
                'cart_id' => $bulk->getKey(),
                'heading' => 'একসাথে চা অর্ডার করুন',
                'text' => 'যে যে চা লাগবে, শুধু কত কেজি লাগবে সেটি দিন। সব চা মিলিয়ে মোট কেজি অনুযায়ী দাম নিজে থেকেই হিসাব হবে।',
                'quick_chips' => true,
                'show_tier_list' => true,
            ]),
        ]);

        $this->cartPage($retail, 'খুচরা কার্ট', 'packet-basket', [
            self::block(CartBlock::class, [
                'cart_id' => $retail->getKey(),
                'heading' => 'আপনার খুচরা কার্ট',
                'text' => 'নিজের কাপের জন্য প্যাকেটে চা। প্যাকেটের দাম আলাদা, পাইকারি কার্টের সাথে মেলানো হয় না।',
            ]),
        ]);

        $this->systemPage(SystemPage::MyOrders, 'আমার অর্ডার', 'my-orders', [
            self::block(MyOrders::class, [
                'heading' => 'আমার অর্ডার',
                'text' => 'মোবাইল নম্বর দিয়ে ঢুকুন। পাসওয়ার্ড লাগবে না।',
            ]),
        ]);

        $this->wireHeader($retail, $bulk);
    }

    /**
     * A starting Cart's own Page, an ordinary one, made only when the Cart was
     * made in this run and the slug is free: a Merchant who has since moved,
     * edited or deleted it finds it as they left it.
     *
     * @param  list<array{type: string, data: array<string, mixed>}>  $blocks
     */
    private function cartPage(Cart $cart, string $title, string $slug, array $blocks): void
    {
        if (! $cart->wasRecentlyCreated || Page::query()->where('slug', $slug)->exists()) {
            return;
        }

        Page::query()->create([
            'title' => $title,
            'slug' => $slug,
            'status' => PageStatus::Published,
            'blocks' => $blocks,
        ]);
    }

    /**
     * Points the header's খুচরা অর্ডার করুন and পাইকারি অর্ডার করুন buttons at the
     * starting Carts made in this run, and never at a choice made since.
     */
    private function wireHeader(Cart $retail, Cart $bulk): void
    {
        $wired = [
            ...($retail->wasRecentlyCreated ? ['retailCartId' => $retail->getKey()] : []),
            ...($bulk->wasRecentlyCreated ? ['bulkCartId' => $bulk->getKey()] : []),
        ];

        if ($wired === []) {
            return;
        }

        /** Only these two keys are written, so every other header setting keeps its default. */
        $row = Setting::query()->find(HeaderSettings::key());
        Setting::query()->updateOrCreate(['key' => HeaderSettings::key()], ['value' => [...($row->value ?? []), ...$wired]]);
    }

    /**
     * @param  list<array{type: string, data: array<string, mixed>}>  $blocks
     */
    private function systemPage(SystemPage $role, string $title, string $slug, array $blocks): Page
    {
        return Page::query()->system($role)->first()
            ?? Page::query()->create([
                'title' => $title,
                'slug' => $slug,
                'status' => PageStatus::Published,
                'blocks' => $blocks,
                'system_role' => $role,
            ]);
    }

    /**
     * The Cart the store opens with for that kind, made once and found again
     * by its kind however it was renamed, switched off or deleted since.
     */
    private function startingCart(OrderKind $kind, string $name, int $sortOrder): Cart
    {
        if ($cart = Cart::startingFor($kind)) {
            return $cart;
        }

        $cart = new Cart(['name' => $name, 'kind' => $kind, 'sort_order' => $sortOrder]);
        $cart->starting_kind = $kind;
        $cart->save();

        return $cart;
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
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    private static function homeBlocks(): array
    {
        $slide = fn (string $eyebrow, string $title, string $text): array => [
            'eyebrow' => $eyebrow,
            'title' => $title,
            'text' => $text,
            'primary_label' => 'পাইকারি অর্ডার করুন',
            'primary_link' => self::BULK_ORDER_LINK,
            'secondary_label' => 'চায়ের দাম দেখুন',
            'secondary_link' => self::PRICING_LINK,
        ];

        return [
            self::block(HeroCarousel::class, [
                'layout' => 'carousel',
                'slides' => [
                    $slide('চায়ের দোকান ও ব্যবসার জন্য', 'আপনার ব্যবসার জন্য ভালো চা, সহজে অর্ডার করুন', 'চায়ের দোকান, রেস্টুরেন্ট কিংবা ব্যবসার জন্য প্রয়োজন অনুযায়ী চা নিন। একসাথে বেশি নিলে প্রতি কেজির দাম আরও কম।'),
                    $slide('পাইকারি দাম', 'বেশি নিলে দাম কম', 'সব চা মিলিয়ে ১০ কেজি নিলে হোলসেল দাম, ২০ কেজি বা তার বেশি নিলে ডিস্ট্রিবিউটর দাম।'),
                    $slide('সহজ অর্ডার', 'কম বুঝলেও সহজে চায়ের অর্ডার', 'কোন চা কত কেজি লাগবে দিন, বাকি হিসাব আমাদের।'),
                ],
            ]),
            self::block(PromiseStrip::class, [
                'items' => [
                    ['icon' => '৳', 'title' => 'বেশি নিলে কম দাম', 'text' => '২০+ কেজিতে ডিস্ট্রিবিউটর দাম'],
                    ['icon' => '✓', 'title' => 'ক্যাশ অন ডেলিভারি', 'text' => 'চা হাতে পেয়ে টাকা দিন'],
                    ['icon' => '⇄', 'title' => 'সারা দেশে ডেলিভারি', 'text' => 'ঢাকায় ২৪–৪৮ ঘণ্টায়'],
                    ['icon' => '☎', 'title' => 'ফোনে অর্ডার নিশ্চিত', 'text' => 'অর্ডারের পর আমরা কল করি'],
                ],
            ]),
            self::block(CategoryTiles::class, [
                'heading' => 'আপনার জন্য কোন চা',
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
                    ['name' => 'রহিম উদ্দিন', 'place' => 'চায়ের দোকান, মিরপুর', 'product' => 'টি স্টল প্রিমিয়াম CD', 'duration' => '১:২৪', 'quote' => 'কড়া লিকার, কাপ বেশি'],
                    ['name' => 'সালমা বেগম', 'place' => 'রেস্টুরেন্ট, চট্টগ্রাম', 'product' => 'টি স্টল প্রিমিয়াম BOP', 'duration' => '০:৫৮', 'quote' => 'অতিথিরা রং দেখে খুশি'],
                    ['name' => 'কামরুল হাসান', 'place' => 'অফিস ক্যান্টিন, গাজীপুর', 'product' => 'টি স্টল ক্লাসিক PD', 'duration' => '১:১০', 'quote' => 'দুই দিনে ডেলিভারি'],
                    ['name' => 'নাসির আহমেদ', 'place' => 'হোটেল, সিলেট', 'product' => 'টি স্টল প্রিমিয়াম লিফ', 'duration' => '১:৪২', 'quote' => 'প্রতিবার একই স্বাদ'],
                ],
            ]),
            self::block(TextReviews::class, [
                'heading' => 'দোকানিরা যা বলছেন',
                'rating_summary' => '৪.৭/৫ · ১,৪১৮টি রিভিউ',
                'note' => '২,৫০০+ দোকান ও রেস্টুরেন্ট নিয়মিত অর্ডার করে',
                'reviews' => [
                    ['name' => 'রহিম উদ্দিন', 'place' => 'চায়ের দোকান, মিরপুর', 'product' => 'টি স্টল প্রিমিয়াম CD', 'text' => 'কয়েক রকম চা মিলিয়ে ২০ কেজি নিলে ডিস্ট্রিবিউটর দাম পাই। লিকার কড়া, ক্রেতারা খুশি।', 'verified' => true],
                    ['name' => 'সালমা বেগম', 'place' => 'রেস্টুরেন্ট, চট্টগ্রাম', 'product' => 'টি স্টল প্রিমিয়াম BOP', 'text' => 'অতিথিদের জন্য এই চা নিই। রং আর সুবাস দুটোই প্রতিবার একই রকম।', 'verified' => true],
                    ['name' => 'কামরুল হাসান', 'place' => 'অফিস ক্যান্টিন, গাজীপুর', 'product' => 'টি স্টল ক্লাসিক PD', 'text' => 'ফোনে অর্ডার নিশ্চিত করে, দুই দিনে চা পৌঁছে যায়। ক্যাশ অন ডেলিভারি সুবিধা।', 'verified' => true],
                ],
            ]),
        ];
    }
}
