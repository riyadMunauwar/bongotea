<?php

use App\Enums\PageStatus;
use App\Enums\TrackingMoment;
use App\Models\Destination;
use App\Models\Page;
use App\Models\TrackingEvent;
use App\Models\User;
use App\Support\PageCache\FallbackPageCacheReader;
use App\Support\Tracking\TrackingSnippet;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\Tracking\FacebookScript;
use Tests\Fixtures\Tracking\ProviderScript;

/**
 * A visitor loading a published Page, reported from both halves.
 *
 * This is the slice the whole design was shaped around and the one carrying
 * the real risk: a published Page is served by the webserver as a file with
 * no PHP running, so the Pixel has to be baked into those bytes and the
 * server's copy has to arrive afterwards, from the browser, over something
 * that costs almost nothing. Everything below is read out of the document a
 * visitor is actually served, or out of the rows the beacon actually writes.
 */

/** What a browser sends when it is our own page talking to us. */
const SAME_ORIGIN = ['Sec-Fetch-Site' => 'same-origin'];

/**
 * A published Page reporting to this Provider's Destination, as a visitor
 * receives it.
 */
function visitedPage(ProviderScript $script): string
{
    $script->makeDestination();

    $page = Page::factory()->published()->create(['slug' => 'promo']);

    return test()->get('/'.$page->slug)->assertOk()->getContent();
}

/**
 * The beacon this document carries: the address it posts to, and the name of
 * the identifier it appends.
 *
 * Read out of the served bytes rather than built, because what is being
 * pinned is that the Pixel and the beacon were handed the same one.
 *
 * @return array{address: string, identifier: string}
 */
function beaconIn(string $html): array
{
    $found = [];

    expect(preg_match('/var gioBeacon = "([^"]+)" \+ encodeURIComponent\(([A-Za-z_$][A-Za-z0-9_$]*)\);/', $html, $found))
        ->toBe(1, 'The document carries no beacon.');

    return ['address' => $found[1], 'identifier' => $found[2]];
}

/**
 * The browser reporting a Moment, the way the snippet does it: everything in
 * the address, nothing in the body, and the header a browser sets itself.
 *
 * @param  array<string, string|null>  $parameters
 * @param  array<string, string>  $headers
 */
function reportMoment(array $parameters = [], array $headers = SAME_ORIGIN): TestResponse
{
    $query = array_filter([
        'slug' => 'promo',
        'moment' => TrackingMoment::Visit->value,
        'id' => 'load-3f9a2c',
        ...$parameters,
    ], fn (?string $value): bool => $value !== null);

    return test()->post(route('tracking.report').'?'.http_build_query($query), [], $headers);
}

