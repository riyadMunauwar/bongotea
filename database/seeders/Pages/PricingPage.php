<?php

namespace Database\Seeders\Pages;

use App\Enums\OrderKind;
use App\Models\Cart;
use App\Support\Pages\Blocks\DeliveryChart;
use App\Support\Pages\Blocks\PriceCalculator;
use App\Support\Pages\Blocks\PriceMatrix;

/**
 * What a cart costs: the calculator, every tea's Tiers and the Delivery
 * Bands, all drawn live from the catalogue.
 */
final class PricingPage extends DemoPage
{
    public static function title(): string
    {
        return 'দাম হিসাব';
    }

    public static function slug(): string
    {
        return 'pricing';
    }

    public static function blocks(): array
    {
        return [
            self::banner('পাইকারি দাম', 'বেশি নিলে দাম কম', 'প্রতিটি চায়ের নিজস্ব দাম আছে। সব চা মিলিয়ে কার্টে মোট কত কেজি আছে, সেই অনুযায়ী প্রতিটি চায়ের দাম বসবে: ১–৯ কেজিতে ট্রেড দাম, ১০–১৯ কেজিতে হোলসেল দাম, ২০ কেজি বা তার বেশিতে ডিস্ট্রিবিউটর দাম।'),
            self::block(PriceCalculator::class, [
                'cart_id' => Cart::startingFor(OrderKind::Bulk)?->getKey(),
                'heading' => 'হিসাব করে দেখুন',
                'text' => 'চা ও কেজি বেছে নিন, প্রতিটির দাম নিচে দেখুন',
            ]),
            self::block(PriceMatrix::class, [
                'heading' => 'কোন চায়ে কত দাম',
                'text' => 'কার্টের মোট কেজি অনুযায়ী প্রতিটি চায়ের দাম ও ছাড়',
            ]),
            self::block(DeliveryChart::class, [
                'heading' => 'ডেলিভারি চার্জ',
                'text' => 'কার্টের মোট কেজি যত বেশি, প্রতি কেজিতে ডেলিভারি খরচ তত কম।',
            ]),
        ];
    }
}
