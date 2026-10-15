<?php

use App\Enums\OrderKind;
use App\Enums\PageStatus;
use App\Models\Cart;
use Illuminate\Support\Facades\DB;

/**
 * Pages that drew "the" bulk Cart or "the" packet basket before a Block
 * named its Cart are given the starting Cart of that kind, so a Customer's
 * Contents and the Page they were gathered on stay together.
 */
function pageHolding(array $blocks): int
{
    return DB::table('pages')->insertGetId([
        'title' => 'Old', 'slug' => 'old-'.fake()->unique()->numberBetween(1, 99999), 'status' => PageStatus::Published->value,
        'blocks' => json_encode($blocks), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function blocksOf(int $pageId): array
{
    return json_decode(DB::table('pages')->where('id', $pageId)->value('blocks'), true);
}

it('places the starting Cart of each kind on the first Page drawing that kind, making it where there is none', function () {
    $migration = require database_path('migrations/2026_10_15_110506_place_starting_carts_on_their_pages.php');

    $retail = Cart::factory()->retail()->create();
    $retail->forceFill(['starting_kind' => OrderKind::Retail])->save();

    $first = pageHolding([['type' => 'faq', 'data' => []], ['type' => 'bulk_order_form', 'data' => ['heading' => 'অর্ডার']]]);
    $second = pageHolding([['type' => 'bulk_order_form', 'data' => []], ['type' => 'packet_basket', 'data' => []]]);

    $migration->up();

    $bulk = Cart::startingFor(OrderKind::Bulk);

    expect($bulk->name)->toBe('পাইকারি অর্ডার করুন')
        ->and(blocksOf($first)[1]['data'])->toBe(['heading' => 'অর্ডার', 'cart_id' => $bulk->getKey()])
        ->and(blocksOf($second)[0]['data'])->toBe([])
        ->and(blocksOf($second)[1]['data'])->toBe(['cart_id' => $retail->getKey()])
        ->and(Cart::query()->count())->toBe(2)
        ->and($bulk->isOpen())->toBeTrue();
});
