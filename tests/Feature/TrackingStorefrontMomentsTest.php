<?php

use App\Enums\TrackingMoment;
use App\Models\Product;
use App\Models\TrackingEvent;
use App\Support\Settings\TrackingSettings;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\Tracking\ProviderScript;

/**
 * Product Viewed and Added to Cart, reported to the server by the beacon.
 *
 * Both happen in the browser and are witnessed nowhere else, so the beacon
 * carries the server copy under the deduplication id the Pixel fired with.
 * The browser names Products and Kilograms; the prices are the catalogue's,
 * worked out by the pricing module as if what was named were a Cart of its
 * own.
 */

/**
 * The browser reporting a storefront Moment about Products, as the
 * storefront posts it: `id:kilograms` pairs and the page it was on.
 */
function reportProducts(TrackingMoment $moment, string $items, string $identifier = 'cart-7c1e-12'): TestResponse
{
    return reportToBeacon($moment, $identifier, ['items' => $items], ['Referer' => 'https://shop.test/tea/gold']);
}

dataset('storefront moments', [
    'product viewed' => [TrackingMoment::ProductViewed, fn (ProviderScript $script): array => $script->productViewedEvents()],
    'added to cart' => [TrackingMoment::AddedToCart, fn (ProviderScript $script): array => $script->addedToCartEvents()],
]);

it('writes the server copy of what the moment reports', function (ProviderScript $script, TrackingMoment $moment, Closure $events) {
    $script->makeDestination();
    $gold = Product::factory()->create(['price' => 55000]);

    reportProducts($moment, $gold->getKey().':1')->assertNoContent();

    expect(TrackingEvent::query()->orderBy('id')->pluck('event')->all())->toBe($events($script));

    $row = TrackingEvent::query()->firstOrFail();

    expect($row->moment)->toBe($moment)
        ->and($row->deduplication_id)->toBe('cart-7c1e-12')
        ->and($row->order_id)->toBeNull()
        ->and($row->source_url)->toBe('https://shop.test/tea/gold');
})->with('tracking providers')->with('storefront moments');

it('reports the product viewed at what one kilogram of it costs', function (ProviderScript $script) {
    $script->makeDestination();
    $gold = Product::factory()->create(['tiers' => [
        ['min' => 1, 'max' => 5, 'price' => 55000],
        ['min' => 6, 'max' => null, 'price' => 43000],
    ]]);

    reportProducts(TrackingMoment::ProductViewed, $gold->getKey().':1')->assertNoContent();

    $script->fakeAccepted();
    $this->artisan('tracking:sweep')->assertSuccessful();

    $sent = $script->sentEvents()[0];

    expect($script->eventNameOf($sent))->toBe($script->productViewedEvents()[0])
        ->and($script->valueOf($sent))->toBe(550.0)
        ->and($script->contentsOf($sent))->toBe([
            ['id' => (string) $gold->getKey(), 'quantity' => 1, 'price' => 550.0],
        ]);
})->with('tracking providers');

/**
 * Kilograms are what move a Product down its own ladder, so ten of them are
 * reported at the Tier ten kilograms reach, and no Delivery Charge rides on
 * top: that enters a reported value only at the Purchase.
 */
it('reports what was added to the cart at the tier its kilograms reach', function (ProviderScript $script) {
    $script->makeDestination();
    $gold = Product::factory()->create(['tiers' => [
        ['min' => 1, 'max' => 5, 'price' => 55000],
        ['min' => 6, 'max' => null, 'price' => 43000],
    ]]);

    reportProducts(TrackingMoment::AddedToCart, $gold->getKey().':10')->assertNoContent();

    $script->fakeAccepted();
    $this->artisan('tracking:sweep')->assertSuccessful();

    $sent = $script->sentEvents()[0];

    expect($script->eventNameOf($sent))->toBe($script->addedToCartEvents()[0])
        ->and($script->valueOf($sent))->toBe(4300.0)
        ->and($script->contentsOf($sent))->toBe([
            ['id' => (string) $gold->getKey(), 'quantity' => 10, 'price' => 430.0],
        ])
        ->and($script->orderReferenceOf($sent))->toBeNull();
})->with('tracking providers');

/**
 * The deduplication id the storefront makes up for one Product in one Cart:
 * the same Product added to the same Cart again lands on the outbox's unique
 * index, which is what makes it once per Product per Cart on the server too.
 */
it('counts one added to cart per product per cart however often the browser reports it', function (ProviderScript $script) {
    $script->makeDestination();
    $gold = Product::factory()->create();
    $green = Product::factory()->create();

    reportProducts(TrackingMoment::AddedToCart, $gold->getKey().':1', 'cart-7c1e-'.$gold->getKey())->assertNoContent();
    reportProducts(TrackingMoment::AddedToCart, $gold->getKey().':3', 'cart-7c1e-'.$gold->getKey())->assertNoContent();
    reportProducts(TrackingMoment::AddedToCart, $green->getKey().':1', 'cart-7c1e-'.$green->getKey())->assertNoContent();

    expect(TrackingEvent::count())->toBe(count($script->addedToCartEvents()) * 2);
})->with('tracking providers');

it('writes nothing for a moment the store has switched off', function (ProviderScript $script, TrackingMoment $moment, Closure $events) {
    $script->makeDestination();
    $gold = Product::factory()->create();

    app(TrackingSettings::class)->with([
        'eventDefaults' => [$script->provider()->value => array_fill_keys($events($script), false)],
    ])->save();

    reportProducts($moment, $gold->getKey().':1')->assertNoContent();

    expect(TrackingEvent::count())->toBe(0);
})->with('tracking providers')->with('storefront moments');

it('reports the money and not the products where the store does not name them', function (ProviderScript $script, TrackingMoment $moment) {
    $script->makeDestination();
    $gold = Product::factory()->create(['price' => 55000]);

    app(TrackingSettings::class)->with(['namesProducts' => false])->save();

    reportProducts($moment, $gold->getKey().':2')->assertNoContent();

    $script->fakeAccepted();
    $this->artisan('tracking:sweep')->assertSuccessful();

    $sent = $script->sentEvents()[0];

    expect($script->valueOf($sent))->toBe(1100.0)
        ->and($script->contentsOf($sent))->toBe([]);
})->with('tracking providers')->with([TrackingMoment::ProductViewed, TrackingMoment::AddedToCart]);

it('reports nothing it does not sell', function (ProviderScript $script) {
    $script->makeDestination();
    $retired = Product::factory()->create();
    $retired->delete();
    $draft = Product::factory()->draft()->create();

    reportProducts(TrackingMoment::AddedToCart, $retired->getKey().':1,'.$draft->getKey().':1,999999:2')->assertNoContent();

    $script->fakeAccepted();
    $this->artisan('tracking:sweep')->assertSuccessful();

    $sent = $script->sentEvents()[0];

    expect($script->valueOf($sent))->toBe(0.0)
        ->and($script->contentsOf($sent))->toBe([]);
})->with('tracking providers');
