<?php

use App\Enums\TrackingChoice;
use App\Enums\TrackingMoment;
use App\Enums\TrackingProvider;
use App\Filament\Clusters\Settings\Pages\Tracking;
use App\Filament\Resources\Pages\Pages\CreatePage;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Models\Destination;
use App\Models\Order;
use App\Models\Page;
use App\Models\Product;
use App\Models\TrackingEvent;
use App\Models\User;
use App\Support\Checkout\BuiltInField;
use App\Support\Checkout\CheckoutConfig;
use App\Support\Checkout\PricingFingerprint;
use App\Support\Orders\OrderOwnership;
use App\Support\Settings\TrackingSettings;
use App\Support\Tracking\TrackingConfig;
use App\Support\Tracking\TrackingSnippet;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Fixtures\Tracking\FacebookScript;
use Tests\Fixtures\Tracking\ProviderScript;

/**
 * A Merchant deciding, per Page, where it reports and what it reports there.
 *
 * The tab is driven through the real screen and everything it decides is
 * asserted where it lands: on the outbox row a real Capture writes, in the
 * bytes a real visitor is served, and in the payload the sweep actually
 * sends. Nothing here reaches into a Config or a Plan to ask it what it
 * thinks.
 */
beforeEach(function (): void {
    $this->actingAs(User::factory()->managingSettings()->authoringPages()->create());
});

/** The Tracking tab of a Page's edit form, as the screen builds it. */
function trackingTabOf(Page $page): Tab
{
    return collect(tabsInside(editFormOf($page)))
        ->sole(fn (Tab $tab): bool => (string) $tab->getLabel() === 'Tracking');
}

/**
 * The sections on a tab, by heading — nested ones included, since the events
 * are a section per Provider inside the Events section.
 *
 * @return array<string, Section>
 */
function sectionsInside(Component|Tab $component): array
{
    $sections = [];

    foreach ($component->getChildSchema()->getComponents(withHidden: true) as $child) {
        if (! $child instanceof Section) {
            continue;
        }

        $sections[(string) $child->getHeading()] = $child;

        $sections = [...$sections, ...sectionsInside($child)];
    }

    return $sections;
}

/**
 * The browser reporting a load, the way the snippet does it: everything in
 * the address, nothing in the body, and the header a browser sets itself.
 */
function reportLoad(Page $page, string $identifier = 'load-3f9a2c'): TestResponse
{
    $query = http_build_query(['slug' => $page->slug, 'moment' => TrackingMoment::Visit->value, 'id' => $identifier]);

    return test()->post(route('tracking.report').'?'.$query, [], ['Sec-Fetch-Site' => 'same-origin']);
}

/** A published Page selling one Product, reporting to this Provider's Destination. */
function trackedPage(ProviderScript $script, array $config = []): Page
{
    $script->makeDestination();

    $page = sellingPage(Product::factory()->create(['name' => 'Winter Jacket', 'price' => 99000]));

    return $config === [] ? $page : tap($page)->update(['tracking_config' => $config]);
}

