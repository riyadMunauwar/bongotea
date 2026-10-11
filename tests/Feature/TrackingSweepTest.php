<?php

use App\Enums\TrackingEventState;
use App\Enums\TrackingMoment;
use App\Models\Order;
use App\Models\Product;
use App\Models\TrackingEvent;
use App\Support\Settings\GeneralSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Tracking\ProviderScript;

/**
 * Seam B: what the sweep sends, and what it writes down about how that went.
 *
 * Every test here fakes the Provider's HTTP client, runs the real command,
 * and asserts on the request that left and the rows that changed. Nothing
 * reaches into a payload builder: the payload is read back off the faked
 * client the way the Provider's own servers would have received it.
 */

/**
 * A captured Cash on Delivery Order reporting to a Provider switched on and supplied: the
 * shape every test here starts from, captured and recorded for real so the
 * row under test is one a Customer actually produced.
 *
 * @param  array<string, mixed>  $details
 */
function reportingCapture(ProviderScript $script, int $price = 99000, int $quantity = 1, array $details = [], int $deliveryCharge = 0): Order
{
    $script->switchOn();
    $product = Product::factory()->create(['name' => 'Winter Jacket', 'price' => $price]);

    $order = orderAsCaptured($details, [['product' => $product, 'kilograms' => $quantity]]);

    if ($deliveryCharge > 0) {
        $order->update(['delivery_charge' => $deliveryCharge]);
    }

    recordCaptureOf($order->refresh());

    return $order;
}

it('delivers every pending row for a provider in one request and marks them sent', function (ProviderScript $script) {
    reportingCapture($script);

    $script->fakeAccepted();

    $this->artisan('tracking:sweep')
        ->expectsOutputToContain('Sent 1 tracking event')
        ->assertSuccessful();

    $script->assertBatchesSent(1);
    $script->assertAddressedToTheAccount();
    $script->assertAuthorisedWith($script->accessToken());

    $row = TrackingEvent::sole();

    expect($row->state)->toBe(TrackingEventState::Sent)
        ->and($row->sent_at)->not->toBeNull()
        ->and($row->attempts)->toBe(1)
        ->and($row->last_error)->toBeNull();
})->with('tracking providers');

it('reports the purchase with the order number as both the deduplication id and the merchant reference', function (ProviderScript $script) {
    $order = reportingCapture($script);

    $script->fakeAccepted();
    $this->artisan('tracking:sweep')->assertSuccessful();

    $event = $script->sentEvents()[0];

    expect($script->eventNameOf($event))->toBe($script->purchaseEvent())
        ->and($script->deduplicationIdOf($event))->toBe($order->order_number)
        ->and($script->orderReferenceOf($event))->toBe($order->order_number)
        ->and($script->actionSourceOf($event))->toBe('website')
        ->and($script->eventTimeOf($event))->toBe($order->created_at->getTimestamp())
        ->and($script->sourceUrlOf($event))->toBe('https://shop.test/checkout');
})->with('tracking providers');

it('reports a value that includes the delivery charge, so it agrees with the merchants own sales figure', function (ProviderScript $script) {
    $order = reportingCapture($script, price: 99000, quantity: 2, deliveryCharge: 6000);

    expect($order->subtotal)->toBe(198000)
        ->and($order->delivery_charge)->toBe(6000)
        ->and($order->total)->toBe(204000);

    $script->fakeAccepted();
    $this->artisan('tracking:sweep')->assertSuccessful();

    $event = $script->sentEvents()[0];

    expect($script->valueOf($event))->toBe(2040.0)
        ->and($script->currencyOf($event))->toBe('BDT');
})->with('tracking providers');

it('reports the currency the merchant set, not the one config shipped with', function (ProviderScript $script) {
    app(GeneralSettings::class)->with(['currencyCode' => 'usd'])->save();

    reportingCapture($script);

    $script->fakeAccepted();
    $this->artisan('tracking:sweep')->assertSuccessful();

    expect($script->currencyOf($script->sentEvents()[0]))->toBe('USD');
})->with('tracking providers');

it('reports the contents from the order lines, at the price and quantity agreed at capture', function (ProviderScript $script) {
    $order = reportingCapture($script, price: 45000, quantity: 3);
    $line = $order->lines()->sole();

    $script->fakeAccepted();
    $this->artisan('tracking:sweep')->assertSuccessful();

    expect($script->contentsOf($script->sentEvents()[0]))->toBe([
        ['id' => (string) $line->product_id, 'quantity' => 3, 'price' => 450.0],
    ]);
})->with('tracking providers');

