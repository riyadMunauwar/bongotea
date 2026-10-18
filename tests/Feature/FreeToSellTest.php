<?php

use App\Enums\AdjustmentCause;
use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Pages\ProductStockHistory;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Pack;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Inventory\CommittedStock;
use App\Support\Inventory\StockLedger;
use App\Support\Orders\OrderCapture;
use App\Support\Orders\QuoteRefused;
use App\Support\Orders\RetailQuoteRefused;
use App\Support\Pricing\CartLine;
use App\Support\Pricing\PriceBook;
use App\Support\Pricing\ProductPrices;
use Livewire\Livewire;

/**
 * Free to Sell: On Hand less the weight on every Order that is neither
 * Cancelled nor sent, New as well as Confirmed (ADR-0037), and the gate
 * Capture holds an Order to.
 *
 * Counted off the Orders and never written as a movement, so it is read
 * here through the Inventory module and the Checkout, never through rows.
 */
beforeEach(function (): void {
    $this->ledger = app(StockLedger::class);
    $this->stock = app(CommittedStock::class);
    $this->counter = User::factory()->countingStock()->managingCatalogue()->create();

    retailDeliveryEverywhere(6000, freeFrom: 100000);
});

/** Somebody counted this many grams of the Product on the shelf. */
function countShelf(Product $product, int $grams): void
{
    app(StockLedger::class)->recordCount($product, $grams, AdjustmentCause::OpeningCount, null, test()->counter);
}

/** An Order for this many kilograms of one Product, in the given state. */
function kilogramsOrdered(Product $product, int $kilograms, OrderStatus $status): Order
{
    $order = Order::factory()->create(['status' => $status]);
    OrderLine::factory()->for($order)->for($product)->create(['product_name' => $product->name, 'quantity' => $kilograms]);

    return $order->refresh();
}

/**
 * The bulk Checkout posted for one Product, quoted at the store's own price,
 * so only stock can refuse it.
 *
 * @return array{name: string, phone: string, district: string, address: string, lines: list<array{productId: int, kilograms: int}>, quote: array{lines: list<array{productId: int, tierPrice: int}>, deliveryCharge: int, total: int}}
 */
function bulkCheckoutFor(Product $product, int $kilograms): array
{
    $priced = PriceBook::current()->price([
        new CartLine(ProductPrices::of($product), $kilograms),
    ], 'Dhaka');

    return [
        'name' => 'Rahim Uddin',
        'phone' => '01712345678',
        'district' => 'Dhaka',
        'address' => 'House 4, Road 12, Banani',
        'cart' => openCart()->getKey(),
        'lines' => [['productId' => $product->getKey(), 'kilograms' => $kilograms]],
        'quote' => [
            'lines' => [['productId' => $product->getKey(), 'tierPrice' => $priced->lines[0]->tierPrice]],
            'deliveryCharge' => $priced->deliveryCharge,
            'total' => $priced->total,
        ],
    ];
}

/**
 * The packet basket posted for one Pack, at 270.00 each against a 60.00 retail Delivery Charge.
 *
 * @return array{name: string, phone: string, district: string, address: string, cart: string, packs: list<array{packId: int, productId: int, count: int}>, quote: array{lines: list<array{packId: int, packPrice: int}>, deliveryCharge: int, total: int}}
 */
function retailCheckoutFor(Pack $pack, int $count): array
{
    $subtotal = 27000 * $count;
    $delivery = $subtotal >= 100000 ? 0 : 6000;

    return [
        'name' => 'Rahim Uddin',
        'phone' => '01712345678',
        'district' => 'Dhaka',
        'address' => 'House 4, Road 12, Banani',
        'cart' => openCart(OrderKind::Retail)->getKey(),
        'packs' => [['packId' => $pack->getKey(), 'productId' => $pack->product_id, 'count' => $count]],
        'quote' => [
            'lines' => [['packId' => $pack->getKey(), 'packPrice' => 27000]],
            'deliveryCharge' => $delivery,
            'total' => $subtotal + $delivery,
        ],
    ];
}

/** A 250 g Pack at 270.00, ticked both ways on a Product sold both ways. */
function quarterPack(): Pack
{
    return Pack::factory()
        ->for(Product::factory()->bothWays()->create())
        ->bothWays()
        ->create(['grams' => 250, 'mrp' => 30000, 'price' => 27000]);
}

/*
|--------------------------------------------------------------------------
| The figure
|--------------------------------------------------------------------------
*/

it('is On Hand less every New and Confirmed Order nobody has sent', function () {
    $tea = Product::factory()->create();
    countShelf($tea, 30000);

    kilogramsOrdered($tea, 4, OrderStatus::Confirmed);
    kilogramsOrdered($tea, 5, OrderStatus::New);

    /** Neither holds anything: one is not happening, and the other is already in a van, its 7 kg off On Hand. */
    kilogramsOrdered($tea, 9, OrderStatus::Cancelled);
    Shipment::factory()->for(kilogramsOrdered($tea, 7, OrderStatus::Confirmed))->create();

    $listed = $this->stock->withFreeToSell(Product::query()->whereKey($tea))->sole();

    expect($this->ledger->onHand($tea))->toBe(23000)
        ->and($this->stock->freeToSell($tea))->toBe(14000)
        ->and((int) $listed->{CommittedStock::FREE_TO_SELL})->toBe(14000);
});

