<?php

namespace Database\Seeders;

use App\Enums\CategoryTheme;
use App\Enums\ProductStatus;
use App\Enums\Strength;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tag;
use App\Support\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The demo catalogue: the Bongotea Brand, the three Categories a tea is sold
 * under — টি স্টল প্রিমিয়াম, টি স্টল ক্লাসিক and ফ্যামিলি ব্লেন্ড — with their
 * Themes, the four Tags, and fifteen Products: each tea-stall line in all
 * five Grades (লিফ, BOP, PD, ডাস্ট, CD) and five teas for the family table.
 * Each tea's Particle size follows its Grade — a leaf বড়, BOP মাঝারি, PD and
 * CD ছোট, ডাস্ট খুব ছোট — and every one keeps the common Storage tips.
 *
 * It is sample stock for a store that has none: seeded whole, in one
 * transaction, and only while the store has no catalogue at all. So running
 * it again adds nothing twice and puts back nothing a Merchant has renamed,
 * edited or deleted, and an install that failed half-way left nothing
 * behind to stop the next run.
 *
 * Every Product is priced on the same three Tiers the store's copy names —
 * ১–৯ কেজি (Trade), ১০–১৯ কেজি (Wholesale) and ২০+ কেজি (Distributor) — at
 * typed whole-taka Tier Prices (ADR-0031) a few percent apart, each below
 * the Product's MRP. Brewing figures follow the store's own চা তৈরির নিয়ম:
 * ২.৫০–২.৭৫ গ্রাম for লিফ and BOP, ২.০০–২.৫০ গ্রাম for PD, ডাস্ট and CD.
 *
 * Every Product is sold পাইকারি in 500 g and 1 kg bags; the family teas are
 * sold খুচরা as well, in 100 g and 250 g packets (ADR-0036).
 */
class CatalogueSeeder extends Seeder
{
    /** The three weights every Product is priced at, by the whole cart's Kilograms. */
    private const RANGES = [[1, 9], [10, 19], [20, null]];

    /** The Category whose teas are sold খুচরা too, in packets for the family table; the tea-stall ones are পাইকারি alone. */
    private const RETAIL_CATEGORY = 'family-blend';

    private const CATEGORIES = [
        [
            'slug' => 'tea-stall-premium',
            'name' => 'টি স্টল প্রিমিয়াম',
            'note' => 'চায়ের দোকান ও হোটেলের সেরা বাছাই',
            'quality_headline' => 'প্রতিটি কাপে গাঢ় রং, ভরাট স্বাদ',
            'about' => 'চায়ের দোকান, হোটেল ও রেস্টুরেন্টের জন্য বঙ্গ টি-এর সেরা বাছাই। লিফ, BOP, PD, ডাস্ট ও CD — পাঁচ গ্রেডেই পাওয়া যায়, তাই নিয়মিত পরিবেশন থেকে দ্রুত কাপ প্রস্তুতি পর্যন্ত প্রতিটি প্রয়োজনে একই মান।',
            'why_points' => [
                'পাঁচ গ্রেড: লিফ, BOP, PD, ডাস্ট ও CD',
                'দুধ চা ও রং চায়ে গাঢ় রং ও ভরাট স্বাদ',
                'সব SKU মিলিয়ে মোট ওজনে ট্রেড, পাইকারি ও পরিবেশক মূল্য',
            ],
            'seo_title' => 'টি স্টল প্রিমিয়াম — চায়ের দোকান ও হোটেলের চা | বঙ্গ টি',
            'seo_description' => 'চায়ের দোকান, হোটেল ও রেস্টুরেন্টের জন্য বঙ্গ টি-এর প্রিমিয়াম চা — লিফ, BOP, PD, ডাস্ট ও CD। মোট ওজন অনুযায়ী ব্যবসায়িক মূল্য।',
            'theme' => CategoryTheme::Premium,
        ],
        [
            'slug' => 'tea-stall-classic',
            'name' => 'টি স্টল ক্লাসিক',
            'note' => 'প্রতিদিনের বিক্রির সাশ্রয়ী চা',
            'quality_headline' => 'সাশ্রয়ী দামে ভরসার স্বাদ',
            'about' => 'ব্যস্ত চায়ের দোকান, ক্যান্টিন ও পাইকারি বাজারের জন্য — যাদের প্রতিদিন অনেক কাপ চা বানাতে হয়। পাঁচ গ্রেডে চেনা স্বাদ ও রং, কাপপ্রতি খরচ নাগালে।',
            'why_points' => [
                'কাপপ্রতি খরচ কম, প্রতিদিনের বিক্রিতে সুবিধা',
                'দ্রুত ব্রিউয়ের জন্য PD, ডাস্ট ও CD',
                'বিভিন্ন গ্রেড এক অর্ডারে মিলিয়ে নেওয়া যায়',
            ],
            'seo_title' => 'টি স্টল ক্লাসিক — সাশ্রয়ী দোকানের চা | বঙ্গ টি',
            'seo_description' => 'ব্যস্ত চায়ের দোকান ও পাইকারি বাজারের জন্য সাশ্রয়ী চা — লিফ, BOP, PD, ডাস্ট ও CD। কাপপ্রতি কম খরচে চেনা স্বাদ।',
            'theme' => CategoryTheme::Gold,
        ],
        [
            'slug' => 'family-blend',
            'name' => 'ফ্যামিলি ব্লেন্ড',
            'note' => 'পরিবারের টেবিলের জন্য বাছাই করা চা',
            'quality_headline' => 'প্রতিদিনের চা থেকে বিশেষ মুহূর্তের চা',
            'about' => 'পরিবারের সকালের চা, বিকেলের আড্ডা কিংবা অতিথি আপ্যায়ন — প্রতিদিনের বঙ্গ টি লিফ থেকে প্রিমিয়াম ক্লোন, ফার্স্ট ফ্লাশ, গ্রিন টি ও ইয়েলো টি পর্যন্ত, আপনার স্বাদ অনুযায়ী বেছে নিন।',
            'why_points' => [
                'দৈনন্দিন চা ও বিশেষ চা এক জায়গায়',
                'হালকা ও সতেজ থেকে ভরাট — নানা স্বাদ ও ধরন',
                'ঘরে রাখার উপযোগী ছোট প্যাক',
            ],
            'seo_title' => 'ফ্যামিলি ব্লেন্ড — পরিবারের জন্য প্রিমিয়াম চা | বঙ্গ টি',
            'seo_description' => 'পরিবারের জন্য বঙ্গ টি লিফ, প্রিমিয়াম ক্লোন, ফার্স্ট ফ্লাশ, গ্রিন টি ও ইয়েলো টি — প্রতিদিনের চা থেকে বিশেষ মুহূর্তের চা।',
            'theme' => CategoryTheme::Garden,
        ],
    ];

    /** By slug, in the order the store filters by them. */
    private const TAGS = [
        'milk' => 'দুধ চায়ের জন্য',
        'plain' => 'রং চায়ের জন্য',
        'strong' => 'কড়া লিকার',
        'hotel' => 'রেস্টুরেন্ট ও হোটেল',
    ];

    public function run(): void
    {
        if (self::hasCatalogue()) {
            return;
        }

        DB::transaction(function (): void {
            $this->seed();
        });
    }

    /**
     * Whether the store already has a catalogue of its own — anything at all,
     * a Product taken out of it included — in which case it is never handed
     * the demo one.
     */
    public static function hasCatalogue(): bool
    {
        return Brand::query()->exists()
            || Category::query()->exists()
            || Tag::query()->exists()
            || Product::withTrashed()->exists();
    }

    private function seed(): void
    {
        $brand = Brand::query()->create(['slug' => 'bongotea', 'name' => 'Bongotea']);

        $categories = [];

        foreach (self::CATEGORIES as $sortOrder => $category) {
            $categories[$category['slug']] = Category::query()->create([
                ...array_diff_key($category, ['theme' => true]),
                'theme_key' => $category['theme']->value,
                'sort_order' => $sortOrder,
            ]);
        }

        $tags = [];

        foreach (array_keys(self::TAGS) as $sortOrder => $slug) {
            $tags[$slug] = Tag::query()->create(['slug' => $slug, 'name' => self::TAGS[$slug], 'sort_order' => $sortOrder]);
        }

        foreach (self::products() as $product) {
            $soldRetail = $product['category'] === self::RETAIL_CATEGORY;

            $created = Product::query()->create([
                ...array_diff_key($product, array_flip(['category', 'tags'])),
                'category_id' => $categories[$product['category']]->id,
                'brand_id' => $brand->id,
                'status' => ProductStatus::Published,
                'is_available' => true,
                'sold_bulk' => true,
                'sold_retail' => $soldRetail,
                'storage_tips' => Product::commonStorageTips(),
            ]);

            $created->tags()->attach(array_map(fn (string $slug): int => $tags[$slug]->id, $product['tags']));

            foreach (self::packs($product, $soldRetail) as $sort => $pack) {
                $created->packs()->create([...$pack, 'sort' => $sort, 'is_available' => true]);
            }

            /** A bulk buyer starts from the 500 g business pack, as every পাইকারি tea's package weight says. */
            $created->update(['default_bulk_pack_id' => $created->packs()->where('grams', 500)->value('id')]);
        }
    }

