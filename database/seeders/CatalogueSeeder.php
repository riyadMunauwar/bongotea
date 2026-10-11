<?php

namespace Database\Seeders;

use App\Enums\CategoryTheme;
use App\Enums\ProductStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tag;
use App\Support\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The mockup's catalogue: the Bongotea Brand, the three Categories with their
 * Themes, the four Tags, and the eighteen Products on their Tiers.
 *
 * It is sample stock for a store that has none: seeded whole, in one
 * transaction, and only while the store has no catalogue at all. So running
 * it again adds nothing twice and puts back nothing a Merchant has renamed,
 * edited or deleted, and an install that failed half-way left nothing
 * behind to stop the next run.
 *
 * The mockup priced each tea as a Regular Price less a discount at each of
 * six weights — a percentage for some teas, taka off for others. Tiers are
 * typed prices (ADR-0031), so that arithmetic is done once here, exactly as
 * the mockup rounded it, and only its results are stored. The mockup's
 * Regular Price becomes the MRP.
 */
class CatalogueSeeder extends Seeder
{
    /** The mockup's six weights, shared by every tea it sells. */
    private const RANGES = [[1, 5], [6, 10], [11, 15], [16, 20], [21, 50], [51, null]];

    private const CATEGORIES = [
        [
            'slug' => 'premium',
            'name' => 'প্রিমিয়াম',
            'note' => 'সেরা বাছাই পাতা',
            'quality_headline' => 'সেরা মানের বাছাই করা পাতা',
            'about' => 'বাগানের কচি দুটি পাতা ও একটি কুঁড়ি থেকে বাছাই করা চা। গাঢ় রং ও তীব্র ঘ্রাণ, দামি কাপ ও অতিথি আপ্যায়নের জন্য।',
            'why_points' => ['হাতে বাছাই করা কচি পাতা', 'গাঢ় রং ও দীর্ঘস্থায়ী সুগন্ধ', 'হোটেল, রেস্টুরেন্ট ও অতিথি আপ্যায়নে'],
            'theme' => CategoryTheme::Premium,
        ],
        [
            'slug' => 'gold',
            'name' => 'গোল্ড',
            'note' => 'মান ও দামের ভারসাম্য',
            'quality_headline' => 'মান ও দামের সেরা ভারসাম্য',
            'about' => 'যাদের দোকানে ভালো স্বাদ চাই কিন্তু দামও নাগালে রাখতে হয়, তাদের জন্য। প্রতিদিনের বিক্রিতে সবচেয়ে বেশি চলে।',
            'why_points' => ['দুধ চা ও রং চা দুটোতেই ভালো', 'সারা বছর একই স্বাদ', 'মাঝারি দামে বেশি কাপ'],
            'theme' => CategoryTheme::Gold,
        ],
        [
            'slug' => 'silver',
            'name' => 'সিলভার',
            'note' => 'সাশ্রয়ী দৈনিক চা',
            'quality_headline' => 'সাশ্রয়ী দৈনিক চা',
            'about' => 'ব্যস্ত চায়ের দোকান, মেস ও অফিসে কম খরচে বেশি কাপ চা বানানোর জন্য।',
            'why_points' => ['কম পাতায় কড়া লিকার', 'প্রতি কাপে সবচেয়ে কম খরচ', 'বেশি দুধেও স্বাদ ঠিক থাকে'],
            'theme' => CategoryTheme::Silver,
        ],
    ];

    /** By slug, in the order the store filters by them. */
    private const TAGS = [
        'milk' => 'দুধ চায়ের জন্য',
        'plain' => 'রং চায়ের জন্য',
        'strong' => 'কড়া লিকার',
        'hotel' => 'রেস্টুরেন্ট ও হোটেল',
    ];

