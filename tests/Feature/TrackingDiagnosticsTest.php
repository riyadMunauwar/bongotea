<?php

use App\Enums\TrackingEventState;
use App\Filament\Resources\Destinations\DestinationResource;
use App\Filament\Resources\Destinations\Widgets\DeliveryHealthStats;
use App\Filament\Resources\Destinations\Widgets\RecentDeliveryFailures;
use App\Models\TrackingEvent;
use App\Models\User;
use App\Support\Tracking\DeliveryHealth;
use App\Support\Tracking\TrackingPrune;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Fixtures\Tracking\ProviderScript;

/**
 * Whether an operator can tell that reporting is healthy.
 *
 * Driven through the real screens against real outbox rows. The figure the
 * screen exists for is the last one — when the sweep last ran — because a
 * scheduler that was never set up shows up nowhere else: the events do not
 * vanish, they simply never leave, and every other figure looks like a quiet
 * week.
 */
beforeEach(function (): void {
    $this->actingAs(User::factory()->managingSettings()->create());
});

it('draws the delivery figures and the failures beside the destinations they belong to', function (ProviderScript $script) {
    $script->makeDestination();

    $this->get(DestinationResource::getUrl('index'))
        ->assertOk()
        ->assertSeeLivewire(DeliveryHealthStats::class)
        ->assertSeeLivewire(RecentDeliveryFailures::class);
})->with('tracking providers');

it('says nothing about delivery until the merchant has somewhere to deliver to', function () {
    $this->get(DestinationResource::getUrl('index'))
        ->assertOk()
        ->assertDontSeeLivewire(DeliveryHealthStats::class)
        ->assertDontSeeLivewire(RecentDeliveryFailures::class);
});

it('counts what is waiting and what never arrived, and names the oldest thing in the queue', function (ProviderScript $script) {
    $destination = $script->makeDestination();

    TrackingEvent::factory()->count(2)->create([
        'destination_id' => $destination->getKey(),
        'occurred_at' => now()->subDays(2),
    ]);

    TrackingEvent::factory()->create([
        'destination_id' => $destination->getKey(),
        'deduplication_id' => 'GIO-9001',
        'state' => TrackingEventState::Failed,
        'last_error' => 'Invalid OAuth access token.',
    ]);

    TrackingEvent::factory()->count(2)->create([
        'destination_id' => $destination->getKey(),
        'deduplication_id' => fn (): string => 'GIO-'.fake()->unique()->numerify('#####'),
        'state' => TrackingEventState::Expired,
    ]);

    Livewire::test(DeliveryHealthStats::class)
        ->assertSee('Waiting to send')
        ->assertSee('Oldest happened 2 days ago')
        ->assertSee('Failed')
        /** The tile counts the whole of what never arrived, as the table below it lists. */
        ->assertSee('1 refused by the Provider, 2 too old to accept');
})->with('tracking providers');

it('says nothing has failed rather than drawing a bare nought', function (ProviderScript $script) {
    $script->makeDestination();

    Livewire::test(DeliveryHealthStats::class)
        ->assertSee('Nothing has failed')
        ->assertSee('Nothing waiting');
})->with('tracking providers');

it('lists a refused event with what the provider actually said', function (ProviderScript $script) {
    $destination = $script->makeDestination();

    TrackingEvent::factory()->create(['destination_id' => $destination->getKey()]);

    $script->fakeRefused('Invalid OAuth access token.');

    $this->artisan('tracking:sweep')->assertSuccessful();

    Livewire::test(RecentDeliveryFailures::class)
        ->assertCanSeeTableRecords(TrackingEvent::all())
        ->assertSee($destination->label)
        ->assertSee('Invalid OAuth access token.');
})->with('tracking providers');

it('lists an event that grew too old to be accepted, beside the ones that were refused', function (ProviderScript $script) {
    $destination = $script->makeDestination();

    TrackingEvent::factory()->create([
        'destination_id' => $destination->getKey(),
        'deduplication_id' => 'GIO-9002',
        'occurred_at' => now()->subSeconds($script->acceptanceWindowSeconds())->subHour(),
    ]);

    $script->fakeAccepted();

    $this->artisan('tracking:sweep')->assertSuccessful();

    Livewire::test(RecentDeliveryFailures::class)
        ->assertCanSeeTableRecords(TrackingEvent::all())
        ->assertSee('Expired')
        ->assertSee('Older than what');
})->with('tracking providers');