    /**
     * A Product's Packs (ADR-0036): the 500 g and 1 kg bags a পাইকারি order
     * is sent in, and — for a family tea, sold খুচরা as well — 100 g and
     * 250 g packets priced at their share of the MRP and the first Tier
     * Price, to the whole taka.
     *
     * @param  array{mrp: int, tiers: list<array{price: int}>}  $product
     * @return list<array{grams: int, serves_bulk: bool, serves_retail: bool, mrp?: int, price?: int}>
     */
    private static function packs(array $product, bool $soldRetail): array
    {
        $taka = Money::toMinorUnits(1);
        $share = fn (int $perKilogram, int $grams): int => (int) round($perKilogram * $grams / 1000 / $taka) * $taka;

        $retail = $soldRetail ? array_map(fn (int $grams): array => [
            'grams' => $grams,
            'serves_bulk' => false,
            'serves_retail' => true,
            'mrp' => $share($product['mrp'], $grams),
            'price' => $share($product['tiers'][0]['price'], $grams),
        ], [100, 250]) : [];

        return [
            ...$retail,
            ['grams' => 500, 'serves_bulk' => true, 'serves_retail' => false],
            ['grams' => 1000, 'serves_bulk' => true, 'serves_retail' => false],
        ];
    }

    /**
     * A Product's MRP and its three Tiers, from whole taka per Kilogram,
     * stored in minor units.
     *
     * @param  array{int, int, int}  $prices  the 1–9, 10–19 and 20+ kg Tier Prices
     * @return array{mrp: int, tiers: list<array{min: int, max: int|null, price: int}>}
     */
    private static function pricing(int $mrp, array $prices): array
    {
        return [
            'mrp' => Money::toMinorUnits($mrp),
            'tiers' => array_map(fn (array $range, int $price): array => [
                'min' => $range[0],
                'max' => $range[1],
                'price' => Money::toMinorUnits($price),
            ], self::RANGES, $prices),
        ];
    }

    /**
     * How a black tea of one Grade is brewed, as চা তৈরির নিয়ম gives it: the
     * steep, the cups a Kilogram makes and the steps, shared by every tea of
     * that Grade whichever Category it is sold under.
     *
     * @param  'leaf'|'bop'|'pd'|'dust'|'cd'  $grade
     * @return array{infusion: string, cups_per_kilogram: string, brewing_steps: list<string>}
     */
    private static function brewing(string $grade): array
    {
        $milk = 'দুধ চায়ে কাপপ্রতি ৪০–৬০ মিলি দুধ ও স্বাদমতো চিনি (৩–৬ গ্রাম) মিশিয়ে অল্প গরম করে ছেঁকে পরিবেশন করুন।';

        if (in_array($grade, ['leaf', 'bop'], true)) {
            return [
                'infusion' => '৩–৫ মিনিট',
                'cups_per_kilogram' => '১ কেজিতে প্রায় ৩৬০–৪০০ কাপ',
                'brewing_steps' => [
                    'প্রতি কাপে ১০০–১২০ মিলি পানি ও ২.৫০–২.৭৫ গ্রাম চা পাতা নিন।',
                    'পানি প্রায় ৯৫–১০০°C পর্যন্ত গরম করে চা পাতা দিন, ৩–৫ মিনিট ব্রিউ হতে দিন।',
                    $milk,
                    'দীর্ঘ সময় জোরে ফুটিয়ে রাখবেন না, এতে তিক্ততা বাড়ে।',
                ],
            ];
        }

        $steep = $grade === 'pd' ? '২–৪ মিনিট' : '২–৩ মিনিট';

        return [
            'infusion' => $steep,
            'cups_per_kilogram' => '১ কেজিতে প্রায় ৪০০–৫০০ কাপ',
            'brewing_steps' => [
                'প্রতি কাপে ১০০–১২০ মিলি পানি ও ২.০০–২.৫০ গ্রাম চা পাতা নিন — এর বেশি দেবেন না।',
                "পানি ফুটে উঠলে চা পাতা দিন; অল্প সময় ({$steep}) ব্রিউ করে রং ও স্বাদ দেখে নিন।",
                $milk,
                'বেশি গাঢ় বা তিক্ত লাগলে পরের কাপে চায়ের পরিমাণ ও সময় কমিয়ে দিন।',
            ],
        ];
    }

    /**
     * The fifteen teas, Category by Category in the order the store shows
     * them, which is the order they are created in.
     *
     * @return list<array<string, mixed>>
     */
    private static function products(): array
    {
        return [
            ...self::teaStallPremium(),
            ...self::teaStallClassic(),
            ...self::familyBlend(),
        ];
    }

