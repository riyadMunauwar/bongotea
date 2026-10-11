<?php

use App\Enums\TrackingMoment;
use App\Filament\Clusters\Settings\Pages\Tracking;
use App\Filament\Widgets\OverviewStats;
use App\Models\Order;
use App\Models\User;
use App\Models\VisitCount;
use App\Support\Orders\OrderOwnership;
use App\Support\Reporting\ReportingPreset;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\ReportingWindow;
use App\Support\Settings\GeneralSettings;
use App\Support\Settings\TrackingSettings;
use Carbon\CarbonImmutable;
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
 * path of every page load.
 *
 * Everything is driven through the real beacon and read off the real screens.
 */

/** Counting Visits, the way a Merchant switches it on. */
function countingVisits(): void
{
    app(TrackingSettings::class)->with(['countsVisits' => true])->save();
}

/** What the aggregate holds, over every day it has counted. */
function visitsCounted(): int
{
    return (int) VisitCount::query()->sum('visits');
}

describe('the switch', function () {
    it('counts nothing until the merchant asks for it', function () {
        expect(app(TrackingSettings::class)->countsVisits)->toBeFalse();

        reportToBeacon()->assertNoContent();

        expect(VisitCount::count())->toBe(0);
    });

    /**
     * Counting owes nothing to any ad platform: a store reporting to no Provider
     * anywhere still counts.
     */
    it('counts a load for a store reporting to no provider once counting is on', function () {
        countingVisits();

        reportToBeacon()->assertNoContent();

        expect(visitsCounted())->toBe(1);
    });

    /**
     * The Merchant's own figure is not an ad platform's to switch off.
     */
    it('counts a load where every visit event is switched off', function () {
        app(TrackingSettings::class)->with([
            'countsVisits' => true,
            'eventDefaults' => ['facebook' => ['PageView' => false, 'ViewContent' => false]],
        ])->save();

        (new FacebookScript)->switchOn();

        reportToBeacon()->assertNoContent();

        expect(visitsCounted())->toBe(1);
    });
});