    /** What the mockup says of the twelve teas it describes in less detail. */
    private const PLAIN_DETAILS = [
        'not_best_for' => 'অন্য স্বাদ চাইলে একই সেগমেন্টের অন্য চা দেখুন।',
        'brewing_guide' => 'প্রতি কাপে ২ গ্রাম, ৩ মিনিট ফুটিয়ে নিন।',
        'cups_per_kilogram' => '১ কেজিতে প্রায় ৪৫০ কাপ',
        'business_tips' => [
            'এয়ারটাইট কৌটায় রাখুন, ঘ্রাণ ভালো থাকে।',
            'বেশিক্ষণ ফুটালে তেতো হতে পারে, ৩–৪ মিনিটে নামিয়ে নিন।',
            'মাসের শুরুতে একসাথে বেশি নিলে কেজিপ্রতি দাম কমে।',
        ],
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
     * the mockup's.
     */
    private static function hasCatalogue(): bool
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
            $created = Product::query()->create([
                ...array_diff_key($product, array_flip(['category', 'regular', 'off', 'steps', 'tags'])),
                ...(isset($product['not_best_for']) ? [] : self::PLAIN_DETAILS),
                'category_id' => $categories[$product['category']]->id,
                'brand_id' => $brand->id,
                'mrp' => Money::toMinorUnits($product['regular']),
                'tiers' => self::tiers($product['regular'], $product['off'], $product['steps']),
                'status' => ProductStatus::Published,
                'is_available' => true,
            ]);

            $created->tags()->attach(array_map(fn (string $slug): int => $tags[$slug]->id, $product['tags']));
        }
    }

    /**
     * A tea's Tiers as the mockup worked them out: at each weight, the
     * Regular Price less that step's percentage or taka, rounded to whole
     * taka, then stored in minor units.
     *
     * @param  'percent'|'taka'  $off
     * @param  list<int>  $steps
     * @return list<array{min: int, max: int|null, price: int}>
     */
    private static function tiers(int $regular, string $off, array $steps): array
    {
        return array_map(fn (array $range, int $step): array => [
            'min' => $range[0],
            'max' => $range[1],
            'price' => Money::toMinorUnits((int) round($off === 'percent' ? $regular * (1 - $step / 100) : $regular - $step)),
        ], self::RANGES, $steps);
    }

    /**
     * The eighteen teas, Category by Category in the order the store shows
     * them, which is the order they are created in.
     *
     * @return list<array<string, mixed>>
     */
    private static function products(): array
    {
        return [
            [
                'slug' => 'premium', 'category' => 'premium', 'name' => 'বঙ্গোটি প্রিমিয়াম',
                'short_description' => 'দুধ চায়ের জন্য ঘন ও সুগন্ধি চা।',
                'regular' => 620, 'off' => 'percent', 'steps' => [0, 5, 10, 13, 16, 20],
                'badge' => 'সবচেয়ে জনপ্রিয়', 'tags' => ['milk'],
                'strength' => 4, 'liquor_colour' => 'গাঢ় লালচে বাদামি', 'aroma' => 'তীব্র সুগন্ধ',
                'best_for' => ['দুধ চা', 'মালাই চা', 'অফিস ও হোটেল'],
                'not_best_for' => 'হালকা রং চা পছন্দ করলে গোল্ড বেছে নিন।',
                'brewing_guide' => 'প্রতি কাপে ২–২.৫ গ্রাম, ৩–৪ মিনিট ফুটিয়ে নিন।',
                'cups_per_kilogram' => '১ কেজিতে প্রায় ৪০০–৪৫০ কাপ',
                'business_tips' => [
                    'দুধ চায়ে কনডেন্সড মিল্ক দিলে এই চায়ের রং সবচেয়ে ভালো আসে।',
                    'সকালের ভিড়ের আগে এক কেতলি লিকার বানিয়ে রাখুন, বারবার পাতা দিতে হবে না।',
                    'এয়ারটাইট কৌটায় রাখুন, খোলা রাখলে ঘ্রাণ কমে যায়।',
                ],
            ],
            [
                'slug' => 'special', 'category' => 'premium', 'name' => 'বঙ্গোটি স্পেশাল',
                'short_description' => 'রেস্টুরেন্ট ও অতিথি আপ্যায়নের জন্য বাছাই করা পাতা।',
                'regular' => 650, 'off' => 'percent', 'steps' => [0, 5, 8, 12, 15, 18],
                'badge' => 'রেস্টুরেন্টের জন্য', 'tags' => ['plain', 'hotel'],
                'strength' => 4, 'liquor_colour' => 'উজ্জ্বল লালচে সোনালি', 'aroma' => 'ফুলের মতো হালকা সুবাস',
                'best_for' => ['রেস্টুরেন্ট', 'হোটেল', 'অতিথি আপ্যায়ন'],
                'not_best_for' => 'খুব কম দামের কাপ চায়ে খরচ বেশি পড়বে।',
                'brewing_guide' => 'প্রতি কাপে ২.৫ গ্রাম, ৩–৪ মিনিট ভিজিয়ে রাখুন।',
                'cups_per_kilogram' => '১ কেজিতে প্রায় ৪০০ কাপ',
                'business_tips' => [
                    'মেনুতে "স্পেশাল চা" নামে বেশি দামে বিক্রি করতে পারেন।',
                    'কাচের কাপে পরিবেশন করলে রংটা ক্রেতার চোখে পড়ে।',
                    'খাবারের পরে চা অফার করলে বিক্রি বাড়ে।',
                ],
            ],
            [
                'slug' => 'royal', 'category' => 'premium', 'name' => 'বঙ্গোটি রয়েল',
                'short_description' => 'বড় পাতার সুগন্ধি চা, অতিথি আপ্যায়নে রাজকীয় স্বাদ।',
                'regular' => 680, 'off' => 'percent', 'steps' => [0, 5, 9, 12, 15, 18],
                'tags' => ['plain', 'hotel'],
                'strength' => 4, 'liquor_colour' => 'উজ্জ্বল লাল', 'aroma' => 'মিষ্টি ফুলেল সুবাস',
                'best_for' => ['অতিথি আপ্যায়ন', 'হোটেল', 'রং চা'],
            ],
            [
                'slug' => 'orthodox', 'category' => 'premium', 'name' => 'বঙ্গোটি অর্থোডক্স লিফ',
                'short_description' => 'আস্ত পাতার চা, হালকা সোনালি লিকার ও গভীর সুবাস।',
                'regular' => 740, 'off' => 'taka', 'steps' => [0, 30, 60, 90, 110, 130],
                'tags' => ['plain', 'hotel'],
                'strength' => 3, 'liquor_colour' => 'হালকা সোনালি', 'aroma' => 'গভীর, প্রাকৃতিক সুবাস',
                'best_for' => ['রং চা', 'লেবু চা', 'প্রিমিয়াম ক্যাফে'],
            ],
            [
                'slug' => 'garden', 'category' => 'premium', 'name' => 'বঙ্গোটি সিলেট বাগান',
                'short_description' => 'এক বাগানের বাছাই করা পাতা, প্রতিবার একই স্বাদ।',
                'regular' => 700, 'off' => 'percent', 'steps' => [0, 4, 8, 11, 14, 17],
                'tags' => ['milk', 'plain'],
                'strength' => 4, 'liquor_colour' => 'লালচে সোনালি', 'aroma' => 'তাজা পাতার ঘ্রাণ',
                'best_for' => ['দুধ চা', 'রং চা', 'রেস্টুরেন্ট'],
            ],
            [
                'slug' => 'masala', 'category' => 'premium', 'name' => 'বঙ্গোটি মশলা চা',
                'short_description' => 'এলাচ, আদা ও দারুচিনি মেশানো তৈরি মশলা চা।',
                'regular' => 690, 'off' => 'taka', 'steps' => [0, 25, 50, 75, 95, 115],
                'tags' => ['milk', 'hotel'],
                'strength' => 4, 'liquor_colour' => 'ঘন বাদামি', 'aroma' => 'মশলার উষ্ণ ঘ্রাণ',
                'best_for' => ['মশলা চা', 'শীতের মৌসুম', 'ক্যাফে'],
            ],
            [
                'slug' => 'gold', 'category' => 'gold', 'name' => 'বঙ্গোটি গোল্ড',
                'short_description' => 'সোনালি লিকার, মোলায়েম স্বাদ। রং চা ও দুধ চা দুটোতেই ভালো।',
                'regular' => 550, 'off' => 'taka', 'steps' => [0, 30, 60, 80, 100, 120],
                'tags' => ['plain', 'milk'],
                'strength' => 3, 'liquor_colour' => 'সোনালি উজ্জ্বল', 'aroma' => 'মোলায়েম, মিষ্টি ঘ্রাণ',
                'best_for' => ['রং চা', 'লেবু চা', 'দুধ চা'],
                'not_best_for' => 'খুব কড়া লিকার চাইলে স্ট্রং ভালো।',
                'brewing_guide' => 'প্রতি কাপে ২ গ্রাম, ২–৩ মিনিট ভিজিয়ে রাখুন।',
                'cups_per_kilogram' => '১ কেজিতে প্রায় ৪৫০–৫০০ কাপ',
                'business_tips' => [
                    'লেবু চা ও আদা চায়ের জন্য আলাদা কেতলিতে এই চা রাখুন।',
                    'রং চায়ের দাম দুধ চায়ের চেয়ে কম রেখে বেশি বিক্রি করা যায়।',
                    'বিকেলের ক্রেতারা হালকা চা বেশি পছন্দ করেন।',
                ],
            ],
            [
                'slug' => 'blend', 'category' => 'gold', 'name' => 'বঙ্গোটি ব্লেন্ড',
                'short_description' => 'কয়েক বাগানের পাতা মিশিয়ে ভারসাম্যপূর্ণ স্বাদ।',
                'regular' => 530, 'off' => 'taka', 'steps' => [0, 25, 50, 75, 95, 115],
                'tags' => ['milk', 'plain', 'hotel'],
                'strength' => 3, 'liquor_colour' => 'ভারসাম্যপূর্ণ লাল', 'aroma' => 'মিশ্র সুগন্ধ',
                'best_for' => ['দুধ চা ও রং চা দুটোই', 'ক্যাটারিং', 'অফিস'],
                'not_best_for' => 'একদম কড়া লিকার চাইলে স্ট্রং বেছে নিন।',
                'brewing_guide' => 'প্রতি কাপে ২ গ্রাম, ৩ মিনিট ফুটান।',
                'cups_per_kilogram' => '১ কেজিতে প্রায় ৪৫০ কাপ',
                'business_tips' => [
                    'এক চায়েই দুধ চা ও রং চা চালাতে চাইলে এটি সুবিধাজনক।',
                    'অনুষ্ঠান ও ক্যাটারিংয়ে সবার রুচির সাথে মেলে।',
                    'স্বাদ সারা বছর একই রকম থাকে।',
                ],
            ],
            [
                'slug' => 'classic', 'category' => 'gold', 'name' => 'বঙ্গোটি ক্লাসিক',
                'short_description' => 'চেনা স্বাদের দুধ চা, সব ধরনের দোকানে চলে।',
                'regular' => 520, 'off' => 'percent', 'steps' => [0, 5, 9, 12, 15, 18],
                'tags' => ['milk'],
                'strength' => 3, 'liquor_colour' => 'মাঝারি লাল', 'aroma' => 'চেনা চায়ের ঘ্রাণ',
                'best_for' => ['দুধ চা', 'চায়ের দোকান', 'অফিস'],
            ],
            [
                'slug' => 'dust', 'category' => 'gold', 'name' => 'বঙ্গোটি ফাইন ডাস্ট',
                'short_description' => 'মিহি দানার চা, দ্রুত লিকার আসে।',
                'regular' => 500, 'off' => 'taka', 'steps' => [0, 25, 50, 70, 85, 100],
                'tags' => ['strong', 'milk'],
                'strength' => 4, 'liquor_colour' => 'গাঢ় লাল', 'aroma' => 'কড়া ঘ্রাণ',
                'best_for' => ['দ্রুত লিকার', 'ব্যস্ত দোকান', 'দুধ চা'],
            ],
            [
                'slug' => 'milkmix', 'category' => 'gold', 'name' => 'বঙ্গোটি মিল্ক স্পেশাল',
                'short_description' => 'দুধ ও কনডেন্সড মিল্কে ঘন রং দেয়।',
                'regular' => 540, 'off' => 'percent', 'steps' => [0, 4, 8, 12, 14, 17],
                'tags' => ['milk'],
                'strength' => 4, 'liquor_colour' => 'ঘন লালচে বাদামি', 'aroma' => 'মোলায়েম ঘ্রাণ',
                'best_for' => ['দুধ চা', 'মালাই চা', 'কনডেন্সড মিল্ক চা'],
            ],
            [
                'slug' => 'morning', 'category' => 'gold', 'name' => 'বঙ্গোটি মর্নিং',
                'short_description' => 'সকালের প্রথম কাপের জন্য সতেজ ও হালকা কড়া।',
                'regular' => 510, 'off' => 'taka', 'steps' => [0, 20, 45, 65, 80, 95],
                'tags' => ['plain', 'milk'],
                'strength' => 3, 'liquor_colour' => 'উজ্জ্বল লাল', 'aroma' => 'সতেজ ঘ্রাণ',
                'best_for' => ['সকালের চা', 'অফিস', 'রং চা'],
            ],
            [
                'slug' => 'strong', 'category' => 'silver', 'name' => 'বঙ্গোটি স্ট্রং',
                'short_description' => 'কড়া লিকার, দোকানের ব্যস্ত সময়ের জন্য।',
                'regular' => 460, 'off' => 'taka', 'steps' => [0, 20, 40, 60, 75, 90],
                'badge' => 'দোকানের পছন্দ', 'tags' => ['strong', 'milk'],
                'strength' => 5, 'liquor_colour' => 'ঘন কালচে লাল', 'aroma' => 'কড়া, ঝাঁঝালো',
                'best_for' => ['ব্যস্ত চায়ের দোকান', 'দুধ চা', 'কারখানা ও মেস'],
                'not_best_for' => 'অতিথি আপ্যায়নে স্পেশাল বেশি মানায়।',
                'brewing_guide' => 'প্রতি কাপে ১.৫–২ গ্রাম যথেষ্ট, ৩ মিনিট ফুটান।',
                'cups_per_kilogram' => '১ কেজিতে প্রায় ৫০০–৫৫০ কাপ',
                'business_tips' => [
                    'কম পাতায় বেশি লিকার হয়, তাই প্রতি কাপে খরচ কমে।',
                    'অনেক দুধ মেশালেও স্বাদ হারায় না।',
                    'লিকার বেশিক্ষণ ফুটালে তেতো হতে পারে, ৩–৪ মিনিটে নামিয়ে নিন।',
                ],
            ],
            [
                'slug' => 'regular', 'category' => 'silver', 'name' => 'বঙ্গোটি রেগুলার',
                'short_description' => 'প্রতিদিনের জন্য ভালো মানের সাশ্রয়ী চা।',
                'regular' => 440, 'off' => 'percent', 'steps' => [0, 4, 8, 12, 15, 18],
                'tags' => ['milk', 'plain'],
                'strength' => 3, 'liquor_colour' => 'মাঝারি লাল', 'aroma' => 'স্বাভাবিক চায়ের ঘ্রাণ',
                'best_for' => ['প্রতিদিনের চা', 'ছোট দোকান', 'অফিস'],
                'not_best_for' => 'প্রিমিয়াম ক্রেতাদের জন্য প্রিমিয়াম বা স্পেশাল নিন।',
                'brewing_guide' => 'প্রতি কাপে ২ গ্রাম, ৩ মিনিট ফুটান।',
                'cups_per_kilogram' => '১ কেজিতে প্রায় ৪৫০ কাপ',
                'business_tips' => [
                    'কম দামের কাপ চায়ের জন্য সবচেয়ে লাভজনক।',
                    'স্ট্রং-এর সাথে মিশিয়ে নিজের দোকানের আলাদা স্বাদ বানাতে পারেন।',
                    'মাসের শুরুতে একবারে বেশি নিলে কেজিপ্রতি দাম কমে।',
                ],
            ],
            [
                'slug' => 'daily', 'category' => 'silver', 'name' => 'বঙ্গোটি ডেইলি',
                'short_description' => 'প্রতিদিনের সাশ্রয়ী চা, বেশি কাপে কম খরচ।',
                'regular' => 420, 'off' => 'percent', 'steps' => [0, 4, 8, 11, 14, 16],
                'tags' => ['milk', 'plain'],
                'strength' => 3, 'liquor_colour' => 'মাঝারি লাল', 'aroma' => 'স্বাভাবিক ঘ্রাণ',
                'best_for' => ['প্রতিদিনের চা', 'ছোট দোকান', 'বাসা'],
            ],
            [
                'slug' => 'saver', 'category' => 'silver', 'name' => 'বঙ্গোটি সেভার',
                'short_description' => 'সবচেয়ে কম দামের চা, মেস ও হোস্টেলের জন্য।',
                'regular' => 390, 'off' => 'taka', 'steps' => [0, 15, 30, 45, 55, 65],
                'tags' => ['milk'],
                'strength' => 3, 'liquor_colour' => 'হালকা লাল', 'aroma' => 'হালকা ঘ্রাণ',
                'best_for' => ['মেস ও হোস্টেল', 'বড় পরিমাণ', 'দুধ চা'],
            ],
            [
                'slug' => 'canteen', 'category' => 'silver', 'name' => 'বঙ্গোটি ক্যান্টিন',
                'short_description' => 'অফিস ও কারখানার ক্যান্টিনে বড় কেতলির জন্য।',
                'regular' => 410, 'off' => 'percent', 'steps' => [0, 5, 8, 11, 13, 15],
                'tags' => ['strong', 'milk'],
                'strength' => 4, 'liquor_colour' => 'গাঢ় লাল', 'aroma' => 'কড়া ঘ্রাণ',
                'best_for' => ['ক্যান্টিন', 'কারখানা', 'বড় কেতলি'],
            ],
            [
                'slug' => 'tong', 'category' => 'silver', 'name' => 'বঙ্গোটি টং স্পেশাল',
                'short_description' => 'টং দোকানের কড়া দুধ চায়ের আসল স্বাদ।',
                'regular' => 430, 'off' => 'taka', 'steps' => [0, 20, 35, 50, 60, 75],
                'tags' => ['strong', 'milk'],
                'strength' => 5, 'liquor_colour' => 'ঘন কালচে লাল', 'aroma' => 'ঝাঁঝালো কড়া',
                'best_for' => ['টং দোকান', 'কড়া দুধ চা', 'রাস্তার চা'],
            ],
        ];
    }
}