    /**
     * টি স্টল প্রিমিয়াম, লিফ to CD.
     *
     * @return list<array<string, mixed>>
     */
    private static function teaStallPremium(): array
    {
        return [
            [
                'slug' => 'tea-stall-premium-leaf', 'category' => 'tea-stall-premium', 'name' => 'টি স্টল প্রিমিয়াম লিফ',
                ...self::pricing(640, [600, 575, 550]),
                'tags' => ['milk', 'plain', 'hotel'],
                'badge' => 'সবচেয়ে জনপ্রিয়',
                'short_description' => 'মাঝারি দানার ভারসাম্যপূর্ণ লিফ চা — চায়ের দোকান ও হোটেলে নিয়মিত দুধ চা ও রং চায়ের জন্য।',
                'description' => '<p>টি স্টল প্রিমিয়াম লিফ হলো বঙ্গ টি-এর মাঝারি দানার ব্ল্যাক টি, বাছাই করা হয়েছে চায়ের দোকান, হোটেল ও রেস্টুরেন্টের নিয়মিত পরিবেশনের জন্য। স্বাদ, রং ও ঘনত্বের মধ্যে ভারসাম্য রাখাই এই গ্রেডের বৈশিষ্ট্য।</p>'
                    .'<h3>স্বাদ ও রং</h3><p>লিকার উজ্জ্বল লালচে বাদামি, স্বাদ ভরাট কিন্তু অতিরিক্ত কড়া নয়। দুধ মেশালে সুন্দর বাদামি রং আসে, আর রং চায়ে চায়ের নিজস্ব ঘ্রাণ স্পষ্ট থাকে।</p>'
                    .'<h3>কার জন্য</h3><ul><li>যে দোকানে দুধ চা ও রং চা দুটোই সমানে চলে</li><li>হোটেল ও রেস্টুরেন্টে অতিথি পরিবেশন</li><li>অফিসের নিয়মিত চা</li></ul>'
                    .'<h3>কীভাবে বানাবেন</h3><p>প্রতি কাপে ১০০–১২০ মিলি পানিতে ২.৫০–২.৭৫ গ্রাম চা দিন এবং ৯৫–১০০°C পানিতে ৩–৫ মিনিট ব্রিউ করুন। দুধ চায়ে কাপপ্রতি ৪০–৬০ মিলি দুধই যথেষ্ট।</p>',
                'best_for' => ['নিয়মিত দুধ চা', 'রং চা', 'হোটেল ও রেস্টুরেন্ট'],
                'not_best_for' => 'খুব দ্রুত কাপ বানাতে হলে টি স্টল প্রিমিয়াম ডাস্ট বা CD বেশি সুবিধাজনক।',
                'business_tips' => [
                    'দুধ চা ও রং চা — দুটোর জন্য আলাদা চা রাখতে না চাইলে এক লিফেই দুটো চালানো যায়।',
                    'প্রতিটি কাপের জন্য একই মাপের চামচ ব্যবহার করুন, তাহলে প্রতিদিন একই স্বাদ থাকে।',
                    'দিনের শুরুতে একটি পরীক্ষামূলক কাপ বানিয়ে স্বাদ যাচাই করে নিন।',
                ],
                'search_keywords' => 'লিফ চা, প্রিমিয়াম লিফ, leaf cha, leaf tea, lif cha, premium leaf tea, চায়ের দোকানের চা, tea stall tea',
                'seo_title' => 'টি স্টল প্রিমিয়াম লিফ — ভারসাম্যপূর্ণ দোকানের চা | বঙ্গ টি',
                'seo_description' => 'মাঝারি দানার প্রিমিয়াম লিফ চা — চায়ের দোকান, হোটেল ও রেস্টুরেন্টে নিয়মিত দুধ চা ও রং চায়ের জন্য ভারসাম্যপূর্ণ স্বাদ ও রং।',
                'strength' => Strength::Four,
                'tea_type' => 'ব্ল্যাক টি',
                'grade' => 'লিফ',
                'particle_size' => 'বড়',
                'liquor_colour' => 'উজ্জ্বল লালচে বাদামি',
                'liquor_body' => 'মাঝারি ভরাট, মসৃণ',
                'aroma' => 'পরিষ্কার, তাজা চায়ের ঘ্রাণ',
                ...self::brewing('leaf'),
                'package_weight' => '৫০০ গ্রাম ব্যবসায়িক প্যাক',
                'custom_characteristics' => [
                    ['label' => 'পানির তাপমাত্রা', 'value' => 'প্রায় ৯৫–১০০°C'],
                    ['label' => 'সেরা পরিবেশন', 'value' => 'দুধ চা ও রং চা'],
                ],
            ],
            [
                'slug' => 'tea-stall-premium-bop', 'category' => 'tea-stall-premium', 'name' => 'টি স্টল প্রিমিয়াম BOP',
                ...self::pricing(620, [580, 555, 530]),
                'tags' => ['milk', 'plain', 'hotel'],
                'short_description' => 'বড় ভাঙা দানার BOP — কাপে চায়ের চরিত্র ও ঘ্রাণ চান এমন দোকান ও রেস্টুরেন্টের জন্য।',
                'description' => '<p>টি স্টল প্রিমিয়াম BOP তুলনামূলক বড় ভাঙা দানার ব্ল্যাক টি। ভাঙা পাতার গঠনের কারণে কাপের স্বাদ ও চায়ের চরিত্রে ভারসাম্যপূর্ণ অভিজ্ঞতা দেয়।</p>'
                    .'<h3>স্বাদ ও রং</h3><p>লিকার লালচে সোনালি, স্বাদ পরিষ্কার ও দৃঢ়, পেছনে হালকা মিষ্টি ঘ্রাণ থাকে। রং চা ও লেবু চায়ে এর চরিত্র সবচেয়ে ভালো বোঝা যায়, দুধ চায়েও স্বাদ হারায় না।</p>'
                    .'<h3>কার জন্য</h3><ul><li>রেস্টুরেন্ট ও হোটেলের নিয়মিত পরিবেশন</li><li>রং চা ও লেবু চা যেখানে বেশি চলে</li><li>খাবারের পরে অতিথিকে চা</li></ul>'
                    .'<h3>কীভাবে বানাবেন</h3><p>প্রতি কাপে ১০০–১২০ মিলি পানিতে ২.৫০–২.৭৫ গ্রাম চা দিন, ৯৫–১০০°C পানিতে ৩–৫ মিনিট ব্রিউ করে ছেঁকে নিন। দীর্ঘ সময় ফুটিয়ে রাখবেন না।</p>',
                'best_for' => ['রং চা ও লেবু চা', 'রেস্টুরেন্ট', 'অতিথি পরিবেশন'],
                'not_best_for' => 'খুব কড়া লিকারের দুধ চা চাইলে টি স্টল প্রিমিয়াম PD বা ডাস্ট বেছে নিন।',
                'business_tips' => [
                    'কাচের কাপে রং চা পরিবেশন করুন — লালচে সোনালি রংটা ক্রেতার চোখে পড়ে।',
                    'খাবারের পরে চা অফার করলে রেস্টুরেন্টে চায়ের বিক্রি বাড়ে।',
                    'কর্মীদের জন্য লিখিত রেসিপি রাখুন, যাতে প্রতিটি কাপ একই রকম হয়।',
                ],
                'search_keywords' => 'বিওপি, বিওপি চা, BOP, bop cha, bop tea, broken orange pekoe, ভাঙা পাতার চা, premium bop',
                'seo_title' => 'টি স্টল প্রিমিয়াম BOP — বড় ভাঙা দানার চা | বঙ্গ টি',
                'seo_description' => 'বড় ভাঙা দানার প্রিমিয়াম BOP চা — রেস্টুরেন্ট ও চায়ের দোকানে রং চা, লেবু চা ও দুধ চায়ের জন্য পরিষ্কার স্বাদ ও লালচে সোনালি রং।',
                'strength' => Strength::FourPlus,
                'tea_type' => 'ব্ল্যাক টি',
                'grade' => 'BOP',
                'particle_size' => 'মাঝারি',
                'liquor_colour' => 'লালচে সোনালি',
                'liquor_body' => 'মাঝারি, পরিষ্কার',
                'aroma' => 'হালকা মিষ্টি, পরিষ্কার ঘ্রাণ',
                ...self::brewing('bop'),
                'package_weight' => '৫০০ গ্রাম ব্যবসায়িক প্যাক',
                'custom_characteristics' => [
                    ['label' => 'পানির তাপমাত্রা', 'value' => 'প্রায় ৯৫–১০০°C'],
                    ['label' => 'সেরা পরিবেশন', 'value' => 'রং চা ও লেবু চা'],
                ],
            ],
            [
                'slug' => 'tea-stall-premium-pd', 'category' => 'tea-stall-premium', 'name' => 'টি স্টল প্রিমিয়াম PD',
                ...self::pricing(600, [560, 535, 510]),
                'tags' => ['milk', 'strong', 'hotel'],
                'short_description' => 'মাঝারি-ছোট দানার PD — দ্রুত ব্রিউ, গাঢ় রং ও নিয়মিত পরিবেশনের জন্য।',
                'description' => '<p>টি স্টল প্রিমিয়াম PD-এর দানা লিফের চেয়ে ছোট এবং ডাস্টের চেয়ে বড়। মাঝারি-ছোট দানার এই গ্রেড দ্রুত ও কার্যকরভাবে কাপের রং ও স্বাদ তৈরি করে।</p>'
                    .'<h3>স্বাদ ও রং</h3><p>লিকার গাঢ় লাল, স্বাদ ঘন ও কড়া। দুধ চায়ে দ্রুত সুন্দর রং আসে, তাই ব্যস্ত সময়েও প্রতিটি কাপ একই রকম থাকে।</p>'
                    .'<h3>কার জন্য</h3><ul><li>ভিড়ের সময়ে দ্রুত পরিবেশন করতে হয় এমন চায়ের দোকান</li><li>হোটেল ও রেস্টুরেন্টের দুধ চা</li><li>দ্রুত সার্ভিসের ব্যবসায়িক ক্ষেত্র</li></ul>'
                    .'<h3>কীভাবে বানাবেন</h3><p>প্রতি কাপে ১০০–১২০ মিলি পানিতে ২.০০–২.৫০ গ্রাম চা দিন। পানি ফুটে উঠলে চা পাতা দিয়ে ২–৪ মিনিট ব্রিউ করুন এবং রং দেখে নামিয়ে নিন।</p>',
                'best_for' => ['দ্রুত ব্রিউ', 'ব্যস্ত চায়ের দোকান', 'কড়া দুধ চা'],
                'not_best_for' => 'হালকা রং চা পছন্দ করলে টি স্টল প্রিমিয়াম লিফ বা BOP ভালো।',
                'business_tips' => [
                    'সকালের ভিড়ের আগে এক কেতলি লিকার বানিয়ে রাখুন, বারবার পাতা দিতে হবে না।',
                    'চা পাতা মাপমতো দিন — PD দ্রুত রং ছাড়ে, বেশি দিলে খরচ ও তিক্ততা দুটোই বাড়ে।',
                    'লিফ বা ডাস্টের সঙ্গে এক অর্ডারে নিলে মোট ওজনে মূল্যস্তর কমে।',
                ],
                'search_keywords' => 'পিডি, পিডি চা, PD, pd cha, pd tea, pekoe dust, দ্রুত ব্রিউ চা, premium pd',
                'seo_title' => 'টি স্টল প্রিমিয়াম PD — দ্রুত ব্রিউয়ের চা | বঙ্গ টি',
                'seo_description' => 'মাঝারি-ছোট দানার প্রিমিয়াম PD চা — দ্রুত ব্রিউ, গাঢ় লাল লিকার ও কড়া দুধ চা। ব্যস্ত চায়ের দোকান ও রেস্টুরেন্টের জন্য।',
                'strength' => Strength::FiveMinus,
                'tea_type' => 'ব্ল্যাক টি',
                'grade' => 'PD',
                'particle_size' => 'ছোট',
                'liquor_colour' => 'গাঢ় লাল',
                'liquor_body' => 'ঘন',
                'aroma' => 'কড়া, উষ্ণ ঘ্রাণ',
                ...self::brewing('pd'),
                'package_weight' => '৫০০ গ্রাম ব্যবসায়িক প্যাক',
                'custom_characteristics' => [
                    ['label' => 'পানির তাপমাত্রা', 'value' => 'প্রায় ৯০–১০০°C'],
                    ['label' => 'সেরা পরিবেশন', 'value' => 'দুধ চা'],
                ],
            ],
            [
                'slug' => 'tea-stall-premium-dust', 'category' => 'tea-stall-premium', 'name' => 'টি স্টল প্রিমিয়াম ডাস্ট',
                ...self::pricing(580, [540, 515, 490]),
                'tags' => ['milk', 'strong'],
                'short_description' => 'ছোট দানার ডাস্ট — দ্রুত রং ও ভরাট স্বাদের কড়া দুধ চায়ের জন্য।',
                'description' => '<p>টি স্টল প্রিমিয়াম ডাস্ট ছোট দানার ব্ল্যাক টি। ছোট দানার কারণে খুব দ্রুত ব্রিউ হয় এবং অল্প সময়েই কাপে গাঢ় রং ও ভরাট স্বাদ আসে।</p>'
                    .'<h3>স্বাদ ও রং</h3><p>লিকার ঘন কালচে লাল, স্বাদ কড়া ও ঝাঁঝালো। অনেক দুধ মেশালেও চায়ের স্বাদ হারায় না — টং দোকানের চেনা কড়া দুধ চায়ের স্বাদ।</p>'
                    .'<h3>কার জন্য</h3><ul><li>সারাদিন দুধ চা চলে এমন চায়ের দোকান</li><li>দ্রুত রং ও স্বাদ যাদের দরকার</li><li>কনডেন্সড মিল্কের চা</li></ul>'
                    .'<h3>কীভাবে বানাবেন</h3><p>প্রতি কাপে ১০০–১২০ মিলি পানিতে ২.০০–২.৫০ গ্রাম চা দিন। পানি ফুটে উঠলে চা দিয়ে ২–৩ মিনিট ব্রিউ করুন — অতিরিক্ত চা না দিয়ে সময় নিয়ন্ত্রণ করুন।</p>',
                'best_for' => ['কড়া দুধ চা', 'দ্রুত রং', 'ব্যস্ত চায়ের দোকান'],
                'not_best_for' => 'হালকা রং চা বা লেবু চায়ের জন্য টি স্টল প্রিমিয়াম BOP ভালো।',
                'business_tips' => [
                    'কম পাতায় গাঢ় লিকার হয়, তাই প্রতি কাপে খরচ কমে।',
                    'বেশিক্ষণ ফুটালে তেতো হয় — ২–৩ মিনিটে নামিয়ে নিন।',
                    'প্যাকেট খোলার পর বায়ুরোধী কৌটায় রাখুন, ছোট দানা দ্রুত আর্দ্রতা টানে।',
                ],
                'search_keywords' => 'ডাস্ট, ডাস্ট চা, dust cha, dust tea, gura cha, গুঁড়া চা, কড়া চা, premium dust',
                'seo_title' => 'টি স্টল প্রিমিয়াম ডাস্ট — দ্রুত রং ও কড়া স্বাদ | বঙ্গ টি',
                'seo_description' => 'ছোট দানার প্রিমিয়াম ডাস্ট চা — দ্রুত রং, ঘন কালচে লাল লিকার ও কড়া দুধ চা। চায়ের দোকানের ব্যস্ত সময়ের জন্য।',
                'strength' => Strength::Five,
                'tea_type' => 'ব্ল্যাক টি',
                'grade' => 'ডাস্ট',
                'particle_size' => 'খুব ছোট',
                'liquor_colour' => 'ঘন কালচে লাল',
                'liquor_body' => 'ভারী, ঘন',
                'aroma' => 'কড়া, ঝাঁঝালো',
                ...self::brewing('dust'),
                'package_weight' => '৫০০ গ্রাম ব্যবসায়িক প্যাক',
                'custom_characteristics' => [
                    ['label' => 'পানির তাপমাত্রা', 'value' => 'প্রায় ৯০–১০০°C'],
                    ['label' => 'সেরা পরিবেশন', 'value' => 'কড়া দুধ চা'],
                ],
            ],
            [
                'slug' => 'tea-stall-premium-cd', 'category' => 'tea-stall-premium', 'name' => 'টি স্টল প্রিমিয়াম CD',
                ...self::pricing(560, [520, 495, 470]),
                'tags' => ['milk', 'strong'],
                'short_description' => 'খুব ছোট দানার CD — সবচেয়ে দ্রুত কাপ প্রস্তুতি ও সবচেয়ে কড়া লিকার।',
                'description' => '<p>টি স্টল প্রিমিয়াম CD বঙ্গ টি-এর সবচেয়ে ছোট দানার গ্রেড। দানা খুব ছোট হওয়ায় মুহূর্তেই রং ছাড়ে, তাই বেশি গতিতে কাপ প্রস্তুতির ব্যবসায়িক প্রয়োজনে এটি উপযোগী।</p>'
                    .'<h3>স্বাদ ও রং</h3><p>লিকার গাঢ় কালচে লাল, স্বাদ সবচেয়ে কড়া। অল্প পাতাতেই দুধ চায়ে ঘন রং আসে।</p>'
                    .'<h3>কার জন্য</h3><ul><li>রেলস্টেশন, বাজার ও টং দোকান — যেখানে একসঙ্গে অনেক কাপ লাগে</li><li>দ্রুত কাপ প্রস্তুতি</li><li>খুব কড়া দুধ চায়ের ক্রেতা</li></ul>'
                    .'<h3>কীভাবে বানাবেন</h3><p>প্রতি কাপে ১০০–১২০ মিলি পানিতে ২.০০–২.৫০ গ্রাম চা যথেষ্ট। পানি ফুটে উঠলে চা দিয়ে ২–৩ মিনিট ব্রিউ করুন; বেশি পাতা বা বেশি সময় দিলে তিক্ত হয়।</p>',
                'best_for' => ['দ্রুত কাপ প্রস্তুতি', 'টং দোকান', 'খুব কড়া দুধ চা'],
                'not_best_for' => 'অতিথি আপ্যায়নে রং চায়ের জন্য টি স্টল প্রিমিয়াম লিফ বেশি মানায়।',
                'business_tips' => [
                    'অল্প পাতায় অনেক কাপ — ভিড়ের সময়ে কাপপ্রতি খরচ সবচেয়ে কম রাখা যায়।',
                    'মাপার চামচ নির্দিষ্ট রাখুন; CD-তে সামান্য বেশি পাতাও স্বাদ বদলে দেয়।',
                    'ডাস্ট বা PD-এর সঙ্গে মিলিয়ে নিয়ে মোট ওজনে পাইকারি বা পরিবেশক মূল্য নিন।',
                ],
                'search_keywords' => 'সিডি, সিডি চা, CD, cd cha, cd tea, কড়া চা, kora cha, fast brew tea, premium cd',
                'seo_title' => 'টি স্টল প্রিমিয়াম CD — দ্রুত কাপ প্রস্তুতির চা | বঙ্গ টি',
                'seo_description' => 'খুব ছোট দানার প্রিমিয়াম CD চা — মুহূর্তে রং, সবচেয়ে কড়া লিকার। বেশি গতিতে কাপ প্রস্তুতির চায়ের দোকানের জন্য।',
                'strength' => Strength::FivePlus,
                'tea_type' => 'ব্ল্যাক টি',
                'grade' => 'CD',
                'particle_size' => 'ছোট',
                'liquor_colour' => 'গাঢ় কালচে লাল',
                'liquor_body' => 'খুব ঘন, ভারী',
                'aroma' => 'তীব্র, ঝাঁঝালো',
                ...self::brewing('cd'),
                'package_weight' => '৫০০ গ্রাম ব্যবসায়িক প্যাক',
                'custom_characteristics' => [
                    ['label' => 'পানির তাপমাত্রা', 'value' => 'প্রায় ৯০–১০০°C'],
                    ['label' => 'সেরা পরিবেশন', 'value' => 'কড়া দুধ চা ও কনডেন্সড মিল্ক চা'],
                ],
            ],
        ];
    }