it('leaves a delivered event out of the failures, because nothing went wrong with it', function (ProviderScript $script) {
    $destination = $script->makeDestination();

    TrackingEvent::factory()->create(['destination_id' => $destination->getKey()]);

    $script->fakeAccepted();

    $this->artisan('tracking:sweep')->assertSuccessful();

    Livewire::test(RecentDeliveryFailures::class)
        ->assertCanNotSeeTableRecords(TrackingEvent::all())
        ->assertSee('Nothing has failed');
})->with('tracking providers');

it('keeps its own page number, so paging the destinations list never blanks the failures under it', function (ProviderScript $script) {
    $destination = $script->makeDestination();

    $failure = TrackingEvent::factory()->create([
        'destination_id' => $destination->getKey(),
        'state' => TrackingEventState::Failed,
        'last_error' => 'Invalid OAuth access token.',
    ]);

    /**
     * What the Destinations table above leaves behind when it is paged.
     * Shared, this table would ask for a page it does not have and Filament
     * would draw the empty state — a green tick over a list of failures.
     */
    Livewire::withQueryParams(['page' => 4])
        ->test(RecentDeliveryFailures::class)
        ->assertCanSeeTableRecords([$failure])
        ->assertDontSee('Nothing has failed');
})->with('tracking providers');

it('answers a page past the end with the last page there is, never with the empty state', function (ProviderScript $script) {
    $destination = $script->makeDestination();

    $failure = TrackingEvent::factory()->create([
        'destination_id' => $destination->getKey(),
        'state' => TrackingEventState::Failed,
        'last_error' => 'Invalid OAuth access token.',
    ]);

    /**
     * What a nightly prune leaves behind under an operator sitting on a later
     * page. Unclamped, Filament draws the empty state for a page holding no
     * rows however large the total — a green tick over a list of failures.
     */
    Livewire::withQueryParams(['failuresPage' => 6])
        ->test(RecentDeliveryFailures::class)
        ->assertCanSeeTableRecords([$failure])
        ->assertDontSee('Nothing has failed');
})->with('tracking providers');

it('lists only as far back as it says it keeps, even where the prune is the cron that is broken', function (ProviderScript $script) {
    $destination = $script->makeDestination();

    $settled = now()->subDays(TrackingPrune::DIAGNOSTIC_RETENTION_DAYS)->subDay();

    TrackingEvent::factory()->create([
        'destination_id' => $destination->getKey(),
        'state' => TrackingEventState::Failed,
        'last_error' => 'Invalid OAuth access token.',
        'created_at' => $settled,
        'updated_at' => $settled,
    ]);

    Livewire::test(RecentDeliveryFailures::class)
        ->assertSee('Nothing has failed');

    expect(app(DeliveryHealth::class)->undelivered())->toBe(['total' => 0, 'refused' => 0, 'expired' => 0]);
})->with('tracking providers');

it('records that the sweep ran even when there was nothing at all to send', function (ProviderScript $script) {
    $script->makeDestination();

    Http::fake();

    expect(app(DeliveryHealth::class)->lastSweptAt())->toBeNull();

    $this->artisan('tracking:sweep')->assertSuccessful();

    expect(app(DeliveryHealth::class)->lastSweptAt())->not->toBeNull()
        ->and(app(DeliveryHealth::class)->isStale())->toBeFalse();

    Livewire::test(DeliveryHealthStats::class)
        ->assertSee('Last swept')
        ->assertSee('Delivering every five minutes');
})->with('tracking providers');

it('says the scheduler may not be running when no sweep has ever been recorded', function (ProviderScript $script) {
    $script->makeDestination();

    Livewire::test(DeliveryHealthStats::class)
        ->assertSee('Never')
        ->assertSee('The scheduler may not be running');
})->with('tracking providers');

it('says so again once the sweep has been quiet for longer than a few missed runs', function (ProviderScript $script) {
    $script->makeDestination();

    Http::fake();
    $this->artisan('tracking:sweep')->assertSuccessful();

    $this->travel(DeliveryHealth::STALE_AFTER_SECONDS + 60)->seconds();

    expect(app(DeliveryHealth::class)->isStale())->toBeTrue();

    Livewire::test(DeliveryHealthStats::class)
        ->assertSee('The scheduler may not be running');
})->with('tracking providers');
