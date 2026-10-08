<?php

use App\Enums\TrackingEventState;
use App\Models\Destination;
use App\Models\Order;
use App\Models\TrackingContext;
use App\Models\TrackingEvent;
use App\Models\VisitCount;
use App\Support\Tracking\TrackingPrune;
use Illuminate\Console\Scheduling\Schedule;
use Tests\Fixtures\Tracking\ProviderScript;

/**
 * Where tracking data stops accumulating, and everything that must survive
 * the pruning of it.
 *
 * Every test here runs the real command against real rows. The two that
 * matter most are not about deletion at all: nothing Pending may ever be
 * removed, because a broken scheduler must cost a Merchant a delay and never
 * an event, and the Visit aggregate must come through untouched, because it
 * is the Conversion Rate history a Merchant reads next month.
 */
it('deletes a delivered event once it is past its retention, and keeps one inside it', function (ProviderScript $script) {
    $destination = $script->makeDestination();

    $kept = sentEvent($destination, now()->subDays(TrackingPrune::SENT_RETENTION_DAYS)->addHour());
    $gone = sentEvent($destination, now()->subDays(TrackingPrune::SENT_RETENTION_DAYS)->subHour());

    $this->artisan('tracking:prune')
        ->expectsOutputToContain('Deleted 1 delivered event')
        ->assertSuccessful();

    expect(TrackingEvent::query()->whereKey($kept->getKey())->exists())->toBeTrue()
        ->and(TrackingEvent::query()->whereKey($gone->getKey())->exists())->toBeFalse();
})->with('tracking providers');

it('keeps a refused event and an expired one for the longer diagnostic retention', function (ProviderScript $script) {
    $destination = $script->makeDestination();

    /** Past what a delivered row gets, and well inside what a failure gets. */
    $justSettled = now()->subDays(TrackingPrune::SENT_RETENTION_DAYS)->subDay();

    $refused = settledEvent($destination, TrackingEventState::Failed, $justSettled);
    $expired = settledEvent($destination, TrackingEventState::Expired, $justSettled);

    $this->artisan('tracking:prune')
        ->expectsOutputToContain('Deleted 0 undelivered events')
        ->assertSuccessful();

    expect(TrackingEvent::query()->whereKey([$refused->getKey(), $expired->getKey()])->count())->toBe(2);
})->with('tracking providers');

it('deletes a refused event and an expired one once even the diagnostics are stale', function (ProviderScript $script) {
    $destination = $script->makeDestination();

    $longSettled = now()->subDays(TrackingPrune::DIAGNOSTIC_RETENTION_DAYS)->subHour();

    settledEvent($destination, TrackingEventState::Failed, $longSettled);
    settledEvent($destination, TrackingEventState::Expired, $longSettled);

    $this->artisan('tracking:prune')
        ->expectsOutputToContain('Deleted 2 undelivered events')
        ->assertSuccessful();

    expect(TrackingEvent::query()->count())->toBe(0);
})->with('tracking providers');

it('never deletes a waiting event, however long the scheduler has been broken', function (ProviderScript $script) {
    $destination = $script->makeDestination();

    TrackingEvent::factory()->count(3)->create([
        'destination_id' => $destination->getKey(),
        'state' => TrackingEventState::Pending,
        'occurred_at' => now()->subMonths(3),
        'created_at' => now()->subMonths(3),
        'updated_at' => now()->subMonths(3),
    ]);

    $this->artisan('tracking:prune')->assertSuccessful();

    expect(TrackingEvent::query()->where('state', TrackingEventState::Pending)->count())->toBe(3);
})->with('tracking providers');