    /**
     * টি স্টল ক্লাসিক, লিফ to CD.
     *
     * @return list<array<string, mixed>>
     */
    private static function teaStallClassic(): array
    {
        return [
            [
                'slug' => 'tea-stall-classic-leaf', 'category' => 'tea-stall-classic', 'name' => 'টি স্টল ক্লাসিক লিফ',
                ...self::pricing(540, [500, 480, 460]),
                'tags' => ['milk', 'plain'],
                'short_description' => 'সাশ্রয়ী দামের লিফ চা — প্রতিদিনের দুধ চা ও রং চায়ে চেনা, ভারসাম্যপূর্ণ স্বাদ।',
                'description' => '<p>টি স্টল ক্লাসিক লিফ মাঝারি দানার ব্ল্যাক টি, তৈরি হয়েছে প্রতিদিনের বিক্রির জন্য — যেখানে স্বাদ ভালো চাই, কিন্তু দামও নাগালে রাখতে হয়।</p>'
                    .'<h3>স্বাদ ও রং</h3><p>লিকার মাঝারি লাল, স্বাদ মোলায়েম ও চেনা। দুধ চা ও রং চা দুটোতেই চলে।</p>'
                    .'<h3>কার জন্য</h3><ul><li>পাড়ার চায়ের দোকান</li><li>অফিস ও ক্যান্টিন</li><li>প্রতিদিন নির্দিষ্ট সংখ্যক কাপ বিক্রি হয় এমন দোকান</li></ul>'
                    .'<h3>কীভাবে বানাবেন</h3><p>প্রতি কাপে ১০০–১২০ মিলি পানিতে ২.৫০–২.৭৫ গ্রাম চা দিন, ৯৫–১০০°C পানিতে ৩–৫ মিনিট ব্রিউ করুন। দুধ চায়ে কাপপ্রতি ৪০–৬০ মিলি দুধ মেশান।</p>',
                'best_for' => ['প্রতিদিনের দুধ চা', 'রং চা', 'অফিস ও ক্যান্টিন'],
                'not_best_for' => 'অতিথি আপ্যায়নের বিশেষ কাপের জন্য টি স্টল প্রিমিয়াম লিফ বেশি মানায়।',
                'business_tips' => [
                    'কম দামের কাপ চায়েও ভালো স্বাদ রাখা যায়, তাই নিয়মিত ক্রেতা ধরে রাখা সহজ।',
                    'প্রতি কাপের চা পাতা মেপে দিন — কাপপ্রতি খরচ হিসাব করা সহজ হয়।',
                    'মাসের শুরুতে সব গ্রেড মিলিয়ে ১০ কেজি বা বেশি নিলে পাইকারি মূল্য পাবেন।',
                ],
                'search_keywords' => 'লিফ চা, ক্লাসিক লিফ, classic leaf, leaf cha, leaf tea, সস্তা চা, sasroyi cha, daily tea',
                'seo_title' => 'টি স্টল ক্লাসিক লিফ — সাশ্রয়ী দোকানের লিফ চা | বঙ্গ টি',
                'seo_description' => 'সাশ্রয়ী দামের ক্লাসিক লিফ চা — প্রতিদিনের দুধ চা ও রং চায়ে চেনা, ভারসাম্যপূর্ণ স্বাদ। চায়ের দোকান, অফিস ও ক্যান্টিনের জন্য।',
                'strength' => Strength::FourMinus,
                'tea_type' => 'ব্ল্যাক টি',
                'grade' => 'লিফ',
                'particle_size' => 'বড়',
                'liquor_colour' => 'মাঝারি লাল',
                'liquor_body' => 'মাঝারি',
                'aroma' => 'চেনা, স্বাভাবিক চায়ের ঘ্রাণ',
                ...self::brewing('leaf'),
                'package_weight' => '৫০০ গ্রাম ব্যবসায়িক প্যাক',
                'custom_characteristics' => [
                    ['label' => 'পানির তাপমাত্রা', 'value' => 'প্রায় ৯৫–১০০°C'],
                    ['label' => 'সেরা পরিবেশন', 'value' => 'প্রতিদিনের দুধ চা ও রং চা'],
                ],
            ],
            [
                'slug' => 'tea-stall-classic-bop', 'category' => 'tea-stall-classic', 'name' => 'টি স্টল ক্লাসিক BOP',
                ...self::pricing(520, [485, 465, 445]),
                'tags' => ['milk', 'plain'],
                'short_description' => 'সাশ্রয়ী BOP — ভাঙা পাতার পরিষ্কার স্বাদ, রং চা ও দুধ চা দুটোতেই।',
                'description' => '<p>টি স্টল ক্লাসিক BOP তুলনামূলক বড় ভাঙা দানার ব্ল্যাক টি। নিয়মিত পরিবেশনে ভারসাম্যপূর্ণ স্বাদ দেয়, আর দাম থাকে প্রতিদিনের বিক্রির নাগালে।</p>'
                    .'<h3>স্বাদ ও রং</h3><p>লিকার উজ্জ্বল লাল, স্বাদ পরিষ্কার ও হালকা কড়া। লেবু চা ও আদা চায়ে ভালো মানায়।</p>'
                    .'<h3>কার জন্য</h3><ul><li>রং চা ও লেবু চা বেশি চলে এমন দোকান</li><li>ছোট রেস্টুরেন্ট ও খাবারের দোকান</li><li>বিকেলের হালকা চায়ের ক্রেতা</li></ul>'
                    .'<h3>কীভাবে বানাবেন</h3><p>প্রতি কাপে ১০০–১২০ মিলি পানিতে ২.৫০–২.৭৫ গ্রাম চা দিন, ৯৫–১০০°C পানিতে ৩–৫ মিনিট ব্রিউ করে ছেঁকে নিন।</p>',
                'best_for' => ['লেবু চা ও আদা চা', 'রং চা', 'ছোট রেস্টুরেন্ট'],
                'not_best_for' => 'খুব কড়া দুধ চা চাইলে টি স্টল ক্লাসিক ডাস্ট বা CD বেছে নিন।',
                'business_tips' => [
                    'লেবু চা ও আদা চায়ের জন্য আলাদা কেতলিতে এই চা রাখুন।',
                    'রং চায়ের দাম দুধ চায়ের চেয়ে কম রেখে বেশি কাপ বিক্রি করা যায়।',
                    'বিকেলের ক্রেতারা হালকা চা বেশি পছন্দ করেন — তখন রং চা সামনে রাখুন।',
                ],
                'search_keywords' => 'বিওপি, বিওপি চা, BOP, bop cha, bop tea, classic bop, লেবু চা, lebu cha',
                'seo_title' => 'টি স্টল ক্লাসিক BOP — সাশ্রয়ী ভাঙা পাতার চা | বঙ্গ টি',
                'seo_description' => 'সাশ্রয়ী দামের ক্লাসিক BOP চা — উজ্জ্বল লাল লিকার ও পরিষ্কার স্বাদ। রং চা, লেবু চা ও দুধ চায়ের জন্য।',
                'strength' => Strength::Four,
                'tea_type' => 'ব্ল্যাক টি',
                'grade' => 'BOP',
                'particle_size' => 'মাঝারি',
                'liquor_colour' => 'উজ্জ্বল লাল',
                'liquor_body' => 'মাঝারি, পরিষ্কার',
                'aroma' => 'সতেজ, হালকা ঘ্রাণ',
                ...self::brewing('bop'),
                'package_weight' => '৫০০ গ্রাম ব্যবসায়িক প্যাক',
                'custom_characteristics' => [
                    ['label' => 'পানির তাপমাত্রা', 'value' => 'প্রায় ৯৫–১০০°C'],
                    ['label' => 'সেরা পরিবেশন', 'value' => 'লেবু চা ও রং চা'],
                ],
            ],
            [
                'slug' => 'tea-stall-classic-pd', 'category' => 'tea-stall-classic', 'name' => 'টি স্টল ক্লাসিক PD',
                ...self::pricing(500, [465, 445, 425]),
                'tags' => ['milk', 'strong'],
                'short_description' => 'সাশ্রয়ী PD — দ্রুত ব্রিউ ও গাঢ় রং, প্রতিদিনের ব্যস্ত দোকানের দুধ চায়ের জন্য।',
                'description' => '<p>টি স্টল ক্লাসিক PD মাঝারি-ছোট দানার ব্ল্যাক টি — লিফের চেয়ে ছোট, ডাস্টের চেয়ে বড়। দ্রুত ব্রিউ হয় বলে ব্যস্ত দোকানে সময় বাঁচায়।</p>'
                    .'<h3>স্বাদ ও রং</h3><p>লিকার গাঢ় লাল, স্বাদ কড়া ও ঘন। দুধ মেশালে দ্রুত সুন্দর রং আসে।</p>'
                    .'<h3>কার জন্য</h3><ul><li>সকাল-সন্ধ্যার ভিড়ে দ্রুত পরিবেশন</li><li>কারখানা ও অফিসের ক্যান্টিন</li><li>দুধ চা প্রধান চায়ের দোকান</li></ul>'
                    .'<h3>কীভাবে বানাবেন</h3><p>প্রতি কাপে ১০০–১২০ মিলি পানিতে ২.০০–২.৫০ গ্রাম চা দিন। পানি ফুটে উঠলে চা দিয়ে ২–৪ মিনিট ব্রিউ করুন, রং দেখে নামিয়ে নিন।</p>',
                'best_for' => ['দ্রুত ব্রিউ', 'ক্যান্টিন', 'দুধ চা'],
                'not_best_for' => 'হালকা রং চা পছন্দ করলে টি স্টল ক্লাসিক BOP ভালো।',
                'business_tips' => [
                    'বড় কেতলিতে একসঙ্গে লিকার বানিয়ে রাখলে ভিড়ের সময় দ্রুত কাপ দেওয়া যায়।',
                    'অতিরিক্ত পাতা দেবেন না — PD দ্রুত রং ছাড়ে, মাপমতো দিলেই যথেষ্ট।',
                    'তৈরি দুধ চা দীর্ঘ সময় ঘরের তাপমাত্রায় রেখে দেবেন না।',
                ],
                'search_keywords' => 'পিডি, পিডি চা, PD, pd cha, pd tea, classic pd, ক্যান্টিনের চা, canteen tea',
                'seo_title' => 'টি স্টল ক্লাসিক PD — সাশ্রয়ী দ্রুত ব্রিউয়ের চা | বঙ্গ টি',
                'seo_description' => 'সাশ্রয়ী দামের ক্লাসিক PD চা — দ্রুত ব্রিউ, গাঢ় লাল লিকার ও কড়া দুধ চা। ব্যস্ত দোকান ও ক্যান্টিনের জন্য।',
                'strength' => Strength::FourPlus,
                'tea_type' => 'ব্ল্যাক টি',
                'grade' => 'PD',
                'particle_size' => 'ছোট',
                'liquor_colour' => 'গাঢ় লাল',
                'liquor_body' => 'ঘন',
                'aroma' => 'কড়া ঘ্রাণ',
                ...self::brewing('pd'),
                'package_weight' => '৫০০ গ্রাম ব্যবসায়িক প্যাক',
                'custom_characteristics' => [
                    ['label' => 'পানির তাপমাত্রা', 'value' => 'প্রায় ৯০–১০০°C'],
                    ['label' => 'সেরা পরিবেশন', 'value' => 'দুধ চা'],
                ],
            ],
            [
                'slug' => 'tea-stall-classic-dust', 'category' => 'tea-stall-classic', 'name' => 'টি স্টল ক্লাসিক ডাস্ট',
                ...self::pricing(480, [450, 430, 410]),
                'tags' => ['milk', 'strong'],
                'badge' => 'চায়ের দোকানের প্রিয়',
                'short_description' => 'সাশ্রয়ী ডাস্ট — দ্রুত রং, কড়া লিকার ও কম খরচে বেশি কাপ।',
                'description' => '<p>টি স্টল ক্লাসিক ডাস্ট ছোট দানার ব্ল্যাক টি, দেশের চায়ের দোকানে সবচেয়ে চেনা কড়া দুধ চায়ের জন্য। দ্রুত রং ও স্বাদ আসে, আর কাপপ্রতি খরচ থাকে কম।</p>'
                    .'<h3>স্বাদ ও রং</h3><p>লিকার ঘন লাল, স্বাদ কড়া। বেশি দুধ বা কনডেন্সড মিল্ক মেশালেও চায়ের স্বাদ টিকে থাকে।</p>'
                    .'<h3>কার জন্য</h3><ul><li>টং দোকান ও রাস্তার পাশের চায়ের দোকান</li><li>মেস, হোস্টেল ও কারখানা</li><li>যাদের প্রতিদিন অনেক কাপ বানাতে হয়</li></ul>'
                    .'<h3>কীভাবে বানাবেন</h3><p>প্রতি কাপে ১০০–১২০ মিলি পানিতে ২.০০–২.৫০ গ্রাম চা দিন। পানি ফুটে উঠলে চা দিয়ে ২–৩ মিনিট ব্রিউ করুন; বেশি পাতা না দিয়ে সময় নিয়ন্ত্রণ করুন।</p>',
                'best_for' => ['টং দোকান', 'কড়া দুধ চা', 'মেস ও হোস্টেল'],
                'not_best_for' => 'অতিথি আপ্যায়নের রং চায়ের জন্য টি স্টল প্রিমিয়াম BOP বেশি মানায়।',
                'business_tips' => [
                    'কম পাতায় বেশি লিকার হয়, তাই কম দামের কাপেও লাভ থাকে।',
                    'অনেক দুধ মেশালেও স্বাদ হারায় না — মালাই চা ও কনডেন্সড মিল্ক চায়ে ভালো চলে।',
                    'লিকার বেশিক্ষণ ফুটালে তেতো হয়, ২–৩ মিনিটে নামিয়ে নিন।',
                ],
                'search_keywords' => 'ডাস্ট, ডাস্ট চা, dust cha, dust tea, গুঁড়া চা, gura cha, টং চা, tong cha, kora cha',
                'seo_title' => 'টি স্টল ক্লাসিক ডাস্ট — টং দোকানের কড়া চা | বঙ্গ টি',
                'seo_description' => 'সাশ্রয়ী দামের ক্লাসিক ডাস্ট চা — দ্রুত রং, কড়া লিকার, কম খরচে বেশি কাপ। টং দোকান, মেস ও হোস্টেলের জন্য।',
                'strength' => Strength::FiveMinus,
                'tea_type' => 'ব্ল্যাক টি',
                'grade' => 'ডাস্ট',
                'particle_size' => 'খুব ছোট',
                'liquor_colour' => 'ঘন লাল',
                'liquor_body' => 'ঘন',
                'aroma' => 'কড়া, ঝাঁঝালো',
                ...self::brewing('dust'),
                'package_weight' => '৫০০ গ্রাম ব্যবসায়িক প্যাক',
                'custom_characteristics' => [
                    ['label' => 'পানির তাপমাত্রা', 'value' => 'প্রায় ৯০–১০০°C'],
                    ['label' => 'সেরা পরিবেশন', 'value' => 'কড়া দুধ চা ও মালাই চা'],
                ],
            ],
            [
                'slug' => 'tea-stall-classic-cd', 'category' => 'tea-stall-classic', 'name' => 'টি স্টল ক্লাসিক CD',
                ...self::pricing(460, [430, 410, 390]),
                'tags' => ['milk', 'strong'],
                'short_description' => 'সাশ্রয়ী CD — সবচেয়ে দ্রুত কাপ প্রস্তুতি, অল্প পাতায় কড়া লিকার।',
                'description' => '<p>টি স্টল ক্লাসিক CD খুব ছোট দানার ব্ল্যাক টি। মুহূর্তেই রং ছাড়ে, তাই একসঙ্গে অনেক কাপ দ্রুত বানাতে হলে এটি সবচেয়ে সুবিধাজনক।</p>'
                    .'<h3>স্বাদ ও রং</h3><p>লিকার ঘন কালচে লাল, স্বাদ খুব কড়া। অল্প পাতাতেই দুধ চায়ে গাঢ় রং আসে।</p>'
                    .'<h3>কার জন্য</h3><ul><li>বাজার, বাসস্ট্যান্ড ও স্টেশনের চায়ের দোকান</li><li>বড় কেতলিতে চা বানানো ক্যান্টিন</li><li>পাইকারি বাজারের দ্রুত বিক্রির চা</li></ul>'
                    .'<h3>কীভাবে বানাবেন</h3><p>প্রতি কাপে ১০০–১২০ মিলি পানিতে ২.০০–২.৫০ গ্রাম চা যথেষ্ট। পানি ফুটে উঠলে চা দিয়ে ২–৩ মিনিট ব্রিউ করুন; বেশি পাতা বা বেশি সময় দিলে তিক্ত হয়।</p>',
                'best_for' => ['দ্রুত কাপ প্রস্তুতি', 'বড় কেতলি', 'পাইকারি বিক্রি'],
                'not_best_for' => 'হালকা রং চা বা লেবু চায়ের জন্য উপযোগী নয় — টি স্টল ক্লাসিক BOP দেখুন।',
                'business_tips' => [
                    'সবচেয়ে কম পাতায় কাপ হয় — বেশি কাপ বিক্রির দোকানে কাপপ্রতি খরচ সবচেয়ে কম।',
                    'একই মাপের চামচ ব্যবহার করুন; CD-তে সামান্য বেশি পাতাও স্বাদ বদলে দেয়।',
                    'সব গ্রেড মিলিয়ে ২০ কেজি বা বেশি নিলে পরিবেশক মূল্য প্রযোজ্য।',
                ],
                'search_keywords' => 'সিডি, সিডি চা, CD, cd cha, cd tea, classic cd, কড়া চা, kora cha, সস্তা চা',
                'seo_title' => 'টি স্টল ক্লাসিক CD — সাশ্রয়ী দ্রুত কাপের চা | বঙ্গ টি',
                'seo_description' => 'সাশ্রয়ী দামের ক্লাসিক CD চা — মুহূর্তে রং, অল্প পাতায় কড়া লিকার। বাজার, স্টেশন ও ক্যান্টিনের দ্রুত কাপ প্রস্তুতির জন্য।',
                'strength' => Strength::Five,
                'tea_type' => 'ব্ল্যাক টি',
                'grade' => 'CD',
                'particle_size' => 'ছোট',
                'liquor_colour' => 'ঘন কালচে লাল',
                'liquor_body' => 'খুব ঘন',
                'aroma' => 'তীব্র, ঝাঁঝালো',
                ...self::brewing('cd'),
                'package_weight' => '৫০০ গ্রাম ব্যবসায়িক প্যাক',
                'custom_characteristics' => [
                    ['label' => 'পানির তাপমাত্রা', 'value' => 'প্রায় ৯০–১০০°C'],
                    ['label' => 'সেরা পরিবেশন', 'value' => 'কড়া দুধ চা'],
                ],
            ],
        ];
    }