it('hashes every match key, and leaves the ip, the user agent and the click identifiers plain', function (ProviderScript $script) {
    $script->switchOn();

    $order = orderAsCaptured([
        'name' => 'Rahim Uddin',
        'email' => 'Rahim.Uddin@Example.COM',
        'district' => 'Dhaka',
    ]);

    recordCaptureOf($order, server: [
        'REMOTE_ADDR' => '203.0.113.42',
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (Linux; Android 13) Chrome/120.0 Mobile',
    ]);

    $script->fakeAccepted();
    $this->artisan('tracking:sweep')->assertSuccessful();

    $event = $script->sentEvents()[0];
    $hashed = $script->hashedMatchKeysOf($event);

    expect($hashed['phone'])->toBe($script->hash($script->normalise('phone', $order->customer_phone)))
        ->and($hashed['email'])->toBe($script->hash($script->normalise('email', 'Rahim.Uddin@Example.COM')))
        ->and($hashed['firstName'])->toBe($script->hash($script->normalise('firstName', 'Rahim')))
        ->and($hashed['lastName'])->toBe($script->hash($script->normalise('lastName', 'Uddin')))
        ->and($hashed['city'])->toBe($script->hash($script->normalise('city', 'Dhaka')))
        ->and($hashed['externalId'])->toBe($script->hash('customer-'.$order->customer_id));

    /** Nothing personal reaches the wire in the clear. */
    $wire = json_encode($event);

    expect($wire)->not->toContain('Rahim')
        ->not->toContain('Uddin')
        ->not->toContain('Example.COM')
        ->not->toContain('01712345678')
        ->not->toContain('8801712345678');

    /** These three, on the other hand, are matched on by the Provider itself, so a hashed one matches nobody. */
    expect($script->plainSignalsOf($event))->toBe([
        'ip' => '203.0.113.42',
        'userAgent' => 'Mozilla/5.0 (Linux; Android 13) Chrome/120.0 Mobile',
        'clickIds' => [],
    ]);
})->with('tracking providers');

it('leaves a match key out rather than sending a hashed blank the provider can never match', function (ProviderScript $script) {
    $script->switchOn();

    /** The Customer gave a one-word name and no email. */
    recordCaptureOf(orderAsCaptured(['name' => 'Rahim', 'email' => null]));

    $script->fakeAccepted();
    $this->artisan('tracking:sweep')->assertSuccessful();

    $event = $script->sentEvents()[0];
    $hashed = $script->hashedMatchKeysOf($event);

    /** The block itself always travels, even when only some of it is filled: a batch missing it is refused whole. */
    expect($script->carriesMatchKeyBlock($event))->toBeTrue();

    expect($hashed)->not->toHaveKey('email')

        /** A one-word name is a first name and no last name, not the same word twice. */
        ->and($hashed)->not->toHaveKey('lastName')
        ->and($hashed)->toHaveKey('firstName');
})->with('tracking providers');

