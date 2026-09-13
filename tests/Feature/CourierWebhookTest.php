<?php

use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\Couriers\CourierScript;

/**
 * An Announcement is a Courier telling the system, on its own, that something
 * happened to a parcel. Nothing it claims about state is believed: it names
 * the attempt, our own outbound call decides, and a line of prose with no
 * state behind it is written as text and moves nothing (ADR-0019).
 *
 * Every case runs once per announcing Courier over
 * `tests/Datasets/CourierScripts.php`. Which couriers those are is the
 * script's own answer and never a name here, and the one that announces
 * nothing is proved untouched by the same suite.
 */

/*
|--------------------------------------------------------------------------
| A claim sends the system to ask the Courier itself
|--------------------------------------------------------------------------
*/

it('asks the courier itself when a webhook says a parcel moved, and records what that call answered', function (CourierScript $script) {
    [$moving, $movingWord] = stillMoving($script);

    $shipment = parcelInFlight($script);

    $script->fakeTracking($movingWord);

    $this->post(announcementUrl($script), $script->statusAnnouncement($shipment->invoice_reference, $movingWord))->assertOk();

    /** Exactly one outbound question, and it was about this parcel by the name its Courier knows it under. */
    $script->assertPollsSent(1);
    $script->assertTrackedFor($shipment->refresh());

    expect($shipment->status)->toBe($moving)
        ->and($shipment->raw_status)->toBe($movingWord)
        ->and($shipment->polled_at)->not->toBeNull()
        ->and($shipment->events()->where('raw_status', $movingWord)->count())->toBeOne();
})->with('announcing couriers');

it('never writes what the webhook claims: a forged delivery leaves the parcel where the courier says it is', function (CourierScript $script) {
    [$moving, $movingWord] = stillMoving($script);

    $shipment = parcelInFlight($script);

    /** The Courier, asked, says the parcel is still on its way. */
    $script->fakeTracking($movingWord);

    $forged = $script->rawStatusesFor(ShipmentStatus::Delivered)[0];

    $this->post(announcementUrl($script), $script->statusAnnouncement($shipment->invoice_reference, $forged))->assertOk();

    $shipment->refresh();

    /** The claimed word reached neither the Shipment nor its timeline, and the parcel is still Active. */
    expect($shipment->status)->toBe($moving)
        ->and($shipment->raw_status)->toBe($movingWord)
        ->and($shipment->isActive())->toBeTrue()
        ->and($shipment->events()->where('raw_status', $forged)->exists())->toBeFalse()
        ->and($shipment->events()->where('status', ShipmentStatus::Delivered)->exists())->toBeFalse();
})->with('announcing couriers');

/*
|--------------------------------------------------------------------------
| Narration: text on the timeline, and nothing else
|--------------------------------------------------------------------------
*/

it('writes a line of narration and moves nothing at all when a webhook carries only a message', function (CourierScript $script) {
    $shipment = parcelInFlight($script);

    $before = $shipment->status;

    Http::fake();

    $this->post(announcementUrl($script), $script->narrationAnnouncement($shipment->invoice_reference))->assertOk();

    /** Not worth a call: there is nothing to ask about a sentence, which is the whole reason it is believed. */
    Http::assertNothingSent();

    $event = $shipment->events()->get()->last();

    expect($event->raw_status)->toBe($script->narrationWording())

        /** The row takes the state the parcel was already in — narration says where it stood, never that it moved. */
        ->and($event->status)->toBe($before)
        ->and($event->occurred_at)->not->toBeNull();

    $shipment->refresh();

    /** No canonical status, no figure, no `polled_at`, and the parcel is still worth waiting on. */
    expect($shipment->status)->toBe($before)
        ->and($shipment->raw_status)->toBe($script->rawStatusesFor(ShipmentStatus::Booked)[0])
        ->and($shipment->polled_at)->toBeNull()
        ->and($shipment->isActive())->toBeTrue();
})->with('announcing couriers');

/*
|--------------------------------------------------------------------------
| Heard, whatever it named and whatever became of the call
|--------------------------------------------------------------------------
*/

it('ignores and logs a webhook naming no parcel it holds, and answers 200', function (CourierScript $script) {
    $shipment = parcelInFlight($script);

    Http::fake();
    Log::spy();

    $this->post(announcementUrl($script), $script->statusAnnouncement('GIO-9999-9', $script->returnedWording()))->assertOk();

    /** Not asked about: there is nothing of ours for the answer to be written onto. */
    Http::assertNothingSent();

    /** The fields it named, and never the body it arrived in. */
    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $fields): bool => str_contains($message, 'no parcel')
            && $fields === [
                'courier' => $script->courier()->value,
                'invoice_reference' => 'GIO-9999-9',
                'claimed_status' => $script->returnedWording(),
            ],
    );

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::Booked)
        ->and($shipment->events()->count())->toBe(0);
})->with('announcing couriers');