it('is unknown for a Product nobody has counted, however much is ordered', function () {
    $tea = Product::factory()->create();
    kilogramsOrdered($tea, 4, OrderStatus::New);

    $listed = $this->stock->withFreeToSell(Product::query()->whereKey($tea))->sole();

    expect($this->stock->freeToSell($tea))->toBeNull()
        ->and($listed->{CommittedStock::FREE_TO_SELL})->toBeNull();
});

it('draws bulk kilograms and retail Packs from the same grams', function () {
    $pack = quarterPack();
    countShelf($pack->product, 5000);

    kilogramsOrdered($pack->product, 2, OrderStatus::New);
    OrderLine::factory()->for(Order::factory()->retail()->create())->ofPack($pack, 4)->create();

    expect($this->stock->freeToSell($pack->product))->toBe(2000);
});

it('frees a New Order\'s weight the moment it is cancelled, writing no movement', function () {
    $tea = Product::factory()->create();
    countShelf($tea, 10000);
    $order = kilogramsOrdered($tea, 6, OrderStatus::New);
    $movements = StockMovement::query()->count();

    expect($this->stock->freeToSell($tea))->toBe(4000);

    $order->update(['status' => OrderStatus::Cancelled]);

    expect($this->stock->freeToSell($tea))->toBe(10000)
        ->and(StockMovement::query()->count())->toBe($movements);
});

/*
|--------------------------------------------------------------------------
| Capture
|--------------------------------------------------------------------------
*/

it('refuses a bulk Checkout above Free to Sell, naming the Product, and writes nothing', function () {
    $tea = Product::factory()->create(['price' => 50000]);
    countShelf($tea, 5000);
    kilogramsOrdered($tea, 2, OrderStatus::New);

    $this->postJson(route('checkout.store'), bulkCheckoutFor($tea, 4))
        ->assertConflict()
        ->assertJsonPath('short', [$tea->getKey()])
        ->assertJsonPath('unavailable', [])
        ->assertJsonPath('current.totalKilograms', 4);

    expect(Order::query()->count())->toBe(1);

    $this->postJson(route('checkout.store'), bulkCheckoutFor($tea, 3))->assertCreated();
});

it('refuses a retail Checkout above Free to Sell, naming the Pack, and writes nothing', function () {
    $pack = quarterPack();
    countShelf($pack->product, 1000);

    $this->postJson(route('checkout.store'), retailCheckoutFor($pack, 5))
        ->assertConflict()
        ->assertJsonPath('short', [$pack->getKey()])
        ->assertJsonPath('unavailable', []);

    $this->postJson(route('checkout.store'), retailCheckoutFor($pack, 4))->assertCreated();

    expect(Order::query()->where('kind', OrderKind::Retail)->count())->toBe(1);
});

it('never refuses a Product nobody has counted for stock', function () {
    $tea = Product::factory()->create(['price' => 50000]);
    kilogramsOrdered($tea, 500, OrderStatus::Confirmed);

    $this->postJson(route('checkout.store'), bulkCheckoutFor($tea, 40))->assertCreated();
});

/**
 * Run one after the other, because the test database is SQLite, where
 * `lockForUpdate()` compiles to nothing. What this proves is that the check
 * reads the weight of every Order already committed; the row lock taken first
 * is what makes a second Capture on PostgreSQL wait to read the first's.
 */
it('refuses the second of two Captures for the last grams, bulk or retail', function () {
    $pack = quarterPack();
    countShelf($pack->product, 1000);

    $first = orderAsCaptured(lines: [['product' => $pack->product, 'kilograms' => 1]]);

    expect(fn () => orderAsCaptured(lines: [['product' => $pack->product, 'kilograms' => 1]]))
        ->toThrow(QuoteRefused::class, 'free to sell');

    expect(fn () => app(OrderCapture::class)->captureRetail(
        [['packId' => $pack->getKey(), 'productId' => $pack->product_id, 'pack' => $pack, 'count' => 1]],
        ['name' => 'Rahim', 'phone' => '01712345679', 'address' => 'Banani', 'district' => 'Dhaka', 'payment_method' => PaymentMethod::CashOnDelivery],
    ))->toThrow(RetailQuoteRefused::class, 'free to sell');

    expect(Order::query()->sole()->is($first))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The stock screens
|--------------------------------------------------------------------------
*/

it('draws Free to Sell beside On Hand and Committed', function () {
    $this->actingAs($this->counter);

    $counted = Product::factory()->create();
    $uncounted = Product::factory()->create();
    countShelf($counted, 30000);
    kilogramsOrdered($counted, 4, OrderStatus::Confirmed);
    kilogramsOrdered($counted, 5, OrderStatus::New);

    Livewire::test(ListProducts::class)
        ->assertTableColumnStateSet(StockLedger::ON_HAND, 30000, $counted)
        ->assertTableColumnStateSet(CommittedStock::COMMITTED, 4000, $counted)
        ->assertTableColumnStateSet(CommittedStock::FREE_TO_SELL, 21000, $counted)
        ->assertTableColumnStateSet(CommittedStock::FREE_TO_SELL, null, $uncounted);

    Livewire::test(ProductStockHistory::class, ['record' => $counted->getKey()])
        ->assertSee('On hand: 30 · Free to sell: 21 ·');
});
