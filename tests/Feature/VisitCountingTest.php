<?php

use App\Enums\TrackingMoment;
use App\Filament\Clusters\Settings\Pages\Tracking;
use App\Filament\Widgets\OverviewStats;
use App\Filament\Widgets\PageLeaderboardTable;
use App\Models\Order;
use App\Models\Page;
use App\Models\Product;
use App\Models\User;
use App\Models\VisitCount;
use App\Support\Orders\OrderOwnership;
use App\Support\Reporting\ReportingPreset;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\ReportingWindow;
use App\Support\Settings\GeneralSettings;
use App\Support\Settings\TrackingSettings;
use App\Support\Tracking\TrackingSnippet;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Fixtures\Tracking\FacebookScript;

/**
 * The Merchant's own traffic figure, and the Conversion Rate it makes
 * possible.
 *
 * This is the one Moment that touches no ad platform, and everything below
 * turns on that: a Merchant who has never heard of a Pixel switches counting
 * on, and the tile that has always said "not yet" starts saying a number.
 * Counting stays off until they ask, because it is a database write on the
 * path of every page load and this product is served from shared hosting.
 *
 * Everything is driven through the real beacon and read off the real screens.
 */

/** Counting Visits, the way a Merchant switches it on. */
function countingVisits(): void
{
    app(TrackingSettings::class)->with(['countsVisits' => true])->save();
}

/** A published Page, reporting to nobody at all unless a Destination is made. */
function untrackedPage(string $slug = 'promo'): Page
{
    return Page::factory()->published()->create(['slug' => $slug]);
}

/**
 * The browser reporting one load, as the snippet does it: everything in the
 * address, and the header a browser sets itself.
 */
function loadOf(Page $page, string $identifier = 'load-3f9a2c'): TestResponse
{
    $query = http_build_query(['slug' => $page->slug, 'moment' => TrackingMoment::Visit->value, 'id' => $identifier]);

    return test()->post(route('tracking.report').'?'.$query, [], ['Sec-Fetch-Site' => 'same-origin']);
}

/** What the aggregate holds for a Page, over every day it has counted. */
function visitsOf(Page $page): int
{
    return (int) VisitCount::query()->where('page_id', $page->getKey())->sum('visits');
}

describe('the switch', function () {
    /**
     * A write per page load that nobody asked for is the wrong instinct on
     * shared hosting, so the Page a Merchant has not configured is served the
     * bytes it has always been served — no element, no global, no beacon.
     */
    it('counts nothing, and carries no beacon, until the merchant asks for it', function () {
        $page = untrackedPage();

        expect(app(TrackingSettings::class)->countsVisits)->toBeFalse();

        $html = $this->get('/promo')->assertOk()->getContent();

        expect($html)->toBe($page->body)
            ->and($html)->not->toContain(TrackingSnippet::CONSENT_HOOK);

        /** And a beacon posted by hand anyway counts nothing: the switch is the rule, not the snippet. */
        loadOf($page)->assertNoContent();

        expect(VisitCount::count())->toBe(0);
    });

    /**
     * Counting owes nothing to any ad platform. A Merchant with no Destination
     * anywhere in the installation still gets a beacon, and that beacon still
     * addresses nobody but this site.
     */
    it('carries the beacon on a page with no destination once counting is on', function () {
        countingVisits();

        $page = untrackedPage();

        $html = $this->get('/promo')->assertOk()->getContent();

        expect(beaconIn($html)['address'])->toContain('moment='.TrackingMoment::Visit->value)
            ->and($html)->toContain(TrackingSnippet::CONSENT_HOOK)
            ->and($html)->not->toContain('facebook');

        loadOf($page)->assertNoContent();

        expect(visitsOf($page))->toBe(1);
    });

    /**
     * The Merchant's own figure is not an ad platform's to switch off. A Page
     * reporting nothing at all for the Visit Moment still carries the beacon,
     * because the count is the other reason to send one.
     */
    it('carries the beacon for a page whose visit events are every one switched off', function () {
        app(TrackingSettings::class)->with([
            'countsVisits' => true,
            'eventDefaults' => ['facebook' => ['PageView' => false, 'ViewContent' => false]],
        ])->save();

        (new FacebookScript)->makeDestination();

        $page = untrackedPage();

        expect(beaconIn($this->get('/promo')->assertOk()->getContent())['address'])
            ->toContain('moment='.TrackingMoment::Visit->value);

        loadOf($page)->assertNoContent();

        expect(visitsOf($page))->toBe(1);
    });
});