it('gives a new page a complete tracking config nobody had to configure', function () {
    Livewire::test(CreatePage::class)
        ->fillForm([
            'title' => 'Winter Promo',
            'slug' => 'winter-promo',
            'body' => '<h1>Winter</h1>',
            'status' => 'draft',
            'products' => [],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $config = Page::where('slug', 'winter-promo')->sole()->tracking_config;

    /** Seeded on create and never partial: nothing was said, so everything is inherited. */
    expect($config->destinationId)->toBeNull()
        ->and($config->events)->toBe([])
        ->and($config->suppressesContentDetail)->toBeFalse();
});

it('reads a tracking config a page had before the column, or one holding nonsense, as the default', function (string $stored) {
    $page = trackedPage(new FacebookScript);

    DB::table('pages')->where('id', $page->getKey())->update(['tracking_config' => $stored]);

    /** Read totally: a missing key or a value of the wrong shape is nothing said, and never a 500 in front of a Customer. */
    $this->get('/'.$page->slug)->assertOk();

    $config = $page->refresh()->tracking_config;

    expect($config->destinationId)->toBeNull()
        ->and($config->events)->toBe([])
        ->and($config->suppressesContentDetail)->toBeFalse();

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->assertOk()
        ->assertFormSet(['tracking_config.destination_id' => null]);
})->with([
    'nothing stored' => ['{}'],
    'keys nothing knows, and values of the wrong shape' => [
        '{"destination":"brand","destination_id":"nope","events":"all","suppress_content_detail":null}',
    ],
]);

it('asks about tracking on its own tab beside the other three, in the order the answers depend on each other', function () {
    $tab = trackingTabOf(trackedPage(new FacebookScript));

    expect(array_keys(sectionsInside($tab)))->toBe(['Destination', 'Events', 'Facebook', 'What is sent', 'Preview']);

    /** One switch per event the Provider declares, read off the Provider rather than listed on the tab. */
    expect(statePathsInside($tab))->toBe([
        'tracking_config.destination_id',
        'tracking_config.events.facebook.PageView',
        'tracking_config.events.facebook.ViewContent',
        'tracking_config.events.facebook.InitiateCheckout',
        'tracking_config.events.facebook.Purchase',
        'tracking_config.suppress_content_detail',
    ]);

    expect(fieldInside($tab, 'tracking_config.destination_id'))->toBeInstanceOf(Select::class)
        ->and(fieldInside($tab, 'tracking_config.suppress_content_detail'))->toBeInstanceOf(Toggle::class);

    /** Three states, because the useful answer for nearly every event is "whatever the installation decided". */
    $switch = fieldInside($tab, 'tracking_config.events.facebook.Purchase');

    expect($switch)->toBeInstanceOf(ToggleButtons::class)
        ->and($switch->getOptions())->toBe([
            'inherit' => 'Use default',
            'on' => 'On',
            'off' => 'Off',
        ]);
});

it('lists every destination in the picker, says which are switched off, and offers blank as the default', function () {
    $script = new FacebookScript;
    $page = trackedPage($script);

    $paused = Destination::factory()->disabled()->notDefault()->create(['label' => 'Client Ads']);

    $picker = fieldInside(trackingTabOf($page), 'tracking_config.destination_id');

    expect($picker->getOptions())->toBe([
        Destination::query()->defaulted()->sole()->getKey() => 'Brand Ads — Facebook',
        $paused->getKey() => 'Client Ads — Facebook (switched off)',
    ])
        ->and((string) $picker->getPlaceholder())->toBe('Use the default for each Provider');
});

it('reports a page to the destination it picked, and not to the default', function (ProviderScript $script) {
    $page = trackedPage($script);

    $picked = Destination::factory()->notDefault()->create(['label' => 'Client Ads', 'dataset' => '990088007700660']);

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['tracking_config' => ['destination_id' => $picked->getKey()]])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->postJson(route('checkout.store'), capturePayload($page->refresh()))->assertCreated();

    /** The server half reports there, and only there. */
    expect(TrackingEvent::sole()->destination_id)->toBe($picked->getKey());

    /** And so does the browser half, which is the same answer read once. */
    expect($script->carriesBaseCodeFor($picked, $this->get('/'.$page->slug)->getContent()))->toBeTrue();
})->with('tracking providers');

it('falls back to the default for a page pointing at a destination that has been deleted', function (ProviderScript $script) {
    $page = trackedPage($script);
    $picked = Destination::factory()->notDefault()->create(['label' => 'Client Ads']);

    $page->update(['tracking_config' => ['destination_id' => $picked->getKey()]]);

    $picked->delete();

    $this->postJson(route('checkout.store'), capturePayload($page->refresh()))->assertCreated();

    /** A tidy-up in Settings must never silently switch off a live campaign. */
    expect(TrackingEvent::sole()->destination_id)->toBe(Destination::sole()->getKey());
})->with('tracking providers');

it('reports nowhere for a page pointing at a destination the merchant has switched off, because that is what a campaign break is', function () {
    $script = new FacebookScript;
    $page = trackedPage($script);
    $paused = Destination::factory()->disabled()->notDefault()->create();

    $page->update(['tracking_config' => ['destination_id' => $paused->getKey()]]);

    $this->postJson(route('checkout.store'), capturePayload($page->refresh()))->assertCreated();

    $html = $this->get('/'.$page->slug)->getContent();

    /** The pick stands in for its Provider's default, paused or not: the Merchant paused this Page on purpose. */
    expect(TrackingEvent::count())->toBe(0)
        ->and($script->carriesBaseCodeFor($paused, $html))->toBeFalse()
        ->and($html)->not->toContain(TrackingSnippet::CONSENT_HOOK);
});

it('stops reporting an event the page switched off, in both halves', function (ProviderScript $script) {
    $page = trackedPage($script);

    /** The last of the events this Provider's Visit produces, so the test reads in any Provider's vocabulary. */
    $switchedOff = last($script->visitEvents());
    $kept = array_slice($script->visitEvents(), 0, -1);

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['tracking_config' => ['events' => [$script->provider()->value => [$switchedOff => TrackingChoice::Off->value]]]])
        ->call('save')
        ->assertHasNoFormErrors();

    /** Stored as a nullable boolean, so a Provider gaining a fifth event needs no data migration. */
    expect($page->refresh()->tracking_config->choiceFor($script->provider(), $switchedOff))->toBeFalse()
        ->and($page->tracking_config->choiceFor($script->provider(), $script->pageViewEvent()))->toBeNull();

    $html = $this->get('/'.$page->slug)->assertOk()->getContent();

    /** The browser is told to report what is left of the Visit and nothing else. */
    expect(array_map($script->eventNameOf(...), $script->browserEventsIn(onLoad($html))))->toBe($kept);

    /** And the beacon writes the same ones, from the same reading. */
    reportLoad($page)->assertNoContent();

    expect(TrackingEvent::pluck('event')->all())->toBe($kept);
})->with('tracking providers');

