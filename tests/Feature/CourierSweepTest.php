<?php

use App\Enums\Courier;
use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Shipment;
use App\Support\Couriers\CourierSweep;
use App\Support\Reporting\AttentionReason;
use App\Support\Reporting\NeedsAttentionRow;
use App\Support\Reporting\ReportingQuery;
use App\Support\Settings\CourierSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Couriers\CourierScript;

/**
 * The sweep that keeps a booked parcel current without anybody refreshing
 * anything: it asks each Courier where its Active Shipments stand and writes
 * what it learned onto their timelines.
 *
 * Every case runs once per Courier over `tests/Datasets/CourierScripts.php`,
 * because the three disagree about this more than about anything else — one
 * keeps a whole history and times every line of it, one answers with a single
 * word and no time at all, one answers with a word and the moment it last
 * changed, and each says for itself how long it is worth being left alone. No
 * test here reaches for a driver: the asymmetries are asked of the script.
 */

/*
|--------------------------------------------------------------------------
| Asking, and writing down what was said
|--------------------------------------------------------------------------
*/

it('asks the courier where a freshly booked parcel is and records what it said', function (CourierScript $script) {
    [$moving, $movingWord] = stillMoving($script);

    $shipment = parcelInFlight($script);

    $script->fakeTracking($movingWord);

    $this->artisan('couriers:sweep')
        ->expectsOutputToContain('Asked about 1 parcel')
        ->expectsOutputToContain('Recorded 1 timeline entry')
        ->assertSuccessful();

    $script->assertTrackedFor($shipment->refresh());

    /** The canonical state moved, and the Courier's own word is kept beside it for the timeline to print (ADR-0018). */
    expect($shipment->status)->toBe($moving)
        ->and($shipment->raw_status)->toBe($movingWord)
        ->and($shipment->polled_at)->not->toBeNull();

    $event = $shipment->events()->get()->last();

    /** Both times: the Courier's where the Courier said, and ours whatever it said. */
    expect($event->status)->toBe($moving)
        ->and($event->raw_status)->toBe($movingWord)
        ->and($event->recorded_at)->not->toBeNull()
        ->and($event->occurred_at !== null)->toBe($script->reportsItsOwnTime());
})->with('courier scripts');

it('writes one timeline entry however many times the same thing is reported', function (CourierScript $script) {
    [, $movingWord] = stillMoving($script);
    $bookedWord = $script->rawStatusesFor(ShipmentStatus::Booked)[0];

    $shipment = parcelInFlight($script);

    $script->fakeTracking($bookedWord, $movingWord);

    $this->artisan('couriers:sweep')->assertSuccessful();

    $entries = $shipment->events()->count();

    expect($entries)->toBe($script->timelineEntriesFor($bookedWord, $movingWord));

    /** Due again, and answered with exactly what it was answered with before — which is a whole history re-imported, for the one Courier that keeps one. */
    $this->travel($script->reAskIntervalSeconds() + 1)->seconds();

    $this->artisan('couriers:sweep')
        ->expectsOutputToContain('Asked about 1 parcel')
        ->expectsOutputToContain('Recorded 0 timeline entries')
        ->assertSuccessful();

    $script->assertPollsSent(2);

    /** The unique key carried it, not a check at the call site (ADR-0019). */
    expect($shipment->events()->count())->toBe($entries);
})->with('courier scripts');

it('records a parcel that has moved on since it was last asked about', function (CourierScript $script) {
    [, $movingWord] = stillMoving($script);
    $deliveredWord = $script->rawStatusesFor(ShipmentStatus::Delivered)[0];

    $shipment = parcelInFlight($script);

    $script->fakeTracking($movingWord);

    $this->artisan('couriers:sweep')->assertSuccessful();

    answeringAfresh();

    /** A Courier that keeps a history answers with both; one that keeps none answers with where the parcel is now. */
    $script->fakeTracking($movingWord, $deliveredWord);

    $this->travel($script->reAskIntervalSeconds() + 1)->seconds();

    $this->artisan('couriers:sweep')->assertSuccessful();

    /**
     * Two things happened and the timeline holds two, whether the Courier
     * named the second alone or told us both over again.
     */
    expect($shipment->refresh()->status)->toBe(ShipmentStatus::Delivered)
        ->and($shipment->raw_status)->toBe($deliveredWord)
        ->and($shipment->events()->get()->last()->raw_status)->toBe($deliveredWord)
        ->and($shipment->events()->count())->toBe(2);
})->with('courier scripts');

/*
|--------------------------------------------------------------------------
| Who is asked, and how often
|--------------------------------------------------------------------------
*/

it('leaves a parcel alone until its own courier says it is worth asking again', function (CourierScript $script) {
    [, $movingWord] = stillMoving($script);

    parcelInFlight($script);

    $script->fakeTracking($movingWord);

    $this->artisan('couriers:sweep')->assertSuccessful();
    $script->assertPollsSent(1);

    /** A minute short of this Courier's own interval, which is its driver's property and never one constant for the three. */
    $this->travel($script->reAskIntervalSeconds() - 60)->seconds();

    $this->artisan('couriers:sweep')
        ->expectsOutputToContain('Asked about 0 parcels')
        ->assertSuccessful();

    $script->assertPollsSent(1);

    $this->travel(61)->seconds();

    $this->artisan('couriers:sweep')
        ->expectsOutputToContain('Asked about 1 parcel')
        ->assertSuccessful();

    $script->assertPollsSent(2);
})->with('courier scripts');