describe('what a visit is', function () {
    it('counts every load of a published page, not every browser', function () {
        countingVisits();

        $page = untrackedPage();

        /** Three loads from one browser are three Visits — the way an ad platform counts the same thing. */
        loadOf($page, 'load-one')->assertNoContent();
        loadOf($page, 'load-two')->assertNoContent();
        loadOf($page, 'load-three')->assertNoContent();

        $row = VisitCount::query()->sole();

        expect((int) $row->visits)->toBe(3)
            ->and((int) $row->page_id)->toBe($page->getKey());
    });

    it('keeps one page\'s loads apart from another\'s', function () {
        countingVisits();

        $promo = untrackedPage('promo');
        $winter = untrackedPage('winter');

        loadOf($promo, 'a')->assertNoContent();
        loadOf($promo, 'b')->assertNoContent();
        loadOf($winter, 'c')->assertNoContent();

        expect(visitsOf($promo))->toBe(2)
            ->and(visitsOf($winter))->toBe(1);
    });

    /**
     * The day is the Merchant's own, because a Reporting Window cuts its days
     * at the Merchant's midnight and a figure bucketed at the server's would
     * disagree with the Orders it is set against. 17:30 UTC is half past
     * eleven at night in Dhaka; 18:30 UTC is half past midnight the next day.
     */
    it('counts a load against the merchant\'s day and not the server\'s', function () {
        countingVisits();

        $page = untrackedPage();

        $this->travelTo(CarbonImmutable::parse('2026-09-10 17:30:00', 'UTC'));
        loadOf($page, 'before')->assertNoContent();

        $this->travelTo(CarbonImmutable::parse('2026-09-10 18:30:00', 'UTC'));
        loadOf($page, 'after')->assertNoContent();

        expect(app(GeneralSettings::class)->timezone)->toBe('Asia/Dhaka')
            ->and(VisitCount::query()->orderBy('visited_on')->pluck('visited_on')->all())
            ->toBe(['2026-09-10', '2026-09-11']);
    });

    /**
     * Everything the beacon already refuses to record is also nothing to
     * count: nobody is on a Page that was never published, and a crawler that
     * announces itself is not traffic a Merchant paid for.
     */
    it('counts nothing the beacon would not record', function (Closure $arrange) {
        countingVisits();

        $arrange();

        expect(VisitCount::count())->toBe(0);
    })->with([
        'a draft' => [fn () => loadOf(Page::factory()->create(['slug' => 'promo']))->assertNoContent()],
        'a slug nothing answers' => [fn () => tap(untrackedPage(), fn () => test()
            ->post(route('tracking.report').'?'.http_build_query([
                'slug' => 'not-a-page',
                'moment' => TrackingMoment::Visit->value,
                'id' => 'load-3f9a2c',
            ]), [], ['Sec-Fetch-Site' => 'same-origin'])->assertNoContent())],
        'a crawler' => [fn () => test()
            ->withHeaders(['Sec-Fetch-Site' => 'same-origin', 'User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)'])
            ->post(route('tracking.report').'?'.http_build_query([
                'slug' => untrackedPage()->slug,
                'moment' => TrackingMoment::Visit->value,
                'id' => 'load-3f9a2c',
            ]))->assertNoContent()],
        'a forgery' => [fn () => test()
            ->post(route('tracking.report').'?'.http_build_query([
                'slug' => untrackedPage()->slug,
                'moment' => TrackingMoment::Visit->value,
                'id' => 'load-3f9a2c',
            ]), [], ['Sec-Fetch-Site' => 'cross-site'])->assertForbidden()],
    ]);

    /**
     * A Visit is a load of a published Page. Whoever is on an Order Complete
     * or a Retry Page has already ordered, and counting them would dilute the
     * Conversion Rate with the people who converted — so neither carries a
     * beacon at all, however much each carries a Pixel.
     */
    it('is not a load of the order complete page or the retry page', function () {
        countingVisits();

        (new FacebookScript)->makeDestination();

        $this->postJson(route('checkout.store'), capturePayload(sellingPage(Product::factory()->create())))->assertCreated();

        $order = Order::sole();

        $complete = $this->withCookie(OrderOwnership::COOKIE, (string) json_encode([$order->order_number]))
            ->get(route('checkout.order-complete', $order->order_number))->assertOk()->getContent();

        $retry = $this->get(route('checkout.retry', $order->order_number))->getContent();

        expect($complete)->not->toContain('gioBeacon')
            ->and($retry)->not->toContain('gioBeacon')
            ->and(VisitCount::count())->toBe(0);
    });
});

