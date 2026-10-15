<?php

use App\Enums\OrderKind;
use App\Enums\PageStatus;
use App\Models\Cart;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Pages that drew "the" bulk Cart or "the" packet basket before a Block
 * named its Cart are given the starting Cart of that kind, so a Customer's
 * Contents and the Page they were gathered on stay together; then those
 * Blocks become the one Cart Block, drawing the Cart as they did.
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
        ->and(Cart::query()->count())->toBe(2);
});

it('turns each Bulk order form and Packet basket into a Cart Block drawing the same Cart under the same wording and switches', function () {
    $migration = require database_path('migrations/2026_10_15_113144_draw_carts_with_the_cart_block.php');

    $bulk = Cart::factory()->bulk()->create();
    $retail = Cart::factory()->retail()->create();
    $pageId = pageHolding([
        ['type' => 'faq', 'data' => ['heading' => 'প্রশ্ন']],
        ['type' => 'bulk_order_form', 'data' => ['cart_id' => $bulk->getKey(), 'heading' => 'অর্ডার', 'quick_chips' => false, 'show_tier_list' => true, 'anchor' => 'order']],
        ['type' => 'packet_basket', 'data' => ['cart_id' => $retail->getKey(), 'text' => 'প্যাকেট']],
    ]);

    $migration->up();

    expect(blocksOf($pageId))->toBe([
        ['type' => 'faq', 'data' => ['heading' => 'প্রশ্ন']],
        ['type' => 'cart', 'data' => ['cart_id' => $bulk->getKey(), 'heading' => 'অর্ডার', 'quick_chips' => false, 'show_tier_list' => true, 'anchor' => 'order']],
        ['type' => 'cart', 'data' => ['cart_id' => $retail->getKey(), 'text' => 'প্যাকেট']],
    ])
        ->and(Cart::find($bulk->getKey())->isOpen())->toBeTrue()
        ->and(Cart::find($retail->getKey())->isOpen())->toBeTrue();
});

it('gives a starting Cart nobody has assigned to every Product sold its way, and leaves one already assigned to alone', function () {
    $migration = require database_path('migrations/2026_10_15_114315_assign_the_catalogue_to_the_starting_carts.php');

    $bulk = Cart::factory()->bulk()->create();
    $bulk->forceFill(['starting_kind' => OrderKind::Bulk])->save();
    $retail = Cart::factory()->retail()->create();
    $retail->forceFill(['starting_kind' => OrderKind::Retail])->save();

    $both = Product::factory()->create();
    $bulkOnly = Product::factory()->bulkOnly()->create();
    $retailOnly = Product::factory()->retailOnly()->create();
    Product::factory()->create()->delete();
    $retail->products()->attach($retailOnly->id);

    $migration->up();

    expect($bulk->products()->orderBy('products.id')->pluck('products.id')->all())->toBe([$both->id, $bulkOnly->id])
        ->and($retail->products()->pluck('products.id')->all())->toBe([$retailOnly->id]);
});
