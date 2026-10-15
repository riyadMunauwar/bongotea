<?php

namespace Database\Seeders;

use App\Enums\OrderKind;
use App\Enums\PageStatus;
use App\Enums\SystemPage;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Menu;
use App\Models\Page;
use Database\Seeders\Concerns\AssignsMenus;
use Database\Seeders\Pages\AboutPage;
use Database\Seeders\Pages\ContactPage;
use Database\Seeders\Pages\DemoPage;
use Database\Seeders\Pages\FaqPage;
use Database\Seeders\Pages\HowToBrewPage;
use Database\Seeders\Pages\OurGoalsPage;
use Database\Seeders\Pages\OurPromisePage;
use Database\Seeders\Pages\OurPurposePage;
use Database\Seeders\Pages\PricingPage;
use Database\Seeders\Pages\TeaGradesPage;
use Database\Seeders\Pages\TeaGuidePage;
use Database\Seeders\Pages\TeaRecipesPage;
use Database\Seeders\Pages\TeaStoragePage;
use Database\Seeders\Pages\TeaTypesPage;
use Database\Seeders\Pages\WhichTeaPage;
use Database\Seeders\Pages\WhoItsForPage;
use Database\Seeders\Pages\WhyBongoTeaPage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The demo store's content: the ordinary Pages it opens with — pricing and
 * contact, the six about Bongo Tea and the eight that teach tea — and the
 * header and footer Menus that link them, the Categories and the System
 * Pages.
 *
 * Demo content, which a Merchant would otherwise build in the panel, so it
 * is created together, once, on a store with no ordinary Pages and no Menus
 * of its own: nothing deleted or moved since is ever put back beside it. It
 * runs after the catalogue and the System Pages, which it links to.
 */
class DemoStoreSeeder extends Seeder
{
    use AssignsMenus;

    /** @var array<string, class-string<DemoPage>> by the key the Menus use */
    private const PAGES = [
        'pricing' => PricingPage::class,
        'contact' => ContactPage::class,
        'about' => AboutPage::class,
        'our_purpose' => OurPurposePage::class,
        'our_goals' => OurGoalsPage::class,
        'why' => WhyBongoTeaPage::class,
        'who' => WhoItsForPage::class,
        'promise' => OurPromisePage::class,
        'guide' => TeaGuidePage::class,
        'types' => TeaTypesPage::class,
        'grades' => TeaGradesPage::class,
        'which' => WhichTeaPage::class,
        'brew' => HowToBrewPage::class,
        'storage' => TeaStoragePage::class,
        'recipes' => TeaRecipesPage::class,
        'faq' => FaqPage::class,
    ];

    public function run(): void
    {
        /** The starting Carts' own Pages, which the store opens with like its System Pages. */
        $bulkOrder = Cart::startingFor(OrderKind::Bulk)?->page()?->getKey();
        $cartPages = array_filter([$bulkOrder, Cart::startingFor(OrderKind::Retail)?->page()?->getKey()]);

        if ($bulkOrder === null || Page::query()->whereNull('system_role')->whereKeyNot($cartPages)->exists() || Menu::query()->exists()) {
            return;
        }

        DB::transaction(function () use ($bulkOrder): void {
            $pages = [
                'home' => Page::query()->system(SystemPage::Home)->sole()->id,
                'bulk_order' => $bulkOrder,
                'my_orders' => Page::query()->system(SystemPage::MyOrders)->sole()->id,
            ];

            foreach (self::PAGES as $key => $page) {
                $pages[$key] = Page::query()->create([
                    'title' => $page::title(),
                    'slug' => $page::slug(),
                    'status' => PageStatus::Published,
                    'blocks' => $page::blocks(),
                ])->id;
            }

            self::assignHeader(self::headerMenu($pages));

            self::assignFooter(...self::footerMenus($pages));
        });
    }