it('stops asking about a parcel the courier has finished with', function (CourierScript $script) {
    $deliveredWord = $script->rawStatusesFor(ShipmentStatus::Delivered)[0];

    parcelInFlight($script);

    $script->fakeTracking($deliveredWord);

    $this->artisan('couriers:sweep')->assertSuccessful();

    expect(Shipment::sole()->status)->toBe(ShipmentStatus::Delivered);

    $this->travel($script->reAskIntervalSeconds() + 1)->seconds();

    $this->artisan('couriers:sweep')
        ->expectsOutputToContain('Asked about 0 parcels')
        ->assertSuccessful();

    /** Terminal, so it is not Active, so it is not asked about: one reading of Active and no second rule here. */
    $script->assertPollsSent(1);
})->with('courier scripts');

it('leaves a parcel a user has written off alone', function (CourierScript $script) {
    parcelInFlight($script, ['status' => ShipmentStatus::OnHold, 'abandoned_at' => now()]);

    Http::fake();

    $this->artisan('couriers:sweep')
        ->expectsOutputToContain('Asked about 0 parcels')
        ->assertSuccessful();

    Http::assertNothingSent();
})->with('courier scripts');

it('goes on following a parcel whose order a user has cancelled', function (CourierScript $script) {
    $order = Order::factory()->cancelled()->create();
    $shipment = parcelInFlight($script, ['order_id' => $order->getKey()]);

    $script->fakeTracking($script->returnedWording());

    $this->artisan('couriers:sweep')->assertSuccessful();

    $script->assertPollsSent(1);

    /** Two axes that never write each other: the parcel came back and the Order is still exactly what the User decided (ADR-0018). */
    expect($shipment->refresh()->status)->toBe(ShipmentStatus::Returned)
        ->and($order->refresh()->status)->toBe(OrderStatus::Cancelled);
})->with('courier scripts');

it('turns a parcel that came back into something a user must decide about, and decides nothing itself', function (CourierScript $script) {
    $order = Order::factory()->confirmed()->create();
    $shipment = parcelInFlight($script, ['order_id' => $order->getKey()]);

    $script->fakeTracking($script->returnedWording());

    $this->artisan('couriers:sweep')->assertSuccessful();

    $rows = app(ReportingQuery::class)->needsAttention()->rows;

    /** The parcel is over, the Order is exactly what its User left it, and the question is on somebody's list (ADR-0018). */
    expect($shipment->refresh()->status)->toBe(ShipmentStatus::Returned)
        ->and($order->refresh()->status)->toBe(OrderStatus::Confirmed)
        ->and(array_map(fn (NeedsAttentionRow $row): array => [$row->order->getKey(), $row->reason], $rows))
        ->toBe([[$order->getKey(), AttentionReason::ParcelReturned]]);
})->with('courier scripts');

it('never moves an order status whatever the courier says about the parcel', function (CourierScript $script) {
    $order = Order::factory()->create();

    parcelInFlight($script, ['order_id' => $order->getKey()]);

    $script->fakeTracking($script->rawStatusesFor(ShipmentStatus::Delivered)[0]);

    $this->artisan('couriers:sweep')->assertSuccessful();

    expect($order->refresh()->status)->toBe(OrderStatus::New);
})->with('courier scripts');

it('passes over a courier this installation cannot speak to, in the one wording the admin gives', function (CourierScript $script) {
    parcelInFlight($script);

    app(CourierSettings::class)->with([CourierSettings::property($script->courier(), 'Enabled') => false])->save();
    app()->forgetInstance(CourierSettings::class);

    Http::fake();

    $this->artisan('couriers:sweep')
        ->expectsOutputToContain($script->courier()->refusal())
        ->assertSuccessful();

    Http::assertNothingSent();
})->with('courier scripts');

/*
|--------------------------------------------------------------------------
| A courier that would not answer
|--------------------------------------------------------------------------
*/

it('leaves a parcel exactly as it was when the courier could not be reached, and asks again next sweep', function (CourierScript $script) {
    [$moving, $movingWord] = stillMoving($script);

    $shipment = parcelInFlight($script);

    $script->fakeOutage();

    $this->artisan('couriers:sweep')->assertSuccessful();

    /** Nothing concluded and nothing recorded — not even that it was asked, which is what makes it due again at once (ADR-0019). */
    expect($shipment->refresh()->status)->toBe(ShipmentStatus::Booked)
        ->and($shipment->polled_at)->toBeNull()
        ->and($shipment->events()->count())->toBe(0);

    answeringAfresh();
    $script->fakeTracking($movingWord);

    $this->artisan('couriers:sweep')
        ->expectsOutputToContain('Asked about 1 parcel')
        ->assertSuccessful();

    expect($shipment->refresh()->status)->toBe($moving)
        ->and($shipment->polled_at)->not->toBeNull();
})->with('courier scripts');