describe('what a served page tells the browser to report', function () {
    it('reports the load and the look at the product from a page a visitor is served', function (ProviderScript $script) {
        $html = visitedPage($script);

        expect($script->carriesBaseCodeFor(Destination::sole(), $html))->toBeTrue();

        $events = $script->browserEventsIn($html);

        /** The Moment's own events, the load among them and reported once, not a bare one beside it. */
        expect(array_map($script->eventNameOf(...), $events))->toBe($script->visitEvents());
    })->with('tracking providers');

    it('hands the pixel and the beacon one identifier, made up by the browser on load', function (ProviderScript $script) {
        $html = visitedPage($script);

        $beacon = beaconIn($html);

        $identifiers = array_map($script->deduplicationIdOf(...), $script->browserEventsIn($html));

        /**
         * Every event, and the beacon, naming the same value — which is the
         * whole of how one load is counted once however many of its copies
         * arrive.
         */
        expect(array_values(array_unique($identifiers)))->toBe([$beacon['identifier']])
            ->and($html)->toContain('var '.$beacon['identifier'].' = ');
    })->with('tracking providers');

    /**
     * A published Page is one file served to everyone. An identifier written
     * into it at render time would be the same string for every visitor, and
     * the Provider would deduplicate a whole campaign's traffic down to one
     * Visit.
     */
    it('bakes no identifier into a document the whole campaign is served', function (ProviderScript $script) {
        expect(visitedPage($script))->not->toContain('"eventID": "');
    })->with('tracking providers');

    /**
     * The two halves are redundant, and the browser's is the one that has
     * already happened by the time the server is told. Being refused at the
     * beacon — a rate limit, an outage, a client that sends no
     * `Sec-Fetch-Site` — therefore costs the redundancy and not the event.
     */
    it('fires the pixel before it tells the server anything', function (ProviderScript $script) {
        $html = visitedPage($script);

        expect($script->browserEventsIn(Str::before($html, 'var gioBeacon')))
            ->toBe($script->browserEventsIn($html));
    })->with('tracking providers');

    /**
     * `sendBeacon` survives the visitor leaving for the next page, which is
     * most of them; `keepalive` is the same promise for a browser without
     * it. Neither holds up the paint and neither reads the answer.
     */
    it('posts the visit without waiting for anyone', function (ProviderScript $script) {
        expect(visitedPage($script))
            ->toContain('navigator.sendBeacon(gioBeacon)')
            ->toContain('keepalive: true');
    })->with('tracking providers');

    it('consults one global for consent and fires unless it is explicitly false', function (ProviderScript $script) {
        expect(visitedPage($script))->toContain('if (window.'.TrackingSnippet::CONSENT_HOOK.' === false) {');
    })->with('tracking providers');

    /**
     * The server half stands alone. A Merchant who has added a Destination
     * and not yet pasted its dataset has nothing for the browser to address,
     * and is still owed the Visits that accumulate meanwhile.
     */
    it('still reports the visit to the server for a destination the browser cannot address', function (ProviderScript $script) {
        $script->makeDestination()->update(['dataset' => '']);

        Page::factory()->published()->create(['slug' => 'promo']);

        $html = $this->get('/promo')->assertOk()->getContent();

        expect($script->browserEventsIn($html))->toBe([])
            ->and($html)->toContain(beaconIn($html)['address']);
    })->with('tracking providers');

    /**
     * The sentence the Page form used to make — "what you paste is what a
     * visitor receives" — survives for every Page that reports nowhere,
     * which is every Page until a Merchant configures a Destination.
     */
    it('injects nothing into a page that reports nowhere', function () {
        $page = Page::factory()->published()->create(['slug' => 'promo']);

        $html = $this->get('/promo')->assertOk()->getContent();

        expect($html)->toBe($page->body)
            ->and($html)->not->toContain('<script')
            ->and($html)->not->toContain(TrackingSnippet::CONSENT_HOOK);
    });

    it('injects nothing for a destination the merchant has switched off, because that is what a campaign break is', function (ProviderScript $script) {
        $script->makeDestination()->update(['enabled' => false]);

        $page = Page::factory()->published()->create(['slug' => 'promo']);

        expect($this->get('/promo')->assertOk()->getContent())->toBe($page->body);
    })->with('tracking providers');

    /**
     * A Merchant looking at their own draft is not a Visit. Reporting one
     * would put the admin's own loads into the Merchant's Conversion Rate
     * and into their dataset, which is the reason the Processing Page
     * carries no Pixel either.
     */
    it('carries no pixel on the authoring preview', function (ProviderScript $script) {
        $script->makeDestination();

        $page = Page::factory()->create();

        $html = $this->actingAs(User::factory()->create())
            ->get(route('pages.preview', $page))
            ->assertOk()
            ->getContent();

        expect($html)->toBe($page->body);
    })->with('tracking providers');
});