/**
 * The base code reports the load of the page it is written into by itself, so
 * a Merchant who switched that event off has to have it withheld there too —
 * otherwise the one switch that is on every Provider's base code would be the
 * one switch that does nothing.
 */
it('withholds the load the base code reports by itself when the page has switched that event off', function (ProviderScript $script) {
    $page = trackedPage($script, ['events' => [$script->provider()->value => [$script->pageViewEvent() => false]]]);

    $withoutTheLoad = array_values(array_diff($script->visitEvents(), [$script->pageViewEvent()]));

    $html = $this->get('/'.$page->slug)->assertOk()->getContent();

    expect($script->carriesBaseCodeFor(Destination::sole(), $html))->toBeTrue()
        ->and(array_map($script->eventNameOf(...), $script->browserEventsIn(onLoad($html))))->toBe($withoutTheLoad);
})->with('tracking providers');

it('carries no beacon at all where the page reports no visit event, and still carries the page it wrote', function (ProviderScript $script) {
    $switchedOff = array_fill_keys($script->visitEvents(), false);

    $page = trackedPage($script, ['events' => [$script->provider()->value => $switchedOff]]);

    $html = $this->get('/'.$page->slug)->assertOk()->getContent();

    /** A request that could only be discarded is not one to put on the path of an ad click. */
    expect($html)->not->toContain('gioBeacon')
        ->and($script->carriesBaseCodeFor(Destination::sole(), $html))->toBeTrue();
})->with('tracking providers');

it('reports an event the installation has switched off only where the page switches it back on', function (string $choice, int $rows) {
    $script = new FacebookScript;

    app(TrackingSettings::class)->with(['eventDefaults' => ['facebook' => ['Purchase' => false]]])->save();

    $page = trackedPage($script, ['events' => ['facebook' => ['Purchase' => TrackingChoice::from($choice)->stored()]]]);

    $this->postJson(route('checkout.store'), capturePayload($page))->assertCreated();

    expect(TrackingEvent::where('event', $script->purchaseEvent())->count())->toBe($rows);
})->with([
    'the page says nothing' => ['inherit', 0],
    'the page says on' => ['on', 1],
    'the page says off' => ['off', 0],
]);

it('sends the money and not the basket for a page that suppresses content detail', function (ProviderScript $script) {
    $page = trackedPage($script);

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['tracking_config' => ['suppress_content_detail' => true]])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->postJson(route('checkout.store'), capturePayload($page->refresh()))->assertCreated();

    /** Frozen onto the row, because the sweep runs later and the Page may be gone by then. */
    expect(TrackingEvent::sole()->content_detail)->toBeFalse();

    $script->fakeAccepted();
    $this->artisan('tracking:sweep')->assertSuccessful();

    $sent = $script->sentEvents()[0];

    /** Meta keeps optimising on value and currency; the catalogue match is what is given up. */
    expect($script->valueOf($sent))->toBe(1050.0)
        ->and($script->currencyOf($sent))->toBe('BDT')
        ->and($script->orderReferenceOf($sent))->toBe(Order::sole()->order_number)
        ->and($script->contentsOf($sent))->toBe([]);

    /** And the browser's copy of the same event says exactly as much, because two copies of one event may not disagree. */
    $html = $this->withCookie(OrderOwnership::COOKIE, (string) json_encode([Order::sole()->order_number]))
        ->get(route('checkout.order-complete', Order::sole()->order_number))
        ->assertOk()
        ->getContent();

    $purchase = collect($script->browserEventsIn($html))->sole(fn (array $event): bool => $script->eventNameOf($event) === $script->purchaseEvent());

    expect($script->valueOf($purchase))->toBe(1050.0)
        ->and($script->contentsOf($purchase))->toBe([]);
})->with('tracking providers');

