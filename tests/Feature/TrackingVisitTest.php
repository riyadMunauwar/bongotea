<?php

use App\Enums\TrackingMoment;
use App\Models\TrackingEvent;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\Tracking\FacebookScript;
use Tests\Fixtures\Tracking\ProviderScript;

/**
 * A visitor loading a storefront page, reported to the server by the beacon.
 *
 * The beacon is what the browser posts after the page has painted, so the
 * server's copy of a Visit arrives from the browser over something that
 * costs almost nothing. Everything below is read out of the rows the beacon
 * actually writes.
 */
describe('the beacon', function () {
    it('writes the server copy of everything the moment reports', function (ProviderScript $script) {
        $script->makeDestination();

        reportToBeacon(headers: ['Referer' => 'https://shop.test/tea/premium'])->assertNoContent();

        expect(TrackingEvent::query()->orderBy('id')->pluck('event')->all())->toBe($script->visitEvents());

        $event = TrackingEvent::query()->first();

        expect($event->moment)->toBe(TrackingMoment::Visit)
            ->and($event->deduplication_id)->toBe('load-3f9a2c')
            ->and($event->order_id)->toBeNull()

            /** The page the visitor was on, which a same-origin beacon carries as its Referer. */
            ->and($event->source_url)->toBe('https://shop.test/tea/premium');
    })->with('tracking providers');

    /**
     * The three things a Provider matches on itself, which is the whole
     * point of the server copy: a browser that blocked the Pixel still
     * arrives at Meta with an address and a User-Agent attached.
     */
    it('keeps what the provider matches on from the request the browser made', function (ProviderScript $script) {
        $script->makeDestination();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);

        reportToBeacon(headers: ['User-Agent' => 'Mozilla/5.0 (iPhone)'])->assertNoContent();

        $event = TrackingEvent::query()->firstOrFail();

        expect($event->ip_address)->toBe('203.0.113.9')
            ->and($event->user_agent)->toBe('Mozilla/5.0 (iPhone)');
    })->with('tracking providers');

    it('counts two loads as two visits, and one load reported twice as one', function (ProviderScript $script) {
        $script->makeDestination();

        reportToBeacon(identifier: 'load-one')->assertNoContent();
        reportToBeacon(identifier: 'load-two')->assertNoContent();

        /** The same load arriving again — a retried beacon, a replayed address — lands on the unique index. */
        reportToBeacon(identifier: 'load-two')->assertNoContent();

        expect(TrackingEvent::query()->distinct()->count('deduplication_id'))->toBe(2)
            ->and(TrackingEvent::count())->toBe(count($script->visitEvents()) * 2);
    })->with('tracking providers');

    /**
     * The endpoint is unauthenticated and carries no session, so the header
     * a browser sets itself and a page cannot lie about is the whole of the
     * forgery answer.
     */
    it('refuses anything that is not this site talking to itself', function (?string $site) {
        (new FacebookScript)->makeDestination();

        $address = route('tracking.report').'?'.http_build_query(['moment' => TrackingMoment::Visit->value, 'id' => 'load-3f9a2c']);

        $this->post($address, [], $site === null ? [] : ['Sec-Fetch-Site' => $site])->assertForbidden();

        expect(TrackingEvent::count())->toBe(0);
    })->with([
        'a cross-site post' => 'cross-site',
        'another site of ours' => 'same-site',
        'a client that sends no such header' => null,
    ]);

    /**
     * A crawler that announces itself is not traffic. It is dropped rather
     * than refused: there is nothing wrong with the request, there is just
     * nothing to count.
     */
    it('drops a visit from something that announced itself as a robot', function (string $userAgent) {
        (new FacebookScript)->makeDestination();

        reportToBeacon(headers: ['User-Agent' => $userAgent])->assertNoContent();

        expect(TrackingEvent::count())->toBe(0);
    })->with([
        'a search engine' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        'a link preview fetcher' => 'facebookexternalhit/1.1',
        'a headless browser' => 'Mozilla/5.0 HeadlessChrome/120.0.0.0',
    ]);

    /**
     * Capture and Paid are written by the code that captures and verifies. A
     * Purchase anyone could post is reported revenue the Merchant does not
     * have.
     */
    it('writes nothing for a moment the browser may not report', function (string $moment) {
        (new FacebookScript)->makeDestination();

        $this->post(route('tracking.report').'?'.http_build_query(['moment' => $moment, 'id' => 'load-3f9a2c']), [], ['Sec-Fetch-Site' => 'same-origin'])
            ->assertNoContent();

        expect(TrackingEvent::count())->toBe(0);
    })->with([
        'a capture' => TrackingMoment::Capture->value,
        'a payment' => TrackingMoment::Paid->value,
        'a word nothing knows' => 'something-else',
        'nothing at all' => '',
    ]);

    /**
     * An identifier that has been cut deduplicates against nothing, so a
     * long one is dropped rather than trimmed: keeping it would cost exactly
     * the double counting the identifier exists to prevent.
     */
    it('writes nothing for an identifier it could not deduplicate on', function (string $identifier) {
        (new FacebookScript)->makeDestination();

        reportToBeacon(identifier: $identifier)->assertNoContent();

        expect(TrackingEvent::count())->toBe(0);
    })->with([
        'nothing at all' => '',
        'an essay' => [str_repeat('x', 101)],
    ]);

    it('writes nothing where the store reports nowhere', function () {
        reportToBeacon()->assertNoContent();

        expect(TrackingEvent::count())->toBe(0);
    });

    /**
     * No session, no cookies, no request-forgery middleware — that is what
     * keeps it cheap enough to sit on the path of every page load — and a
     * rate limit, because it is an unauthenticated write.
     */
    it('is registered with no middleware group and behind a rate limit', function () {
        $route = Route::getRoutes()->getByName('tracking.report');

        expect($route->methods())->toBe(['POST'])
            ->and($route->gatherMiddleware())->toBe(['throttle:tracking']);
    });
});