it('leaves a merchant whose cron was broken with every event still waiting, and sends all of them once it is fixed', function (ProviderScript $script) {
    $destination = $script->makeDestination();

    /** A day of reporting nobody delivered, still inside what the Provider accepts. */
    foreach (range(1, 4) as $index) {
        TrackingEvent::factory()->create([
            'destination_id' => $destination->getKey(),
            'deduplication_id' => "GIO-100{$index}",
            'occurred_at' => now()->subDay(),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
    }

    $this->artisan('tracking:prune')->assertSuccessful();

    expect(TrackingEvent::query()->where('state', TrackingEventState::Pending)->count())->toBe(4);

    $script->fakeAccepted();

    $this->artisan('tracking:sweep')
        ->expectsOutputToContain('Sent 4 tracking events')
        ->assertSuccessful();

    expect(TrackingEvent::query()->where('state', TrackingEventState::Sent)->count())->toBe(4);
})->with('tracking providers');

it('deletes a tracking context once no provider would still accept the moment it was kept for', function (ProviderScript $script) {
    $window = $script->acceptanceWindowSeconds();

    $kept = TrackingContext::factory()->create([
        'order_id' => Order::factory(),
        'created_at' => now()->subSeconds($window)->addHour(),
    ]);

    $gone = TrackingContext::factory()->create([
        'order_id' => Order::factory(),
        'created_at' => now()->subSeconds($window)->subHour(),
    ]);

    $this->artisan('tracking:prune')
        ->expectsOutputToContain('Deleted 1 tracking context')
        ->assertSuccessful();

    expect(TrackingContext::query()->whereKey($kept->getKey())->exists())->toBeTrue()
        ->and(TrackingContext::query()->whereKey($gone->getKey())->exists())->toBeFalse();
})->with('tracking providers');

it('holds the browsers word for exactly as long as a provider would still accept an event built from it', function (ProviderScript $script) {
    expect(TrackingPrune::contextRetentionSeconds())->toBeGreaterThanOrEqual($script->acceptanceWindowSeconds());
})->with('tracking providers');

it('keeps the order a pruned tracking context belonged to, because tracking never touches a fulfilment record', function () {
    $context = TrackingContext::factory()->create(['created_at' => now()->subMonth()]);
    $order = $context->order;

    $this->artisan('tracking:prune')->assertSuccessful();

    expect(TrackingContext::query()->count())->toBe(0)
        ->and(Order::query()->whereKey($order->getKey())->exists())->toBeTrue();
});

it('never touches the visit aggregate, so last months conversion rate is still there next month', function () {
    VisitCount::query()->create(['visited_on' => '2026-01-01', 'visits' => 4200]);
    VisitCount::query()->create(['visited_on' => now()->toDateString(), 'visits' => 7]);

    /** The rows a rollup would have been tempted to derive it from, all prunable. */
    $destination = Destination::factory()->create();

    settledEvent($destination, TrackingEventState::Sent, now()->subMonths(2));

    $this->artisan('tracking:prune')->assertSuccessful();

    expect(TrackingEvent::query()->count())->toBe(0)
        ->and(VisitCount::query()->count())->toBe(2)
        ->and(VisitCount::query()->where('visited_on', '2026-01-01')->value('visits'))->toBe(4200);
});

it('is scheduled once a day, and never runs two at once', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains($event->command ?? '', 'tracking:prune'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('10 3 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

/**
 * One delivered outbox row, settled at the given instant — which is the age
 * the prune cuts on.
 */
function sentEvent(Destination $destination, DateTimeInterface $settledAt): TrackingEvent
{
    return settledEvent($destination, TrackingEventState::Sent, $settledAt);
}

/**
 * One outbox row the sweep has finished with, settled at the given instant.
 * A unique deduplication id per row, because the outbox refuses two of the
 * same event for one Destination.
 */
function settledEvent(Destination $destination, TrackingEventState $state, DateTimeInterface $settledAt): TrackingEvent
{
    return TrackingEvent::factory()->create([
        'destination_id' => $destination->getKey(),
        'deduplication_id' => 'GIO-'.fake()->unique()->numerify('######'),
        'state' => $state,
        'sent_at' => $state === TrackingEventState::Sent ? $settledAt : null,
        'occurred_at' => $settledAt,
        'created_at' => $settledAt,
        'updated_at' => $settledAt,
    ]);
}
