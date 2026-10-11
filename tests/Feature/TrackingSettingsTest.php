<?php

use App\Enums\TrackingProvider;
use App\Filament\Clusters\Settings\Pages\Tracking;
use App\Models\TrackingEvent;
use App\Models\User;
use App\Support\Orders\OrderOwnership;
use App\Support\Settings\TrackingSettings;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
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
    $script->switchOn();

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

    expect($script->carriesBaseCode($html))->toBeTrue()
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
    $script->switchOn();

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

it('switches facebook on with its dataset and token, after which the store reports there', function () {
    Livewire::test(Tracking::class)
        ->assertFormSet(['facebookEnabled' => false])
        ->fillForm([
            'facebookEnabled' => true,
            'facebookDataset' => '110022003300440',
            'facebookAccessToken' => 'EAA-very-secret-token',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(TrackingSettings::class);

    expect($settings->enabledProviders())->toBe([TrackingProvider::Facebook])
        ->and(TrackingProvider::Facebook->driver()->refusal())->toBeNull();

    /** The dataset stays readable in SQL; only the credential does not. */
    $row = DB::table('settings')->where('key', 'tracking')->sole()->value;

    expect($row)->toContain('110022003300440')
        ->and($row)->not->toContain('EAA-very-secret-token');

    recordCaptureOf(orderAsCaptured());

    expect(TrackingEvent::sole()->provider)->toBe(TrackingProvider::Facebook);
});

it('loads the token blank with a hint, keeps it when left blank, and replaces it when one is typed', function (ProviderScript $script) {
    $script->switchOn();

    $token = TrackingSettings::property($script->provider(), 'AccessToken');

    Livewire::test(Tracking::class)
        ->assertSet("data.{$token}", null)
        ->assertSee('A value is stored')
        ->assertDontSee($script->accessToken())
        ->fillForm([$token => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(TrackingSettings::class)->{$token})->toBe($script->accessToken());

    Livewire::test(Tracking::class)
        ->fillForm([$token => 'EAA-rotated-token'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(TrackingSettings::class)->{$token})->toBe('EAA-rotated-token');
})->with('tracking providers');

it('says why a switched-on provider cannot be reported to, in the words the sweep uses', function (ProviderScript $script) {
    $script->switchOn();
    $script->withoutAccessToken();

    $refusal = $script->provider()->driver()->refusal();

    expect($refusal)->toBe($script->provider()->getLabel().' has no access token in Settings.');

    Livewire::test(Tracking::class)->assertSee($refusal);

    /** Beside an Off switch, "switched off" would tell nobody anything. */
    $script->switchOff();

    Livewire::test(Tracking::class)->assertDontSee($script->provider()->driver()->refusal());
})->with('tracking providers');

it('names a missing dataset in the providers own word for it', function (ProviderScript $script) {
    app(TrackingSettings::class)->with([TrackingSettings::property($script->provider(), 'Enabled') => true])->save();

    expect($script->provider()->driver()->refusal())
        ->toBe($script->provider()->getLabel().' has no '.$script->provider()->datasetLabel().' in Settings.');
})->with('tracking providers');