describe('what the merchant is shown', function () {
    it('shows the visits tile a figure, and the leaderboard a conversion rate', function () {
        countingVisits();

        $page = untrackedPage();

        /** Four loads today, one of which ordered, and nine hundred the window leaves out. */
        VisitCount::factory()->for($page)->on(0, 4)->create();
        VisitCount::factory()->for($page)->on(20, 900)->create();

        Order::factory()->for($page)->create(['subtotal' => 50000, 'delivery_charge' => 6000, 'created_at' => now()]);

        Livewire::test(OverviewStats::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'today']])
            ->assertSee('Visits')
            ->assertSee('4')
            ->assertDontSee('Not counted')
            ->assertDontSee('900');

        Livewire::test(PageLeaderboardTable::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'today']])
            ->assertSee('4 / 25.0%')
            ->assertDontSee('Not counted');
    });

    /**
     * A nought on a tile beside six tiles carrying figures reads as no
     * traffic, which is a Merchant's worst afternoon and would be a lie. So
     * the tile says the feature is off, and links to where it is switched on.
     */
    it('says counting is off, and where to switch it on, rather than drawing a nought', function () {
        $page = untrackedPage();

        Order::factory()->for($page)->create(['created_at' => now()]);

        $this->actingAs(User::factory()->managingSettings()->create());

        Livewire::test(OverviewStats::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'today']])
            ->assertSee('Not counted')
            ->assertSee('Switch Visit counting on in Settings')
            ->assertSee(Tracking::getUrl());

        Livewire::test(PageLeaderboardTable::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'today']])
            ->assertSee('Not counted');
    });

    /**
     * The sentence stands for everyone, because everyone reading the tile
     * deserves to know the figure is off rather than nought — but the link
     * goes only to the User who could act on it, since the Settings cluster
     * is gated and a link to a forbidden page is worse than no link.
     */
    it('does not link a user who could not switch it on to the screen that would', function () {
        Order::factory()->for(untrackedPage())->create(['created_at' => now()]);

        $this->actingAs(User::factory()->create());

        Livewire::test(OverviewStats::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'today']])
            ->assertSee('Switch Visit counting on in Settings')
            ->assertDontSee(Tracking::getUrl());
    });

    /** The Page's own screen asks the same question of one Page. */
    it('draws one page\'s visits on that page\'s own screen', function () {
        countingVisits();

        $reported = untrackedPage('promo');
        $other = untrackedPage('winter');

        VisitCount::factory()->for($reported)->on(0, 7)->create();
        VisitCount::factory()->for($other)->on(0, 300)->create();

        Livewire::test(OverviewStats::class, ['record' => $reported, 'pageFilters' => [ReportingWindow::PRESET_FILTER => 'today']])
            ->assertSee('7')
            ->assertDontSee('300');
    });

    /**
     * The Visit aggregate is the one thing tracking writes that is kept
     * indefinitely, so deleting a Page for good must not make last month
     * smaller than it was. What goes is the attribution and not the count:
     * the installation's own figure stands, and the leaderboard row left
     * behind says it has no figure rather than telling the Merchant to
     * switch on something that is already on.
     */
    it('keeps a hard-deleted page\'s counts against the installation, and claims them for no row', function () {
        countingVisits();

        $page = untrackedPage();

        VisitCount::factory()->for($page)->on(0, 40)->create();

        Order::factory()->for($page)->create(['created_at' => now()]);

        $page->forceDelete();

        Livewire::test(OverviewStats::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'today']])
            ->assertSee('40')
            ->assertDontSee('Not counted');

        Livewire::test(PageLeaderboardTable::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'today']])
            ->assertSee('promo')
            ->assertSee('—')
            ->assertDontSee('Not counted');
    });

    /**
     * A rate with no denominator is unknown and not zero — but a span that was
     * counted and drew nobody really did convert nobody.
     */
    it('reads a counted window with no visits as no conversion, and an uncounted one as no answer', function () {
        Order::factory()->for(untrackedPage())->create(['created_at' => now()]);

        $rate = fn (): ?float => app(ReportingQuery::class)
            ->overview(ReportingWindow::of(ReportingPreset::Today))
            ->figures
            ->conversionRate();

        expect($rate())->toBeNull();

        countingVisits();

        expect($rate())->toBe(0.0);
    });
});

it('is a toggle on the tracking settings page, off until the merchant saves it on', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    Livewire::test(Tracking::class)
        ->assertSchemaStateSet(['countsVisits' => false])
        ->fillForm(['countsVisits' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(TrackingSettings::class)->countsVisits)->toBeTrue();
});