    /**
     * ফ্যামিলি ব্লেন্ড: the everyday leaf, then the special teas.
     *
     * @return list<array<string, mixed>>
     */
    private static function familyBlend(): array
    {
        return [
            [
                'slug' => 'family-bongo-tea-leaf', 'category' => 'family-blend', 'name' => 'বঙ্গ টি লিফ',
                ...self::pricing(700, [660, 630, 600]),
                'tags' => ['milk', 'plain'],
                'short_description' => 'পরিবারের প্রতিদিনের চা — ভারসাম্যপূর্ণ লিফ, সকালের দুধ চা থেকে বিকেলের রং চা।',
                'description' => '<p>বঙ্গ টি লিফ পরিবারের দৈনন্দিন চায়ের জন্য বাছাই করা মাঝারি দানার ব্ল্যাক টি। সকালের প্রথম কাপ, বিকেলের আড্ডা কিংবা হঠাৎ আসা অতিথি — প্রতিটি মুহূর্তে চেনা, ভরসার স্বাদ।</p>'
                    .'<h3>স্বাদ ও রং</h3><p>লিকার উজ্জ্বল লালচে বাদামি, স্বাদ মসৃণ ও ভারসাম্যপূর্ণ। দুধ চায়ে মোলায়েম রং আসে, রং চায়ে চায়ের স্বাভাবিক ঘ্রাণ পাওয়া যায়।</p>'
                    .'<h3>কার জন্য</h3><ul><li>পরিবারের প্রতিদিনের দুধ চা ও রং চা</li><li>অতিথি আপ্যায়ন</li><li>বাসার ছোট অফিস ও কাজের জায়গা</li></ul>'
                    .'<h3>কীভাবে বানাবেন</h3><p>প্রতি কাপে ১০০–১২০ মিলি পানিতে ২.৫০–২.৭৫ গ্রাম চা দিন, ৯৫–১০০°C পানিতে ৩–৫ মিনিট ব্রিউ করুন। দুধ চায়ে কাপপ্রতি ৪০–৬০ মিলি দুধ ও স্বাদমতো চিনি দিন।</p>',
                'best_for' => ['পরিবারের প্রতিদিনের চা', 'সকালের দুধ চা', 'অতিথি আপ্যায়ন'],
                'not_best_for' => 'হালকা ও সতেজ স্বাদ চাইলে গ্রিন টি বা ফার্স্ট ফ্লাশ টি বেছে নিন।',
                'business_tips' => [
                    'প্যাকেট খোলার পর চা বায়ুরোধী কৌটায় রাখুন, ঘ্রাণ অনেক দিন ভালো থাকে।',
                    'রান্নাঘরে মসলার তাক থেকে দূরে রাখুন — চা আশপাশের গন্ধ টেনে নেয়।',
                    'ভেজা চামচ দিয়ে চা তুলবেন না; শুকনো চামচ রাখুন কৌটার পাশে।',
                ],
                'search_keywords' => 'বঙ্গ টি লিফ, bongo tea leaf, bongo tea, পরিবারের চা, family tea, বাসার চা, leaf cha, daily tea',
                'seo_title' => 'বঙ্গ টি লিফ — পরিবারের প্রতিদিনের চা | বঙ্গ টি',
                'seo_description' => 'পরিবারের দৈনন্দিন চায়ের জন্য বাছাই করা বঙ্গ টি লিফ — মসৃণ, ভারসাম্যপূর্ণ স্বাদ; সকালের দুধ চা থেকে বিকেলের রং চা।',
                'strength' => Strength::FourMinus,
                'tea_type' => 'ব্ল্যাক টি',
                'grade' => 'লিফ',
                'particle_size' => 'বড়',
                'liquor_colour' => 'উজ্জ্বল লালচে বাদামি',
                'liquor_body' => 'মাঝারি, মসৃণ',
                'aroma' => 'মোলায়েম, মিষ্টি ঘ্রাণ',
                ...self::brewing('leaf'),
                'package_weight' => '৪০০ গ্রাম ফ্যামিলি প্যাক',
                'custom_characteristics' => [
                    ['label' => 'পানির তাপমাত্রা', 'value' => 'প্রায় ৯৫–১০০°C'],
                    ['label' => 'সেরা পরিবেশন', 'value' => 'সকালের দুধ চা ও বিকেলের রং চা'],
                ],
            ],
            [
                'slug' => 'family-premium-clone', 'category' => 'family-blend', 'name' => 'প্রিমিয়াম ক্লোন টি',
                ...self::pricing(1100, [1040, 990, 940]),
                'tags' => ['plain', 'milk'],
                'badge' => 'প্রিমিয়াম বাছাই',
                'short_description' => 'নির্বাচিত ক্লোন চা গাছের পাতা থেকে তৈরি প্রিমিয়াম চা — বিশেষ চা-পানের মুহূর্তের জন্য।',
                'description' => '<p>প্রিমিয়াম ক্লোন টি তৈরি হয় নির্দিষ্ট ক্লোন বা নির্বাচিত চা গাছের পাতা থেকে। বিশেষ চা-পানের অভিজ্ঞতার জন্য বাছাই করা এই প্রিমিয়াম চায়ে পাতার মান ও প্রক্রিয়াজাতকরণের যত্ন কাপে স্পষ্ট বোঝা যায়।</p>'
                    .'<h3>স্বাদ ও রং</h3><p>লিকার উজ্জ্বল সোনালি-লাল, স্বাদ মসৃণ ও পরিপূর্ণ, পেছনে দীর্ঘস্থায়ী মিষ্টি সুবাস। রং চায়ে এর চরিত্র সবচেয়ে ভালো উপভোগ করা যায়।</p>'
                    .'<h3>কার জন্য</h3><ul><li>বিশেষ অতিথি আপ্যায়ন</li><li>ছুটির দিনের ধীরেসুস্থে চা</li><li>প্রিমিয়াম চায়ের অভিজ্ঞতা খুঁজছেন যারা</li></ul>'
                    .'<h3>কীভাবে বানাবেন</h3><p>প্রতি কাপে ১০০–১২০ মিলি পানিতে ২.৫০–২.৭৫ গ্রাম চা দিন, ৯৫–১০০°C পানিতে ৩–৪ মিনিট ব্রিউ করে ছেঁকে নিন। চায়ের নিজস্ব স্বাদ পেতে চিনি কম দিন।</p>',
                'best_for' => ['বিশেষ অতিথি আপ্যায়ন', 'রং চা', 'ছুটির দিনের চা'],
                'not_best_for' => 'কড়া দুধ চায়ের জন্য নয় — সেজন্য বঙ্গ টি লিফ ভালো।',
                'business_tips' => [
                    'চায়ের নিজস্ব স্বাদ ও ঘ্রাণ উপভোগ করতে প্রথম কাপটি চিনি ছাড়া চেখে দেখুন।',
                    'কাচের কাপে পরিবেশন করুন — সোনালি-লাল রংটা টেবিলে সুন্দর দেখায়।',
                    'প্রতিবার ব্যবহারের পর প্যাকেট ভালোভাবে বন্ধ করুন, সুবাস ধরে থাকে।',
                ],
                'search_keywords' => 'ক্লোন টি, ক্লোন চা, প্রিমিয়াম ক্লোন, clone tea, clone cha, premium clone tea, প্রিমিয়াম চা, premium tea',
                'seo_title' => 'প্রিমিয়াম ক্লোন টি — বিশেষ মুহূর্তের প্রিমিয়াম চা | বঙ্গ টি',
                'seo_description' => 'নির্বাচিত ক্লোন চা গাছের পাতা থেকে তৈরি প্রিমিয়াম চা — মসৃণ স্বাদ, সোনালি-লাল লিকার ও দীর্ঘস্থায়ী সুবাস।',
                'strength' => Strength::ThreePlus,
                'tea_type' => 'ব্ল্যাক টি',
                'grade' => 'লিফ',
                'particle_size' => 'বড়',
                'liquor_colour' => 'উজ্জ্বল সোনালি-লাল',
                'liquor_body' => 'মসৃণ, পরিপূর্ণ',
                'aroma' => 'দীর্ঘস্থায়ী মিষ্টি সুবাস',
                'infusion' => '৩–৪ মিনিট',
                'cups_per_kilogram' => '১ কেজিতে প্রায় ৩৬০–৪০০ কাপ',
                'brewing_steps' => [
                    'প্রতি কাপে ১০০–১২০ মিলি পানি ও ২.৫০–২.৭৫ গ্রাম চা পাতা নিন।',
                    'পানি প্রায় ৯৫–১০০°C পর্যন্ত গরম করে চা পাতা দিন, ৩–৪ মিনিট ব্রিউ হতে দিন।',
                    'ছেঁকে গরম অবস্থায় পরিবেশন করুন; চায়ের নিজস্ব স্বাদ পেতে চিনি কম দিন।',
                ],
                'package_weight' => '২০০ গ্রাম ফ্যামিলি প্যাক',
                'custom_characteristics' => [
                    ['label' => 'পানির তাপমাত্রা', 'value' => 'প্রায় ৯৫–১০০°C'],
                    ['label' => 'সেরা পরিবেশন', 'value' => 'চিনি ছাড়া বা কম চিনির রং চা'],
                ],
            ],
            [
                'slug' => 'family-first-flush', 'category' => 'family-blend', 'name' => 'ফার্স্ট ফ্লাশ টি',
                ...self::pricing(1300, [1220, 1160, 1100]),
                'tags' => ['plain'],
                'badge' => 'নতুন',
                'short_description' => 'মৌসুমের শুরুর কোমল নতুন পাতার চা — সতেজ, সূক্ষ্ম স্বাদ ও হালকা সোনালি লিকার।',
                'description' => '<p>ফার্স্ট ফ্লাশ হলো চা উৎপাদন মৌসুমের শুরুর দিকে সংগ্রহ করা কোমল নতুন পাতা থেকে তৈরি চা। মৌসুমের প্রথম দিকের চায়ের সতেজ ও সূক্ষ্ম বৈশিষ্ট্য উপভোগের জন্য এটি একটি বিশেষ নির্বাচন।</p>'
                    .'<h3>স্বাদ ও রং</h3><p>লিকার হালকা সোনালি, স্বাদ হালকা ও সতেজ, ঘ্রাণে কচি পাতা ও ফুলের মতো সুবাস। স্বাদ ও সুবাস উৎপাদন অঞ্চল ও প্রক্রিয়াজাতকরণের ওপর নির্ভর করে মৌসুমভেদে সামান্য বদলাতে পারে।</p>'
                    .'<h3>কার জন্য</h3><ul><li>দুধ ছাড়া হালকা চা যারা পছন্দ করেন</li><li>বিকেলের শান্ত চা-পান</li><li>উপহার ও বিশেষ অতিথি</li></ul>'
                    .'<h3>কীভাবে বানাবেন</h3><p>প্রতি কাপে ১০০–১২০ মিলি পানিতে ২.৫০–২.৭৫ গ্রাম চা দিন। ফুটে ওঠার পর পানি সামান্য থিতিয়ে নিয়ে ৩–৪ মিনিট ভিজিয়ে রাখুন; দুধ ছাড়াই পরিবেশন করুন।</p>',
                'best_for' => ['দুধ ছাড়া রং চা', 'বিকেলের হালকা চা', 'উপহার'],
                'not_best_for' => 'দুধ চা বা কড়া লিকারের জন্য নয় — সেজন্য বঙ্গ টি লিফ বেছে নিন।',
                'business_tips' => [
                    'দুধ ও বেশি চিনি ছাড়া পরিবেশন করুন, তবেই এর সূক্ষ্ম স্বাদ বোঝা যায়।',
                    'নতুন প্যাকেটের চা পুরোনো চায়ের সঙ্গে মেশাবেন না; আলাদা পাত্রে রাখুন।',
                    'তীব্র গন্ধযুক্ত মসলা ও খাবার থেকে দূরে, শুকনো জায়গায় সংরক্ষণ করুন।',
                ],
                'search_keywords' => 'ফার্স্ট ফ্লাশ, ফার্স্ট ফ্লাশ চা, first flush, first flush tea, farst flash, নতুন পাতার চা, মৌসুমি চা',
                'seo_title' => 'ফার্স্ট ফ্লাশ টি — মৌসুমের প্রথম পাতার চা | বঙ্গ টি',
                'seo_description' => 'মৌসুমের শুরুর কোমল নতুন পাতা থেকে তৈরি ফার্স্ট ফ্লাশ চা — হালকা সোনালি লিকার, সতেজ ও সূক্ষ্ম স্বাদ।',
                'strength' => Strength::Three,
                'tea_type' => 'ব্ল্যাক টি (ফার্স্ট ফ্লাশ)',
                'grade' => 'হোল লিফ',
                'particle_size' => 'বড়',
                'liquor_colour' => 'হালকা সোনালি',
                'liquor_body' => 'হালকা, সূক্ষ্ম',
                'aroma' => 'কচি পাতা ও ফুলের মতো সুবাস',
                'infusion' => '৩–৪ মিনিট',
                'cups_per_kilogram' => '১ কেজিতে প্রায় ৩৬০–৪০০ কাপ',
                'brewing_steps' => [
                    'প্রতি কাপে ১০০–১২০ মিলি পানি ও ২.৫০–২.৭৫ গ্রাম চা পাতা নিন।',
                    'পানি ফুটে ওঠার পর সামান্য থিতিয়ে চা পাতা দিন, ৩–৪ মিনিট ভিজিয়ে রাখুন।',
                    'ছেঁকে দুধ ছাড়াই পরিবেশন করুন; প্রয়োজনে সামান্য চিনি বা মধু দিন।',
                ],
                'package_weight' => '২০০ গ্রাম ফ্যামিলি প্যাক',
                'custom_characteristics' => [
                    ['label' => 'সংগ্রহের সময়', 'value' => 'চা মৌসুমের শুরুর দিক'],
                    ['label' => 'সেরা পরিবেশন', 'value' => 'দুধ ছাড়া, কম চিনিতে'],
                ],
            ],
            [
                'slug' => 'family-green-tea', 'category' => 'family-blend', 'name' => 'গ্রিন টি',
                ...self::pricing(1200, [1130, 1080, 1030]),
                'tags' => ['plain'],
                'short_description' => 'কম অক্সিডেশনের সবুজ চা — হালকা, সতেজ ও উদ্ভিজ্জ স্বাদ, দুধ ছাড়া উপভোগের জন্য।',
                'description' => '<p>বঙ্গ গ্রিন টি তুলনামূলক কম অক্সিডেশনের মাধ্যমে প্রস্তুত করা হয়, তাই চা পাতার সতেজ ও উদ্ভিজ্জ বৈশিষ্ট্য বজায় থাকে। হালকা ও সতেজ চায়ের অভিজ্ঞতার জন্য এটি পরিবারের প্রিয় বিকল্প।</p>'
                    .'<h3>স্বাদ ও রং</h3><p>লিকার হালকা সবুজাভ সোনালি, স্বাদ হালকা ও সতেজ, ঘ্রাণে তাজা পাতার উদ্ভিজ্জ সুবাস।</p>'
                    .'<h3>কার জন্য</h3><ul><li>হালকা ও সতেজ স্বাদ যারা পছন্দ করেন</li><li>খাবারের পরে বা দুপুরের চা</li><li>দুধ-চিনি ছাড়া চা পান করেন যারা</li></ul>'
                    .'<h3>কীভাবে বানাবেন</h3><p>পানি প্রায় ৭০–৮৫°C পর্যন্ত গরম করুন — ফুটন্ত পানি নয়। ১৫০–২০০ মিলি পানিতে প্রায় ২ গ্রাম চা দিয়ে ২–৩ মিনিট ভিজিয়ে রাখুন। অতিরিক্ত গরম পানি বা দীর্ঘ সময়ে তিক্ততা বাড়ে।</p>',
                'best_for' => ['হালকা ও সতেজ চা', 'দুধ ছাড়া চা', 'খাবারের পরে'],
                'not_best_for' => 'দুধ চায়ের জন্য নয় — গ্রিন টি সাধারণত দুধ ছাড়া পরিবেশন করা হয়।',
                'business_tips' => [
                    'কেতলির পানি ফুটে ওঠার পর কয়েক মিনিট থিতিয়ে নিন, তবেই সঠিক তাপমাত্রা আসে।',
                    'স্বাদমতো সামান্য লেবু বা মধু মেশাতে পারেন, চিনি না দেওয়াই ভালো।',
                    'আলো ও আর্দ্রতা থেকে দূরে বায়ুরোধী পাত্রে রাখুন, সবুজ পাতার সতেজতা থাকে।',
                ],
                'search_keywords' => 'গ্রিন টি, সবুজ চা, green tea, grin tea, green cha, গ্রীন টি, bongo green tea',
                'seo_title' => 'গ্রিন টি — হালকা ও সতেজ সবুজ চা | বঙ্গ টি',
                'seo_description' => 'কম অক্সিডেশনে প্রস্তুত বঙ্গ গ্রিন টি — হালকা, সতেজ ও উদ্ভিজ্জ স্বাদ। ৭০–৮৫°C পানিতে ২–৩ মিনিটে তৈরি, দুধ ছাড়া উপভোগ করুন।',
                'strength' => Strength::ThreeMinus,
                'tea_type' => 'গ্রিন টি',
                'grade' => 'হোল লিফ',
                'particle_size' => 'বড়',
                'liquor_colour' => 'হালকা সবুজাভ সোনালি',
                'liquor_body' => 'হালকা',
                'aroma' => 'তাজা পাতার উদ্ভিজ্জ সুবাস',
                'infusion' => '২–৩ মিনিট',
                'cups_per_kilogram' => '১ কেজিতে প্রায় ৪৫০–৫০০ কাপ',
                'brewing_steps' => [
                    'পানি প্রায় ৭০–৮৫°C পর্যন্ত গরম করুন — ফুটন্ত পানি ব্যবহার করবেন না।',
                    '১৫০–২০০ মিলি পানিতে প্রায় ২ গ্রাম চা দিয়ে ২–৩ মিনিট ভিজিয়ে রাখুন।',
                    'ছেঁকে দুধ ছাড়াই গরম অবস্থায় পরিবেশন করুন।',
                ],
                'package_weight' => '২০০ গ্রাম ফ্যামিলি প্যাক',
                'custom_characteristics' => [
                    ['label' => 'পানির তাপমাত্রা', 'value' => 'প্রায় ৭০–৮৫°C'],
                    ['label' => 'সেরা পরিবেশন', 'value' => 'দুধ ছাড়া, ইচ্ছে হলে লেবু বা মধু দিয়ে'],
                ],
            ],
            [
                'slug' => 'family-yellow-tea', 'category' => 'family-blend', 'name' => 'ইয়েলো টি',
                ...self::pricing(1600, [1500, 1430, 1360]),
                'tags' => ['plain'],
                'short_description' => 'স্বতন্ত্র বৈশিষ্ট্যের বিরল ইয়েলো টি — মোলায়েম, মিষ্টি ও হালকা স্বাদের বিশেষ নির্বাচন।',
                'description' => '<p>বঙ্গ ইয়েলো টি চায়ের একটি পৃথক ধরন, যার উৎপাদন ও প্রক্রিয়াজাতকরণ পদ্ধতি গ্রিন টি থেকে আলাদা। স্বতন্ত্র বৈশিষ্ট্যের চা উপভোগ করতে চান এমন পরিবারের জন্য এটি একটি বিশেষ নির্বাচন।</p>'
                    .'<h3>স্বাদ ও রং</h3><p>লিকার উজ্জ্বল হলুদাভ সোনালি, স্বাদ মোলায়েম ও হালকা মিষ্টি; গ্রিন টি-এর চেয়ে কম ঘাসের মতো, ঘ্রাণ কোমল।</p>'
                    .'<h3>কার জন্য</h3><ul><li>নতুন ও ভিন্ন চায়ের অভিজ্ঞতা খুঁজছেন যারা</li><li>বিশেষ মুহূর্ত ও উপহার</li><li>দুধ ছাড়া হালকা চা পছন্দ করেন যারা</li></ul>'
                    .'<h3>কীভাবে বানাবেন</h3><p>পানি প্রায় ৭৫–৮৫°C পর্যন্ত গরম করুন। ১৫০–২০০ মিলি পানিতে প্রায় ২ গ্রাম চা দিয়ে ২–৩ মিনিট ভিজিয়ে রাখুন; দুধ ছাড়াই পরিবেশন করুন।</p>',
                'best_for' => ['ভিন্ন স্বাদের অভিজ্ঞতা', 'উপহার', 'দুধ ছাড়া হালকা চা'],
                'not_best_for' => 'দুধ চা বা কড়া লিকারের জন্য নয় — সেজন্য বঙ্গ টি লিফ বেছে নিন।',
                'business_tips' => [
                    'ফুটন্ত পানি নয়, সামান্য থিতানো গরম পানি ব্যবহার করুন — তিক্ততা আসে না।',
                    'একই পাতায় দ্বিতীয়বার ভিজিয়েও হালকা এক কাপ উপভোগ করা যায়।',
                    'অল্প অল্প করে ব্যবহার করুন এবং প্রতিবার প্যাকেট ভালোভাবে বন্ধ করুন।',
                ],
                'search_keywords' => 'ইয়েলো টি, হলুদ চা, yellow tea, yelo tea, yellow cha, ইয়েলো চা, বিশেষ চা',
                'seo_title' => 'ইয়েলো টি — স্বতন্ত্র স্বাদের বিশেষ চা | বঙ্গ টি',
                'seo_description' => 'স্বতন্ত্র বৈশিষ্ট্যের বঙ্গ ইয়েলো টি — হলুদাভ সোনালি লিকার, মোলায়েম ও হালকা মিষ্টি স্বাদ। ভিন্ন চায়ের অভিজ্ঞতার জন্য বিশেষ নির্বাচন।',
                'strength' => Strength::TwoPlus,
                'tea_type' => 'ইয়েলো টি',
                'grade' => 'হোল লিফ',
                'particle_size' => 'বড়',
                'liquor_colour' => 'উজ্জ্বল হলুদাভ সোনালি',
                'liquor_body' => 'হালকা, মোলায়েম',
                'aroma' => 'কোমল, হালকা মিষ্টি সুবাস',
                'infusion' => '২–৩ মিনিট',
                'cups_per_kilogram' => '১ কেজিতে প্রায় ৪৫০–৫০০ কাপ',
                'brewing_steps' => [
                    'পানি প্রায় ৭৫–৮৫°C পর্যন্ত গরম করুন — ফুটন্ত পানি ব্যবহার করবেন না।',
                    '১৫০–২০০ মিলি পানিতে প্রায় ২ গ্রাম চা দিয়ে ২–৩ মিনিট ভিজিয়ে রাখুন।',
                    'ছেঁকে দুধ ছাড়াই পরিবেশন করুন; একই পাতায় আরেকবার ভিজিয়ে নেওয়া যায়।',
                ],
                'package_weight' => '১০০ গ্রাম ফ্যামিলি প্যাক',
                'custom_characteristics' => [
                    ['label' => 'পানির তাপমাত্রা', 'value' => 'প্রায় ৭৫–৮৫°C'],
                    ['label' => 'সেরা পরিবেশন', 'value' => 'দুধ ও চিনি ছাড়া'],
                ],
            ],
        ];
    }
}
