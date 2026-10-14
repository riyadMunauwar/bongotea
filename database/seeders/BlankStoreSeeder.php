<?php

namespace Database\Seeders;

use App\Enums\SystemPage;
use App\Models\Menu;
use App\Models\Page;
use Database\Seeders\Concerns\AssignsMenus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The Menus a blank store opens with: only the three System Pages, in the
 * header and in one footer column, since there is nothing else yet to link.
 *
 * Runs after the System Pages exist, and only on a store with no Menus of
 * its own.
 */
class BlankStoreSeeder extends Seeder
{
    use AssignsMenus;

    public function run(): void
    {
        if (Menu::query()->exists()) {
            return;
        }

        $home = Page::query()->system(SystemPage::Home)->sole()->id;
        $bulkOrder = Page::query()->system(SystemPage::BulkOrder)->sole()->id;
        $myOrders = Page::query()->system(SystemPage::MyOrders)->sole()->id;

        $links = [
            self::pageLink('হোম', $home),
            self::pageLink('পাইকারি অর্ডার', $bulkOrder),
            self::pageLink('আমার অর্ডার', $myOrders),
        ];

        DB::transaction(function () use ($links): void {
            self::assignHeader($links);
            self::assignFooter(['অর্ডার' => $links]);
        });
    }
}