it('names what was bought for a page that has not suppressed it', function (ProviderScript $script) {
    $product = Product::factory()->create(['name' => 'Winter Jacket', 'price' => 99000]);

    $script->makeDestination();
    $page = sellingPage($product);

    $this->postJson(route('checkout.store'), capturePayload($page))->assertCreated();

    expect(TrackingEvent::sole()->content_detail)->toBeTrue();

    $script->fakeAccepted();
    $this->artisan('tracking:sweep')->assertSuccessful();

    expect($script->contentsOf($script->sentEvents()[0]))->toBe([
        ['id' => (string) $product->getKey(), 'quantity' => 1, 'price' => 990.0],
    ]);
})->with('tracking providers');

/**
 * Tracking is not a figure. An Order half-typed against a Page whose Merchant
 * has since switched an event off must still be captured, which is why none
 * of this reaches the Pricing Fingerprint (ADR-0007).
 */
it('leaves the pricing fingerprint alone, so switching an event off turns away no order', function () {
    $page = trackedPage(new FacebookScript);

    $fingerprint = PricingFingerprint::for($page);

    $page->update(['tracking_config' => [
        'destination_id' => Destination::sole()->getKey(),
        'events' => ['facebook' => ['Purchase' => false]],
        'suppress_content_detail' => true,
    ]]);

    expect(PricingFingerprint::for($page->refresh()))->toBe($fingerprint);

    /** And a form rendered before any of it still captures. */
    $this->postJson(route('checkout.store'), capturePayload($page, ['fingerprint' => $fingerprint]))->assertCreated();
});

it('shows what every event will send, with real parameter names and the match keys hashed', function () {
    $script = new FacebookScript;
    $page = trackedPage($script);

    $rendered = Livewire::test(EditPage::class, ['record' => $page->getKey()])->assertOk()->html();

    /** Every event this Page reports is previewed, in the Provider's own vocabulary. */
    foreach (['PageView', 'ViewContent', 'InitiateCheckout', 'Purchase'] as $event) {
        expect($rendered)->toContain($event.' — Brand Ads');
    }

    /** Real parameter names, real figures, and a phone number nobody can read back out of it. */
    expect($rendered)->toContain('&quot;action_source&quot;: &quot;website&quot;')
        ->and($rendered)->toContain('&quot;value&quot;: 1050')
        ->and($rendered)->toContain('&quot;currency&quot;: &quot;BDT&quot;')
        ->and($rendered)->toContain($script->hash($script->normalise('phone', '+8801712345678')))
        ->and($rendered)->not->toContain('8801712345678');
});

it('previews nothing at all for a page that reports nowhere', function () {
    $rendered = Livewire::test(EditPage::class, ['record' => sellingPage(Product::factory()->create())->getKey()])
        ->assertOk()
        ->html();

    expect($rendered)->toContain('This Page reports nothing.');
});

/**
 * Email is built in and defaults to off, so most Pages do not ask for it —
 * and it is the one strong match key a Merchant can add by flipping a switch
 * they already own.
 */
it('offers the email hint to a page that does not ask for an email, and not to one that does', function (bool $asks, bool $hinted) {
    $page = trackedPage(new FacebookScript);

    $page->update(['checkout_config' => CheckoutConfig::fromArray([
        ...$page->checkout_config->toArray(),
        'fields' => array_map(
            fn (array $field): array => $field['key'] === BuiltInField::Email->value ? [...$field, 'enabled' => $asks] : $field,
            $page->checkout_config->fieldsToArray(),
        ),
    ])]);

    $rendered = Livewire::test(EditPage::class, ['record' => $page->getKey()])->assertOk()->html();

    expect(str_contains($rendered, 'Switch the Email field on'))->toBe($hinted);
})->with([
    'the page does not ask for one' => [false, true],
    'the page already asks for one' => [true, false],
]);

it('holds the installation-wide default for every event a provider declares, and the beacon rate limit', function () {
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

it('refuses a beacon past the rate limit the merchant set', function () {
    app(TrackingSettings::class)->with(['beaconRateLimit' => 1])->save();

    $page = trackedPage(new FacebookScript);

    /** The document still carries its beacon; what changed is how often one address may use it. */
    expect(beaconIn($this->get('/'.$page->slug)->assertOk()->getContent())['address'])->toContain('tracking');

    reportLoad($page, 'first')->assertNoContent();
    reportLoad($page, 'second')->assertTooManyRequests();
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
    $config = TrackingConfig::fromArray(['events' => ['facebook' => ['Retired' => false, 'Purchase' => true]]]);

    expect($config->choiceFor(TrackingProvider::Facebook, 'Purchase'))->toBeTrue()
        ->and($config->toArray()['events']['facebook'])->toBe(['Retired' => false, 'Purchase' => true]);
});