describe('where the snippet goes', function () {
    it('inserts the pixel at the first place the merchant left it', function (string $body, string $expected) {
        (new FacebookScript)->makeDestination();

        Page::factory()->published()->create(['slug' => 'promo', 'body' => $body]);

        expect($this->get('/promo')->assertOk()->getContent())->toContain($expected);
    })->with([
        'before the head close' => ['<html><head><title>T</title></head><body>hi</body></html>', "</script>\n</head>"],
        'whatever case it was written in' => ['<HTML><HEAD><TITLE>T</TITLE></HEAD><BODY>hi</BODY></HTML>', "</script>\n</HEAD>"],
        'before the body close, where there is no head' => ['<body>hi</body>', "</script>\n</body>"],
    ]);

    /** A merchant who pasted a fragment still gets a Pixel that runs. */
    it('appends the pixel to a document that closes neither head nor body', function () {
        (new FacebookScript)->makeDestination();

        Page::factory()->published()->create(['slug' => 'promo', 'body' => '<p>hi</p>']);

        $html = $this->get('/promo')->assertOk()->getContent();

        expect($html)->toStartWith('<p>hi</p>')
            ->and($html)->toEndWith('</script>');
    });

    /**
     * The bytes the visitor was handed are the bytes on disk are the bytes a
     * Merchant whose webserver was never configured serves. The Pixel is
     * injected before the one string is both cached and returned, so there
     * is no way for the three to disagree about it.
     */
    it('hands the visitor, the disk and the fallback reader the same bytes with the pixel in them', function () {
        (new FacebookScript)->makeDestination();

        Page::factory()->published()->create(['slug' => 'promo']);

        $response = $this->get('/promo');

        $served = (new FallbackPageCacheReader($this->cacheDirectory))->resolve('/promo', 'GET');

        expect($response->getContent())->toContain('<script')
            ->and(file_get_contents($this->cache->htmlPath('promo')))->toBe($response->getContent())
            ->and(file_get_contents($served['path']))->toBe($response->getContent());
    });

    /**
     * The Pixel is frozen into the file, so a warm cache still answers with
     * no PHP: the second visitor is served by the webserver, or by the
     * fallback reader above it, and this method is not reached again.
     */
    it('leaves a tracked page on disk for the webserver to serve', function () {
        (new FacebookScript)->makeDestination();

        Page::factory()->published()->create(['slug' => 'promo']);

        $served = $this->get('/promo')->getContent();

        expect($this->cache->has('promo'))->toBeTrue()
            ->and(file_get_contents($this->cache->htmlPath('promo')))->toContain(beaconIn($served)['address']);
    });
});