it('ignores a webhook naming a parcel another courier is carrying, and answers 200', function (CourierScript $script) {
    $shipment = parcelInFlight($script);

    /** The same reference, posted to a Courier that is not the one holding it. */
    $elsewhere = collect(courierScripts())
        ->first(fn (CourierScript $other): bool => $other->courier() !== $script->courier() && $other->supportsWebhook());

    $elsewhere->makeUsable();

    Http::fake();

    $this->post(announcementUrl($elsewhere), $elsewhere->statusAnnouncement($shipment->invoice_reference, $elsewhere->returnedWording()))->assertOk();

    Http::assertNothingSent();

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::Booked)
        ->and($shipment->events()->count())->toBe(0);
})->with('announcing couriers');

it('answers 200 and reports the failure when our own call to the courier throws', function (CourierScript $script) {
    $shipment = parcelInFlight($script);

    Exceptions::fake();

    Http::fake(fn () => throw new RuntimeException('The driver fell over.'));

    $this->post(announcementUrl($script), $script->statusAnnouncement($shipment->invoice_reference, $script->returnedWording()))->assertOk();

    /** Reported and not returned: the Courier is told to stop retrying, and somebody still hears about this. */
    Exceptions::assertReported(fn (RuntimeException $failure): bool => $failure->getMessage() === 'The driver fell over.');

    /** Nothing concluded, so the sweep asks again. */
    expect($shipment->refresh()->status)->toBe(ShipmentStatus::Booked)
        ->and($shipment->polled_at)->toBeNull()
        ->and($shipment->events()->count())->toBe(0);
})->with('announcing couriers');

it('answers 404 at an address no courier answers to', function () {
    $this->post('/courier/nonesuch/notify', [])->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Told twice
|--------------------------------------------------------------------------
*/

it('writes one timeline entry however many times the same webhook arrives', function (CourierScript $script) {
    [, $movingWord] = stillMoving($script);

    $shipment = parcelInFlight($script);

    Http::fake();

    $narration = $script->narrationAnnouncement($shipment->invoice_reference);

    $this->post(announcementUrl($script), $narration)->assertOk();
    $this->post(announcementUrl($script), $narration)->assertOk();

    expect($shipment->events()->where('raw_status', $script->narrationWording())->count())->toBeOne();

    answeringAfresh();
    $script->fakeTracking($movingWord);

    $claim = $script->statusAnnouncement($shipment->invoice_reference, $movingWord);

    $this->post(announcementUrl($script), $claim)->assertOk();

    $entries = $shipment->events()->count();

    $this->post(announcementUrl($script), $claim)->assertOk();

    /** Asked twice, because a claim is always worth a call — and the second answer wrote nothing new. */
    $script->assertPollsSent(2);

    expect($shipment->events()->count())->toBe($entries);
})->with('announcing couriers');

/*
|--------------------------------------------------------------------------
| The address itself
|--------------------------------------------------------------------------
*/

it('sits outside request-forgery protection and behind a rate limit', function () {
    $route = Route::getRoutes()->getByName('courier.notify');

    expect($route->methods())->toBe(['POST'])
        ->and($route->excludedMiddleware())->toContain(PreventRequestForgery::class)
        ->and($route->gatherMiddleware())->toContain('throttle:courier-announcement');
});

/*
|--------------------------------------------------------------------------
| The courier that announces nothing
|--------------------------------------------------------------------------
*/

it('hears but ignores anything posted at a courier that announces nothing, and goes on polling its parcels', function (CourierScript $script) {
    [$moving, $movingWord] = stillMoving($script);

    $shipment = parcelInFlight($script);

    /** A body in an announcing Courier's own words, so the probe is a real one and this file speaks nobody's vocabulary. */
    $probe = collect(courierScripts())->first(fn (CourierScript $other): bool => $other->supportsWebhook());

    Http::fake();

    /** Its address exists, because every Courier's does; its driver reads nothing off the request. */
    $this->post(
        announcementUrl($script),
        $probe->statusAnnouncement($shipment->invoice_reference, $probe->returnedWording()),
    )->assertOk();

    Http::assertNothingSent();

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::Booked)
        ->and($shipment->events()->count())->toBe(0);

    /** Polling is the only channel this Courier has, and it is exactly as it was. */
    answeringAfresh();
    $script->fakeTracking($movingWord);

    $this->artisan('couriers:sweep')
        ->expectsOutputToContain('Asked about 1 parcel')
        ->assertSuccessful();

    expect(Shipment::sole()->status)->toBe($moving);
})->with('silent couriers');
