<?php

use App\Enums\SystemPage;
use App\Enums\TrackingMoment;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Pack;
use App\Models\Page;
use App\Models\Product;
use App\Models\TrackingEvent;
use Tests\Fixtures\Tracking\ProviderScript;

/**
 * Retail reaches the ad platforms through the same Moments as bulk (ADR-0016,
 * ADR-0034). A Pack is reported as its Product — the Product's id, the Pack
 * count as the quantity, the Pack Price as the item price, the Pack's weight
 * as the variant — and every Moment says which Cart it came from.
 *
 * Throughout: Gold in a 250 g Pack at 270.00.
 */
beforeEach(function () {
    $this->gold = Product::factory()->create(['name' => 'Gold']);
    $this->quarter = Pack::factory()->for($this->gold)->create(['grams' => 250, 'mrp' => 30000, 'price' => 27000]);
});

function sentAfterSweep(ProviderScript $script): array
{
    $script->fakeAccepted();
    test()->artisan('tracking:sweep')->assertSuccessful();

    return $script->sentEvents()[0];
}

it('reports a Pack added to the packet basket as its Product, from the retail Cart', function (ProviderScript $script) {
    $script->switchOn();

    reportToBeacon(TrackingMoment::AddedToCart, 'basket-1-pack-'.$this->quarter->id, ['cart' => 'retail', 'items' => $this->quarter->id.':3'])->assertNoContent();

    $sent = sentAfterSweep($script);

    expect($script->eventNameOf($sent))->toBe($script->addedToCartEvents()[0])
        ->and($script->valueOf($sent))->toBe(810.0)
        ->and($script->contentsOf($sent))->toBe([['id' => (string) $this->gold->getKey(), 'quantity' => 3, 'price' => 270.0]])
        ->and($script->variantsOf($sent))->toBe(['250 g'])
        ->and($script->cartOf($sent))->toBe('retail');
})->with('tracking providers');

it('reports a retail Checkout Started at its Pack Prices and no Delivery Charge', function (ProviderScript $script) {
    $script->switchOn();

    reportToBeacon(TrackingMoment::CheckoutStarted, 'checkout-9', ['cart' => 'retail', 'items' => $this->quarter->id.':2'])->assertNoContent();

    $sent = sentAfterSweep($script);

    expect($script->eventNameOf($sent))->toBe($script->checkoutStartedEvents()[0])
        ->and($script->valueOf($sent))->toBe(540.0)
        ->and($script->cartOf($sent))->toBe('retail');
})->with('tracking providers');

it('reports nothing for a Pack of a Product the store does not sell', function (ProviderScript $script) {
    $script->switchOn();
    $draft = Pack::factory()->for(Product::factory()->draft())->create();

    reportToBeacon(TrackingMoment::AddedToCart, 'basket-2', ['cart' => 'retail', 'items' => $draft->id.':1'])->assertNoContent();

    expect(TrackingEvent::query()->sole()->selection->lines)->toBe([]);
})->with('tracking providers');

it('reports nothing for a Product not sold the way its Cart names', function (ProviderScript $script) {
    $script->switchOn();
    $retailOnly = Product::factory()->retailOnly()->create();
    $bulkPack = Pack::factory()->bulkOnly()->for($this->gold)->create(['grams' => 1000]);

    reportToBeacon(TrackingMoment::AddedToCart, 'cart-3', ['items' => $retailOnly->id.':1'])->assertNoContent();
    reportToBeacon(TrackingMoment::AddedToCart, 'basket-3', ['cart' => 'retail', 'items' => $bulkPack->id.':1'])->assertNoContent();

    expect(TrackingEvent::query()->get()->map(fn (TrackingEvent $event): array => $event->selection->lines)->all())->toBe([[], []]);
})->with('tracking providers');

it('reports a retail Purchase with its Packs, from the retail Cart, and a bulk one from the bulk Cart', function (ProviderScript $script) {
    $script->switchOn();

    $order = Order::factory()->retail()->create();
    OrderLine::factory()->for($order)->ofPack($this->quarter, 2)->create();
    recordCaptureOf($order->refresh());

    $sent = sentAfterSweep($script);

    expect($script->eventNameOf($sent))->toBe($script->purchaseEvent())
        ->and($script->contentsOf($sent))->toBe([['id' => (string) $this->gold->getKey(), 'quantity' => 2, 'price' => 270.0]])
        ->and($script->variantsOf($sent))->toBe(['250 g'])
        ->and($script->cartOf($sent))->toBe('retail');

    TrackingEvent::query()->delete();
    $bulk = orderAsCaptured();
    recordCaptureOf($bulk);

    $sent = sentAfterSweep($script);

    expect($script->cartOf($sent))->toBe('bulk')
        ->and($script->variantsOf($sent))->each->toBeNull();
})->with('tracking providers');

it('tells the browser\'s Pixel which Cart a live selection came from, and each Pack\'s variant', function (ProviderScript $script) {
    $script->switchOn();
    Page::factory()->system(SystemPage::Home)->create();

    $html = $this->get('/')->getContent();

    expect($html)->toContain('"cart": s.cart')
        ->and($html)->toContain('c.variant = l.variant');
})->with('tracking providers');
