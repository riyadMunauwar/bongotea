<?php

use App\Enums\TrackingProvider;
use App\Filament\Clusters\Settings\Pages\Tracking;
use App\Models\Destination;
use App\Models\TrackingEvent;
use App\Models\User;
use App\Support\Orders\OrderOwnership;
use App\Support\Settings\TrackingSettings;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Fixtures\Tracking\ProviderScript;

/**
 * What the store reports, decided once in Settings.
 *
 * The page is driven through the real screen and what it decides is asserted
 * where it lands: on the outbox row a real Capture writes, and in the Pixel
 * a real Order Complete Page carries.
 */
beforeEach(function (): void {
    $this->actingAs(User::factory()->managingSettings()->create());
});

it('holds the store-wide switch for every event a provider declares, and the beacon rate limit', function () {
    Livewire::test(Tracking::class)
        ->assertOk()
        ->assertFormSet([
            'eventDefaults.facebook.PageView' => true,
            'eventDefaults.facebook.Purchase' => true,
            'beaconRateLimit' => 300,
        ])
        ->fillForm([
            'eventDefaults' => ['facebook' => ['ViewContent' => false]],
            'beaconRateLimit' => 5,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(TrackingSettings::class);

    expect($settings->reportsByDefault(TrackingProvider::Facebook, 'ViewContent'))->toBeFalse()
        ->and($settings->reportsByDefault(TrackingProvider::Facebook, 'PageView'))->toBeTrue()
        ->and($settings->beaconRateLimit)->toBe(5);

    /** The row stays readable in SQL, which is the whole of what a settings group is for. */
    expect(DB::table('settings')->where('key', 'tracking')->sole()->value)->toContain('ViewContent');
});

it('stops reporting an event the store switched off, in both halves', function (ProviderScript $script) {
    $script->makeDestination();

    app(TrackingSettings::class)->with([
        'eventDefaults' => [$script->provider()->value => [$script->purchaseEvent() => false]],
    ])->save();

    $order = orderAsCaptured();
    recordCaptureOf($order);

    /** No server copy of the Purchase… */
    expect(TrackingEvent::count())->toBe(0);

    /** …and no browser copy, while the base code still reports the page load. */
    $html = $this->withCookie(OrderOwnership::COOKIE, (string) json_encode([$order->order_number]))
        ->get(route('checkout.order-complete', $order->order_number))
        ->assertOk()
        ->getContent();

    expect($script->carriesBaseCodeFor(Destination::sole(), $html))->toBeTrue()
        ->and(array_map($script->eventNameOf(...), $script->browserEventsIn($html)))->toBe([$script->pageViewEvent()]);
})->with('tracking providers');

it('refuses a beacon past the rate limit the merchant set', function () {
    app(TrackingSettings::class)->with(['beaconRateLimit' => 1])->save();

    reportToBeacon(identifier: 'first')->assertNoContent();
    reportToBeacon(identifier: 'second')->assertTooManyRequests();
});

it('reads a tracking settings row holding nothing of the shape it promises without throwing', function () {
    DB::table('settings')->insert(['key' => 'tracking', 'value' => json_encode(['eventDefaults' => 'everything', 'beaconRateLimit' => null])]);

    $settings = app(TrackingSettings::class);

    /** A row is the one input that must never throw: a TypeError from load() would take down every request. */
    expect($settings->eventDefaults)->toBe([])
        ->and($settings->reportsByDefault(TrackingProvider::Facebook, 'PageView'))->toBeTrue()

        /** And a limit of nothing reads as the default, because a limit of nothing is the beacon switched off for everybody. */
        ->and($settings->beaconRateLimit)->toBe(300);
});

it('keeps a switch for an event the provider no longer declares, for the day it comes back', function () {
    $settings = app(TrackingSettings::class)->with(['eventDefaults' => ['facebook' => ['Retired' => false, 'Purchase' => true]]]);

    expect($settings->reportsByDefault(TrackingProvider::Facebook, 'Purchase'))->toBeTrue()
        ->and($settings->eventDefaults['facebook'])->toBe(['Retired' => false, 'Purchase' => true]);
});

it('names the products bought until the store says not to, and then withholds them from both halves', function (ProviderScript $script) {
    $script->makeDestination();

    Livewire::test(Tracking::class)
        ->assertFormSet(['namesProducts' => true])
        ->fillForm(['namesProducts' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    $order = orderAsCaptured();
    recordCaptureOf($order);

    expect(TrackingEvent::sole()->content_detail)->toBeFalse();

    $script->fakeAccepted();
    $this->artisan('tracking:sweep')->assertSuccessful();

    /** The server copy still says what the Order was worth, and nothing about what was in it. */
    $sent = $script->sentEvents()[0];

    expect($script->contentsOf($sent))->toBe([])
        ->and($script->valueOf($sent))->not->toBeNull();

    $html = $this->withCookie(OrderOwnership::COOKIE, (string) json_encode([$order->order_number]))
        ->get(route('checkout.order-complete', $order->order_number))
        ->assertOk()
        ->getContent();

    $purchase = collect($script->browserEventsIn($html))
        ->first(fn (array $event): bool => $script->eventNameOf($event) === $script->purchaseEvent());

    expect($script->contentsOf($purchase))->toBe([]);
})->with('tracking providers');

it('chooses each provider\'s default destination, which is where the store then reports', function (ProviderScript $script) {
    $first = $script->makeDestination();
    $second = $script->makeDestination();
    $provider = $script->provider()->value;

    Livewire::test(Tracking::class)
        ->assertFormSet(["defaultDestinations.{$provider}" => $first->getKey()])
        ->fillForm(["defaultDestinations.{$provider}" => $second->getKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($second->refresh()->is_default)->toBeTrue()
        ->and($first->refresh()->is_default)->toBeFalse()
        ->and(Activity::query()->where('subject_id', $second->getKey())->sole()->description)
        ->toBe("Made {$second->label} the default {$script->provider()->getLabel()} Destination");

    recordCaptureOf(orderAsCaptured());

    expect(TrackingEvent::sole()->destination_id)->toBe($second->getKey());

    /** The choice is the Destination's own flag, and never a second copy of it in the settings row. */
    expect(DB::table('settings')->where('key', 'tracking')->sole()->value)->not->toContain('defaultDestinations');
})->with('tracking providers');

it('refuses a default destination that is not one of the provider\'s', function () {
    Destination::factory()->create();

    Livewire::test(Tracking::class)
        ->fillForm(['defaultDestinations.facebook' => 999])
        ->call('save')
        ->assertHasFormErrors(['defaultDestinations.facebook']);
});