    /**
     * @param  array<string, int>  $pages
     * @return list<array<string, mixed>>
     */
    private static function headerMenu(array $pages): array
    {
        $notes = [
            'tea-stall-premium' => 'চায়ের দোকানের সেরা বাছাই',
            'tea-stall-classic' => 'প্রতিদিনের বিক্রির চা',
            'family-blend' => 'পরিবারের চায়ের জন্য',
        ];

        $categories = Category::query()->whereIn('slug', array_keys($notes))->orderBy('sort_order')->get()
            ->map(fn (Category $category): array => self::categoryLink($category->name, $category->id, $notes[$category->slug]))
            ->all();

        return array_values(array_filter([
            self::pageLink('হোম', $pages['home']),
            $categories === [] ? null : self::heading('আমাদের চা', $categories),
            self::heading('চা জ্ঞানকোষ', [
                self::pageLink('চা জ্ঞানকোষ', $pages['guide'], 'চাকে জানুন, সঠিক চা বেছে নিন'),
                self::pageLink('চায়ের ধরন', $pages['types']),
                self::pageLink('চায়ের গ্রেড', $pages['grades'], 'লিফ, BOP, PD, ডাস্ট ও CD'),
                self::pageLink('কোন চা আপনার জন্য', $pages['which']),
                self::pageLink('চা তৈরির নিয়ম', $pages['brew']),
                self::pageLink('চা সংরক্ষণের নিয়ম', $pages['storage']),
                self::pageLink('চা রেসিপি', $pages['recipes']),
                self::pageLink('চা সম্পর্কিত প্রশ্ন', $pages['faq']),
            ]),
            self::pageLink('দাম হিসাব', $pages['pricing']),
            self::heading('অর্ডার', [
                self::anchorLink('অর্ডারের ধাপ', $pages['home'], 'how', '৩ ধাপে সহজ অর্ডার'),
                self::pageLink('পাইকারি অর্ডার করুন', $pages['bulk_order'], 'মোট কেজি যত বেশি, দাম তত কম'),
                self::pageLink('আমার অর্ডার ও ডেলিভারি', $pages['my_orders']),
            ]),
            self::heading('আমাদের সম্পর্কে', [
                self::pageLink('আমাদের সম্পর্কে', $pages['about']),
                self::pageLink('আমাদের উদ্দেশ্য', $pages['our_purpose']),
                self::pageLink('আমাদের লক্ষ্য', $pages['our_goals']),
                self::pageLink('কেন বঙ্গ টি', $pages['why']),
                self::pageLink('বঙ্গ টি কার জন্য', $pages['who']),
                self::pageLink('আমাদের অঙ্গীকার', $pages['promise']),
            ]),
            self::pageLink('যোগাযোগ', $pages['contact']),
        ]));
    }

    /**
     * @param  array<string, int>  $pages
     * @return array{columns: array<string, list<array<string, mixed>>>, bottom: list<array<string, mixed>>}
     */
    private static function footerMenus(array $pages): array
    {
        return [
            'columns' => [
                'অর্ডার' => [
                    self::pageLink('হোম', $pages['home']),
                    self::anchorLink('আমাদের চা', $pages['home'], 'products'),
                    self::pageLink('দাম হিসাব', $pages['pricing']),
                    self::pageLink('পাইকারি অর্ডার', $pages['bulk_order']),
                    self::pageLink('আমার অর্ডার', $pages['my_orders']),
                ],
                'চা জ্ঞানকোষ' => [
                    self::pageLink('চায়ের ধরন', $pages['types']),
                    self::pageLink('চায়ের গ্রেড', $pages['grades']),
                    self::pageLink('কোন চা আপনার জন্য', $pages['which']),
                    self::pageLink('চা তৈরির নিয়ম', $pages['brew']),
                    self::pageLink('চা সংরক্ষণের নিয়ম', $pages['storage']),
                    self::pageLink('চা রেসিপি', $pages['recipes']),
                    self::pageLink('চা সম্পর্কিত প্রশ্ন', $pages['faq']),
                ],
                'বঙ্গ টি' => [
                    self::pageLink('আমাদের সম্পর্কে', $pages['about']),
                    self::pageLink('কেন বঙ্গ টি', $pages['why']),
                    self::pageLink('বঙ্গ টি কার জন্য', $pages['who']),
                    self::pageLink('আমাদের অঙ্গীকার', $pages['promise']),
                    self::pageLink('যোগাযোগ', $pages['contact']),
                ],
            ],
            'bottom' => [
                self::pageLink('আমাদের সম্পর্কে', $pages['about']),
                self::pageLink('চা সম্পর্কিত প্রশ্ন', $pages['faq']),
                self::pageLink('যোগাযোগ', $pages['contact']),
            ],
        ];
    }
}
