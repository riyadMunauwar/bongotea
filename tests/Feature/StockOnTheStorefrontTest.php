<?php

use App\Enums\AdjustmentCause;
use App\Enums\OrderStatus;
use App\Enums\SystemPage;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Pack;
use App\Models\Page;
use App\Models\Product;
use App\Models\User;
use App\Support\Inventory\StockLedger;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * স্টক শেষ on the storefront (CONTEXT.md): both Carts are sent each tea's
 * Free to Sell, and the page works out from it what is স্টক শেষ and where a
 * stepper stops. Computed in the page and never stored, so Available is
 * never touched by stock.
 *
 * Assam throughout: sold both ways, in 1 kg bulk Packs and 250 g retail Packs.
 */
beforeEach(function (): void {
    Page::factory()->system(SystemPage::Home)->create();

    $this->assam = Product::factory()->bothWays()->create(['name' => 'Assam']);
    $this->quarter = Pack::factory()->retailOnly()->for($this->assam)->create(['grams' => 250]);
});

/** Somebody counted this many grams of a tea on the shelf. */
function countedOnTheShelf(Product $tea, int $grams): void
{
    app(StockLedger::class)->recordCount($tea, $grams, AdjustmentCause::OpeningCount, null, User::factory()->countingStock()->create());
}

it('sends both Carts each tea\'s Free to Sell in grams', function () {
    countedOnTheShelf($this->assam, 2500);
    $order = Order::factory()->create(['status' => OrderStatus::New]);
    OrderLine::factory()->for($order)->for($this->assam)->create(['product_name' => 'Assam', 'quantity' => 2]);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('pricing.products.0.freeGrams', 500)
        ->where('retailPricing.packs.0.freeGrams', 500));
});

it('sends nothing for a tea nobody has counted, so it is never স্টক শেষ', function () {
    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('pricing.products.0.freeGrams', null)
        ->where('retailPricing.packs.0.freeGrams', null));
});

it('leaves a tea with nothing free Available', function () {
    countedOnTheShelf($this->assam, 0);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('pricing.products.0.freeGrams', 0)
        ->where('pricing.products.0.isAvailable', true)
        ->where('retailPricing.packs.0.isOrderable', true));

    expect($this->assam->refresh()->is_available)->toBeTrue();
});

it('reads Free to Sell in the one catalogue query, never once per tea', function () {
    foreach (Product::factory()->count(3)->create() as $tea) {
        countedOnTheShelf($tea, 5000);
    }

    $reads = 0;
    DB::listen(function (QueryExecuted $query) use (&$reads): void {
        $reads += str_contains($query->sql, 'stock_movements') ? 1 : 0;
    });

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('pricing.products.1.freeGrams', 5000)
        ->where('pricing.products.3.freeGrams', 5000));

    expect($reads)->toBe(1);
});