describe('what a visit is', function () {
    it('counts every load, not every browser, as one row a day for the whole store', function () {
        countingVisits();

        /** Three loads from one browser are three Visits — the way an ad platform counts the same thing. */
        reportToBeacon(identifier: 'load-one')->assertNoContent();
        reportToBeacon(identifier: 'load-two')->assertNoContent();
        reportToBeacon(identifier: 'load-three')->assertNoContent();

        expect((int) VisitCount::query()->sole()->visits)->toBe(3);
    });

    /**
     * The day is the Merchant's own, because a Reporting Window cuts its days
     * at the Merchant's midnight and a figure bucketed at the server's would
     * disagree with the Orders it is set against. 17:30 UTC is half past
     * eleven at night in Dhaka; 18:30 UTC is half past midnight the next day.
     */
    it('counts a load against the merchant\'s day and not the server\'s', function () {
        countingVisits();

        $this->travelTo(CarbonImmutable::parse('2026-09-10 17:30:00', 'UTC'));
        reportToBeacon(identifier: 'before')->assertNoContent();

        $this->travelTo(CarbonImmutable::parse('2026-09-10 18:30:00', 'UTC'));
        reportToBeacon(identifier: 'after')->assertNoContent();

        expect(app(GeneralSettings::class)->timezone)->toBe('Asia/Dhaka')
            ->and(VisitCount::query()->orderBy('visited_on')->pluck('visited_on')->all())
            ->toBe(['2026-09-10', '2026-09-11']);
    });

    /**
     * Everything the beacon already refuses to record is also nothing to
     * count: a crawler that announces itself is not traffic a Merchant paid
     * for.
     */
    it('counts nothing the beacon would not record', function (Closure $arrange) {
        countingVisits();

        $arrange();

        expect(VisitCount::count())->toBe(0);
    })->with([
        'a crawler' => [fn () => reportToBeacon(headers: ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)'])->assertNoContent()],
        'a forgery' => [fn () => reportToBeacon(headers: ['Sec-Fetch-Site' => 'cross-site'])->assertForbidden()],
        'a moment that is not a visit' => [fn () => reportToBeacon(TrackingMoment::CheckoutStarted)->assertNoContent()],
    ]);

    /**
     * Whoever is on an Order Complete or a Retry Page has already ordered,
     * and counting them would dilute the Conversion Rate with the people who
     * converted — so neither page counts itself, however much each carries a
     * Pixel.
     */
    it('is not a load of the order complete page or the retry page', function () {
        countingVisits();

        (new FacebookScript)->switchOn();

        $order = orderAsCaptured();

        $this->withCookie(OrderOwnership::COOKIE, (string) json_encode([$order->order_number]))
            ->get(route('checkout.order-complete', $order->order_number))->assertOk();

        $this->get(route('checkout.retry', $order->order_number));

        expect(VisitCount::count())->toBe(0);
    });
});

describe('what the merchant is shown', function () {
    it('shows the visits tile a figure for the window', function () {
        countingVisits();

        /** Four loads today, and nine hundred the window leaves out. */
        VisitCount::factory()->on(0, 4)->create();
        VisitCount::factory()->on(20, 900)->create();

        Order::factory()->create(['subtotal' => 50000, 'delivery_charge' => 6000, 'created_at' => now()]);

        Livewire::test(OverviewStats::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'today']])
            ->assertSee('Visits')
            ->assertSee('4')
            ->assertDontSee('Not counted')
            ->assertDontSee('900');
    });

    it('draws the store-wide Conversion Rate beside Visits: every Order over every Visit, Cancelled ones included', function () {
        countingVisits();

        VisitCount::factory()->on(0, 40)->create();
        VisitCount::factory()->on(1, 20)->create();

        Order::factory()->create(['created_at' => now()]);
        Order::factory()->cancelled()->create(['created_at' => now()]);

        Livewire::test(OverviewStats::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'today']])
            ->assertSeeInOrder(['Visits', '40', 'Conversion Rate', '5.0%'])
            ->assertSee('5.0 pts up on the day before');
    });

    it('reads the Conversion Rate as not counted, never as nought, while Visits are off', function () {
        Order::factory()->create(['created_at' => now()]);

        Livewire::test(OverviewStats::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'today']])
            ->assertSeeInOrder(['Conversion Rate', 'Not counted', 'Counted once Visits are']);
    });

    /**
     * A nought on a tile beside six tiles carrying figures reads as no
     * traffic, which is a Merchant's worst afternoon and would be a lie. So
     * the tile says the feature is off, and links to where it is switched on.
     */
    it('says counting is off, and where to switch it on, rather than drawing a nought', function () {
        Order::factory()->create(['created_at' => now()]);

        $this->actingAs(User::factory()->managingSettings()->create());

        Livewire::test(OverviewStats::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'today']])
            ->assertSee('Not counted')
            ->assertSee('Switch Visit counting on in Settings')
            ->assertSee(Tracking::getUrl());
    });

    /**
     * The sentence stands for everyone, because everyone reading the tile
     * deserves to know the figure is off rather than nought — but the link
     * goes only to the User who could act on it, since the Settings cluster
     * is gated and a link to a forbidden page is worse than no link.
     */
    it('does not link a user who could not switch it on to the screen that would', function () {
        Order::factory()->create(['created_at' => now()]);

        $this->actingAs(User::factory()->create());

        Livewire::test(OverviewStats::class, ['pageFilters' => [ReportingWindow::PRESET_FILTER => 'today']])
            ->assertSee('Switch Visit counting on in Settings')
            ->assertDontSee(Tracking::getUrl());
    });

    /**
     * A rate with no denominator is unknown and not zero — but a span that was
     * counted and drew nobody really did convert nobody.
     */
    it('reads a counted window with no visits as no conversion, and an uncounted one as no answer', function () {
        Order::factory()->create(['created_at' => now()]);

        $rate = fn (): ?float => app(ReportingQuery::class)
            ->overview(ReportingWindow::of(ReportingPreset::Today))
            ->figures
            ->conversionRate();

        expect($rate())->toBeNull();

        countingVisits();

        expect($rate())->toBe(0.0);
    });

    it('works the conversion rate out over the whole store', function () {
        countingVisits();

        VisitCount::factory()->on(0, 4)->create();
        Order::factory()->create(['created_at' => now()]);

        $rate = app(ReportingQuery::class)
            ->overview(ReportingWindow::of(ReportingPreset::Today))
            ->figures
            ->conversionRate();

        expect($rate)->toBe(0.25);
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