it('writes down a parcel the courier answered about and would not account for', function (CourierScript $script) {
    $shipment = parcelInFlight($script);

    $script->fakeTrackingRefused();

    $this->artisan('couriers:sweep')
        ->expectsOutputToContain('Refused 1 parcel')
        ->assertSuccessful();

    /**
     * An answer, and about this parcel: Unknown is "somebody has to look at
     * this", worded in what the Courier itself said. And it is not terminal,
     * so the parcel goes on being followed rather than being written off by a
     * sweep — only a User may do that.
     */
    expect($shipment->refresh()->status)->toBe(ShipmentStatus::Unknown)
        ->and($shipment->raw_status)->toContain($script->trackingRefusalWording())
        ->and($shipment->polled_at)->not->toBeNull()
        ->and($shipment->isActive())->toBeTrue()
        ->and($shipment->events()->sole()->raw_status)->toContain($script->trackingRefusalWording());

    $this->travel($script->reAskIntervalSeconds() + 1)->seconds();

    $this->artisan('couriers:sweep')->assertSuccessful();

    /** The same refusal twice is one timeline entry, for the same reason a repeated status is. */
    expect($shipment->refresh()->events()->count())->toBe(1);
})->with('courier scripts');

/*
|--------------------------------------------------------------------------
| Stuck, and giving up
|--------------------------------------------------------------------------
*/

it('flags a parcel that has gone a week without resolving as stuck, and goes on asking about it', function (CourierScript $script) {
    [, $movingWord] = stillMoving($script);

    $shipment = parcelInFlight($script);

    $script->fakeTracking($movingWord);

    $this->travel(CourierSweep::STUCK_AFTER_SECONDS - 60)->seconds();

    $this->artisan('couriers:sweep')
        ->expectsOutputToContain('Flagged 0 stuck parcels')
        ->assertSuccessful();

    expect($shipment->refresh()->stuck_at)->toBeNull();

    $this->travel(61)->seconds();

    $this->artisan('couriers:sweep')
        ->expectsOutputToContain('Flagged 1 stuck parcel')
        ->assertSuccessful();

    /** Told about, never given up on: being stuck is a thing to put in front of a User, not a reason to stop looking. */
    expect($shipment->refresh()->stuck_at)->not->toBeNull()
        ->and($shipment->isActive())->toBeTrue();

    $this->travel($script->reAskIntervalSeconds() + 1)->seconds();

    $this->artisan('couriers:sweep')
        ->expectsOutputToContain('Asked about 1 parcel')
        ->assertSuccessful();
})->with('courier scripts');

it('flags a parcel as stuck once and never again', function (CourierScript $script) {
    [, $movingWord] = stillMoving($script);

    $shipment = parcelInFlight($script);

    $script->fakeTracking($movingWord);

    $this->travel(CourierSweep::STUCK_AFTER_SECONDS + 60)->seconds();

    $this->artisan('couriers:sweep')->assertSuccessful();

    $flaggedAt = $shipment->refresh()->stuck_at;

    expect($flaggedAt)->not->toBeNull();

    $this->travel(2)->days();

    $this->artisan('couriers:sweep')
        ->expectsOutputToContain('Flagged 0 stuck parcels')
        ->assertSuccessful();

    expect($shipment->refresh()->stuck_at->timestamp)->toBe($flaggedAt->timestamp);
})->with('courier scripts');

it('gives up asking about a parcel booked more than a month ago, without writing it off', function (CourierScript $script) {
    [, $movingWord] = stillMoving($script);

    $shipment = parcelInFlight($script);

    $script->fakeTracking($movingWord);

    $this->travel(CourierSweep::GIVE_UP_AFTER_SECONDS + 60)->seconds();

    $this->artisan('couriers:sweep')
        ->expectsOutputToContain('Asked about 0 parcels')
        ->assertSuccessful();

    $script->assertPollsSent(0);

    /**
     * Still Active and still counted in what the couriers are holding: only a
     * User writing an attempt off may say a parcel is dead, and that is a
     * time and a name rather than anything a sweep does (ADR-0018).
     */
    expect($shipment->refresh()->isActive())->toBeTrue()
        ->and($shipment->abandoned_at)->toBeNull();
})->with('courier scripts');

/*
|--------------------------------------------------------------------------
| The schedule
|--------------------------------------------------------------------------
*/

it('runs on the schedule beside the payment and tracking sweeps, and never two at once', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains($event->command ?? '', 'couriers:sweep'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('walks the courier registry rather than a list of its own', function () {
    Http::fake();

    $command = $this->artisan('couriers:sweep');

    /** Nothing is supplied on a fresh installation, so every Courier the enum holds is passed over by name (ADR-0013). */
    foreach (Courier::cases() as $courier) {
        $command->expectsOutputToContain($courier->refusal());
    }

    $command->assertSuccessful();

    Http::assertNothingSent();
});
