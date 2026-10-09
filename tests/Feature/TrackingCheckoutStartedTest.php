<?php

use App\Enums\TrackingMoment;
use App\Http\Requests\CheckoutRequest;
use App\Models\Product;
use App\Models\TrackingEvent;
use App\Support\Settings\TrackingSettings;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\Tracking\ProviderScript;

/**
 * A visitor who starts the checkout, reported to the server by the beacon.
 *
 * The Moment that happens between a load and an Order, and the only one whose
 * money nobody has written down yet: there is no Order to read a basket off,
 * so what the visitor has in their Cart travels to the beacon, and the server
 * prices it from the catalogue rather than believe what it is told.
 * Everything below is read out of the rows the beacon actually writes, or the
 * request the sweep actually sends.
 */

/**
 * The browser reporting that a visitor has started the checkout. The items
 * ride as `id:quantity` pairs, which is the whole of what the browser is
 * trusted to say — the prices are the catalogue's.
 */
function reportStart(?string $items = null, string $identifier = 'load-3f9a2c'): TestResponse
{
    return reportToBeacon(
        TrackingMoment::CheckoutStarted,
        $identifier,
        $items === null ? [] : ['items' => $items],
        ['Referer' => 'https://shop.test/checkout'],
    );
}

describe('the beacon', function () {
    it('writes the server copy of everything the moment reports', function (ProviderScript $script) {
        $script->makeDestination();
        $jacket = Product::factory()->create(['price' => 99000]);

        reportStart($jacket->getKey().':2')->assertNoContent();

        expect(TrackingEvent::query()->orderBy('id')->pluck('event')->all())->toBe($script->checkoutStartedEvents());

        $row = TrackingEvent::query()->firstOrFail();

        expect($row->moment)->toBe(TrackingMoment::CheckoutStarted)
            ->and($row->deduplication_id)->toBe('load-3f9a2c')
            ->and($row->order_id)->toBeNull()
            ->and($row->source_url)->toBe('https://shop.test/checkout');
    })->with('tracking providers');

    /**
     * What the Provider is actually told, read back off the request the sweep
     * made: the money and the basket the visitor had in front of them, and no
     * Order reference, because there is no Order.
     */
    it('reports what the visitor had in the cart, at the catalogue\'s own prices', function (ProviderScript $script) {
        $script->makeDestination();
        $jacket = Product::factory()->create(['name' => 'Winter Jacket', 'price' => 99000]);
        Product::factory()->create(['name' => 'Scarf', 'price' => 15000]);

        reportStart($jacket->getKey().':2')->assertNoContent();

        $script->fakeAccepted();
        $this->artisan('tracking:sweep')->assertSuccessful();

        $sent = $script->sentEvents()[0];

        expect($script->valueOf($sent))->toBe(1980.0)
            ->and($script->contentsOf($sent))->toBe([
                ['id' => (string) $jacket->getKey(), 'quantity' => 2, 'price' => 990.0],
            ])
            ->and($script->orderReferenceOf($sent))->toBeNull()
            ->and($script->sourceUrlOf($sent))->toBe('https://shop.test/checkout');
    })->with('tracking providers');

    /**
     * The Cart's whole weight is what moves each Product down its own ladder,
     * so four kilograms of one tea and two of another are both priced at the
     * Tier six kilograms reach — the figure the Checkout's summary showed.
     */
    it('prices each product at the tier the whole cart\'s kilograms reach', function (ProviderScript $script) {
        $script->makeDestination();
        $gold = Product::factory()->create(['tiers' => [
            ['min' => 1, 'max' => 5, 'price' => 55000],
            ['min' => 6, 'max' => null, 'price' => 43000],
        ]]);
        $green = Product::factory()->create(['tiers' => [
            ['min' => 1, 'max' => 5, 'price' => 45000],
            ['min' => 6, 'max' => null, 'price' => 40000],
        ]]);

        reportStart($gold->getKey().':4,'.$green->getKey().':2')->assertNoContent();

        $script->fakeAccepted();
        $this->artisan('tracking:sweep')->assertSuccessful();

        $sent = $script->sentEvents()[0];

        expect($script->valueOf($sent))->toBe(2520.0)
            ->and($script->contentsOf($sent))->toBe([
                ['id' => (string) $gold->getKey(), 'quantity' => 4, 'price' => 430.0],
                ['id' => (string) $green->getKey(), 'quantity' => 2, 'price' => 400.0],
            ]);
    })->with('tracking providers');

    /**
     * The prices are the catalogue's and nothing that arrives may say
     * otherwise. A Product that does not exist adds no line and no money
     * however loudly it is named.
     */
    it('prices what it is told about from the catalogue, and reports nothing it does not sell', function (ProviderScript $script) {
        $script->makeDestination();
        $jacket = Product::factory()->create(['price' => 99000]);
        $retired = Product::factory()->create(['price' => 500000]);
        $retired->delete();

        reportStart($jacket->getKey().':1,'.$retired->getKey().':3,999999:4')->assertNoContent();

        $script->fakeAccepted();
        $this->artisan('tracking:sweep')->assertSuccessful();

        $sent = $script->sentEvents()[0];

        expect($script->valueOf($sent))->toBe(990.0)
            ->and($script->contentsOf($sent))->toBe([
                ['id' => (string) $jacket->getKey(), 'quantity' => 1, 'price' => 990.0],
            ]);
    })->with('tracking providers');

    it('brings a quantity nobody could order back inside what a checkout would take', function (ProviderScript $script) {
        $script->makeDestination();
        $jacket = Product::factory()->create(['price' => 99000]);

        reportStart($jacket->getKey().':99999')->assertNoContent();

        $script->fakeAccepted();
        $this->artisan('tracking:sweep')->assertSuccessful();

        $contents = $script->contentsOf($script->sentEvents()[0]);

        expect($contents[0]['quantity'])->toBe(CheckoutRequest::MAX_KILOGRAMS);
    })->with('tracking providers');

    it('reports no money where the browser named nothing', function (ProviderScript $script) {
        $script->makeDestination();
        Product::factory()->create(['price' => 99000]);

        reportStart()->assertNoContent();

        $script->fakeAccepted();
        $this->artisan('tracking:sweep')->assertSuccessful();

        expect($script->valueOf($script->sentEvents()[0]))->toBe(0.0);
    })->with('tracking providers');

    it('counts one checkout started however many times the browser reports it', function (ProviderScript $script) {
        $script->makeDestination();
        $jacket = Product::factory()->create(['price' => 99000]);

        reportStart($jacket->getKey().':1')->assertNoContent();
        reportStart($jacket->getKey().':1')->assertNoContent();

        /** A second page load is a second Moment, under its own identifier. */
        reportStart($jacket->getKey().':1', identifier: 'load-second')->assertNoContent();

        expect(TrackingEvent::count())->toBe(count($script->checkoutStartedEvents()) * 2);
    })->with('tracking providers');

    it('writes nothing where the store has switched the event off', function (ProviderScript $script) {
        $script->makeDestination();
        $jacket = Product::factory()->create();

        app(TrackingSettings::class)->with([
            'eventDefaults' => [$script->provider()->value => array_fill_keys($script->checkoutStartedEvents(), false)],
        ])->save();

        reportStart($jacket->getKey().':1')->assertNoContent();

        expect(TrackingEvent::count())->toBe(0);
    })->with('tracking providers');
});