it('cuts batches at the providers own limit and sends one request per batch', function (ProviderScript $script) {
    reportingCapture($script);
    $order = Order::sole();
    $limit = $script->batchLimit();

    /**
     * Written straight in rather than through the recorder: what is under
     * test is the sweep's cut, and a thousand real Captures would prove the
     * same thing far more slowly.
     */
    $rows = [];

    for ($i = 0; $i < $limit; $i++) {
        $rows[] = [
            'provider' => $script->provider()->value,
            'moment' => TrackingMoment::Capture->value,
            'event' => $script->purchaseEvent(),
            'deduplication_id' => 'filler-'.$i,
            'occurred_at' => now(),
            'order_id' => $order->getKey(),
            'state' => TrackingEventState::Pending->value,
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    DB::table('tracking_events')->insert($rows);

    $script->fakeAccepted();
    $this->artisan('tracking:sweep')->assertSuccessful();

    expect($script->batchSizes())->toBe([$limit, 1])
        ->and(TrackingEvent::query()->where('state', TrackingEventState::Sent)->count())->toBe($limit + 1);
})->with('tracking providers');

it('leaves rows pending when the provider cannot be reached, so a moments outage costs nothing', function (ProviderScript $script) {
    reportingCapture($script);

    $script->fakeOutage();

    $this->artisan('tracking:sweep')
        ->expectsOutputToContain('Left 1 event pending')
        ->assertSuccessful();

    $row = TrackingEvent::sole();

    expect($row->state)->toBe(TrackingEventState::Pending)
        ->and($row->attempts)->toBe(1)
        ->and($row->last_error)->not->toBeNull()
        ->and($row->sent_at)->toBeNull();

    /** And the next sweep delivers it, which is the whole point of never writing Failed for an outage. */
    $script->endOutage();
    $this->artisan('tracking:sweep')->assertSuccessful();

    expect(TrackingEvent::sole()->state)->toBe(TrackingEventState::Sent);
})->with('tracking providers');

it('leaves rows pending when the provider answers a server error, which says nothing about the batch', function (ProviderScript $script) {
    reportingCapture($script);

    $script->fakeServerError();
    $this->artisan('tracking:sweep')->assertSuccessful();

    expect(TrackingEvent::sole()->state)->toBe(TrackingEventState::Pending);
})->with('tracking providers');

it('leaves rows pending when the provider is rate limiting us, however that answer is dressed up', function (ProviderScript $script) {
    reportingCapture($script);

    $script->fakeThrottled();

    $this->artisan('tracking:sweep')
        ->expectsOutputToContain('Left 1 event pending')
        ->assertSuccessful();

    /** Marked Failed, a spike that tripped the limit would throw away a thousand real events. */
    expect(TrackingEvent::sole()->state)->toBe(TrackingEventState::Pending);
})->with('tracking providers');

it('marks a refused batch failed with what the provider said, because retrying it would never end', function (ProviderScript $script) {
    reportingCapture($script);

    $script->fakeRefused('Invalid OAuth access token.');

    $this->artisan('tracking:sweep')
        ->expectsOutputToContain('Failed 1 event')
        ->assertSuccessful();

    $row = TrackingEvent::sole();

    expect($row->state)->toBe(TrackingEventState::Failed)
        ->and($row->last_error)->toContain('Invalid OAuth access token.')
        ->and($row->attempts)->toBe(1);

    /** A failed row is settled: the next sweep does not ask again. */
    $script->fakeAccepted();
    $this->artisan('tracking:sweep')->assertSuccessful();

    $script->assertBatchesSent(0);
})->with('tracking providers');

it('holds a switched-off providers rows and still expires them once they are too old', function (ProviderScript $script) {
    reportingCapture($script);

    $script->switchOff();

    Http::fake();

    $this->artisan('tracking:sweep')
        ->expectsOutputToContain($script->provider()->getLabel().' is switched off in Settings.')
        ->assertSuccessful();

    expect(TrackingEvent::sole()->state)->toBe(TrackingEventState::Pending);

    $this->travelTo(now()->addSeconds($script->acceptanceWindowSeconds() + 60));

    $this->artisan('tracking:sweep')
        ->expectsOutputToContain('Expired 1 event')
        ->assertSuccessful();

    expect(TrackingEvent::sole()->state)->toBe(TrackingEventState::Expired);

    Http::assertNothingSent();
})->with('tracking providers');

it('expires a row the provider will no longer accept rather than retrying it forever', function (ProviderScript $script) {
    reportingCapture($script);

    Http::fake();

    $this->travelTo(now()->addSeconds($script->acceptanceWindowSeconds() + 60));

    $this->artisan('tracking:sweep')
        ->expectsOutputToContain('Expired 1 event')
        ->assertSuccessful();

    expect(TrackingEvent::sole()->state)->toBe(TrackingEventState::Expired);

    Http::assertNothingSent();
})->with('tracking providers');

it('skips an unusable provider with its own wording, and keeps its rows for when it is fixed', function (ProviderScript $script) {
    reportingCapture($script);

    $script->withoutAccessToken();

    Http::fake();

    $this->artisan('tracking:sweep')
        ->expectsOutputToContain($script->provider()->driver()->refusal())
        ->assertSuccessful();

    Http::assertNothingSent();

    expect(TrackingEvent::sole()->state)->toBe(TrackingEventState::Pending);

    /** The wording is the Provider's, said once, and the admin reads the same sentence. */
    expect($script->provider()->driver()->refusal())->toContain('has no access token');

    $script->restoreAccessToken('EAA-restored');

    $script->fakeAccepted();
    $this->artisan('tracking:sweep')->assertSuccessful();

    expect(TrackingEvent::sole()->state)->toBe(TrackingEventState::Sent);
})->with('tracking providers');

it('speaks to nobody when there is nothing waiting', function (ProviderScript $script) {
    $script->switchOn();

    Http::fake();

    $this->artisan('tracking:sweep')
        ->expectsOutputToContain('Sent 0 tracking events')
        ->assertSuccessful();

    Http::assertNothingSent();
})->with('tracking providers');

it('is scheduled beside the payment sweep, and never runs two at once', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains($event->command ?? '', 'tracking:sweep'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