describe('the beacon', function () {
    it('writes the server copy of everything the moment reports', function (ProviderScript $script) {
        visitedPage($script);

        reportMoment()->assertNoContent();

        expect(TrackingEvent::query()->orderBy('id')->pluck('event')->all())->toBe($script->visitEvents());

        $event = TrackingEvent::query()->first();

        expect($event->moment)->toBe(TrackingMoment::Visit)
            ->and($event->deduplication_id)->toBe('load-3f9a2c')
            ->and($event->page_slug)->toBe('promo')
            ->and($event->order_id)->toBeNull()
            ->and($event->source_url)->toBe(route('landing.show', 'promo'));
    })->with('tracking providers');

    /**
     * The three things a Provider matches on itself, which is the whole
     * point of the server copy: a browser that blocked the Pixel still
     * arrives at Meta with an address and a User-Agent attached.
     */
    it('keeps what the provider matches on from the request the browser made', function (ProviderScript $script) {
        visitedPage($script);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeaders([...SAME_ORIGIN, 'User-Agent' => 'Mozilla/5.0 (iPhone)'])
            ->post(route('tracking.report').'?'.http_build_query([
                'slug' => 'promo',
                'moment' => TrackingMoment::Visit->value,
                'id' => 'load-3f9a2c',
            ]))->assertNoContent();

        $event = TrackingEvent::query()->firstOrFail();

        expect($event->ip_address)->toBe('203.0.113.9')
            ->and($event->user_agent)->toBe('Mozilla/5.0 (iPhone)');
    })->with('tracking providers');

    it('counts two loads as two visits, and one load reported twice as one', function (ProviderScript $script) {
        visitedPage($script);

        reportMoment(['id' => 'load-one'])->assertNoContent();
        reportMoment(['id' => 'load-two'])->assertNoContent();

        /** The same load arriving again — a retried beacon, a replayed address — lands on the unique index. */
        reportMoment(['id' => 'load-two'])->assertNoContent();

        expect(TrackingEvent::query()->distinct()->count('deduplication_id'))->toBe(2)
            ->and(TrackingEvent::count())->toBe(count($script->visitEvents()) * 2);
    })->with('tracking providers');

    /**
     * The endpoint is unauthenticated and carries no session, so the header
     * a browser sets itself and a page cannot lie about is the whole of the
     * forgery answer — the same signal the Checkout POST already trusts.
     */
    it('refuses anything that is not this site talking to itself', function (?string $site) {
        (new FacebookScript)->makeDestination();

        Page::factory()->published()->create(['slug' => 'promo']);

        reportMoment(headers: $site === null ? [] : ['Sec-Fetch-Site' => $site])->assertForbidden();

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

        Page::factory()->published()->create(['slug' => 'promo']);

        reportMoment(headers: [...SAME_ORIGIN, 'User-Agent' => $userAgent])->assertNoContent();

        expect(TrackingEvent::count())->toBe(0);
    })->with([
        'a search engine' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        'a link preview fetcher' => 'facebookexternalhit/1.1',
        'a headless browser' => 'Mozilla/5.0 HeadlessChrome/120.0.0.0',
    ]);

    /**
     * Capture and Paid are written by the code that captures and verifies. A
     * Purchase anyone holding a Page's slug could post is reported revenue
     * the Merchant does not have.
     */
    it('writes nothing for a moment the browser may not report', function (string $moment) {
        (new FacebookScript)->makeDestination();

        Page::factory()->published()->create(['slug' => 'promo']);

        reportMoment(['moment' => $moment])->assertNoContent();

        expect(TrackingEvent::count())->toBe(0);
    })->with([
        'a capture' => TrackingMoment::Capture->value,
        'a payment' => TrackingMoment::Paid->value,
        'a word nothing knows' => 'something-else',
        'nothing at all' => '',
    ]);

    it('writes nothing for a page that is not published, or is not a page at all', function (Closure $arrange, string $slug) {
        (new FacebookScript)->makeDestination();

        $arrange();

        reportMoment(['slug' => $slug])->assertNoContent();

        expect(TrackingEvent::count())->toBe(0);
    })->with([
        'a draft' => [fn () => Page::factory()->create(['slug' => 'promo', 'status' => PageStatus::Draft]), 'promo'],
        'a slug nothing answers' => [fn () => Page::factory()->published()->create(['slug' => 'promo']), 'not-a-page'],
        'nothing at all' => [fn () => Page::factory()->published()->create(['slug' => 'promo']), ''],
    ]);

    /**
     * An identifier that has been cut deduplicates against nothing, so a
     * long one is dropped rather than trimmed: keeping it would cost exactly
     * the double counting the identifier exists to prevent.
     */
    it('writes nothing for an identifier it could not deduplicate on', function (string $identifier) {
        (new FacebookScript)->makeDestination();

        Page::factory()->published()->create(['slug' => 'promo']);

        reportMoment(['id' => $identifier])->assertNoContent();

        expect(TrackingEvent::count())->toBe(0);
    })->with([
        'nothing at all' => '',
        'an essay' => [str_repeat('x', 101)],
    ]);

    it('writes nothing for a page that reports nowhere', function () {
        Page::factory()->published()->create(['slug' => 'promo']);

        reportMoment()->assertNoContent();

        expect(TrackingEvent::count())->toBe(0);
    });

    /**
     * Two segments deep, like every other non-landing route, so the
     * one-segment landing catch-all cannot shadow it. No session, no
     * cookies, no request-forgery middleware — that is what keeps it cheap
     * enough to sit on the path of every ad click — and a rate limit,
     * because it is an unauthenticated write.
     */
    it('is registered with no middleware group, behind a rate limit, and out of the catch-all\'s reach', function () {
        $route = Route::getRoutes()->getByName('tracking.report');

        expect($route->methods())->toBe(['POST'])
            ->and(count(explode('/', $route->uri())))->toBe(2)
            ->and($route->gatherMiddleware())->toBe(['throttle:tracking']);
    });
});
