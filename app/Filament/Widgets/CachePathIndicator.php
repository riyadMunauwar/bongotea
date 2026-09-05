<?php

namespace App\Filament\Widgets;

use App\Models\Page;
use App\Support\PageCache\FallbackPageCacheReader;
use Filament\Widgets\Widget;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Tells the User which of the three paths is serving their Pages.
 *
 * Every way the fast path can fail produces a working site — the list is on
 * `FallbackPageCacheReader`, which exists to catch them — and the merchant is
 * told about none of it. They simply pay a framework boot on every click of the
 * ad traffic they bought, forever, with no signal anywhere. Without this widget
 * that reader is less a safety net than a hiding place.
 *
 * **The check runs in the User's browser, not on the server.** A server-side
 * check would have to make an HTTP request to its own domain, and loopback is
 * precisely what shared hosting breaks: blocked outbound requests, hairpin NAT
 * that does not resolve, firewalls refusing the host's own address. It would
 * report failure on installations where the cache is working perfectly. The
 * browser is already talking to this site successfully, which is the premise
 * the check rests on — so all this class does is hand the view an address and
 * the header vocabulary to read at it.
 *
 * No artisan command accompanies it. The audience is merchants on shared
 * hosting, who frequently have no SSH at all; a command-line diagnostic is a
 * tool for the person who did not need it.
 *
 * The check is not read-only, and cannot be: a request that misses the cache is
 * a full render, and `LandingPageController` writes what it rendered on the way
 * out. So opening the Dashboard on a cold Page fills that Page's cache. That is
 * why the miss state's remediation asks the User to check again — the second
 * answer is the one that says whether anything is actually wrong.
 *
 * **This spends the Dashboard slot.** `App\Filament\Pages\Dashboard` exists so
 * that a widget could be given a home, and shipped with none because Filament's
 * defaults did not earn it. This is what does: it is glanceable, it is
 * per-installation operational truth, and it is surfaced nowhere else.
 */
class CachePathIndicator extends Widget
{
    protected string $view = 'filament.admin.widgets.cache-path-indicator';

    protected int|string|array $columnSpan = 'full';

    /**
     * Rendered with the Dashboard rather than lazily.
     *
     * A lazy widget is a placeholder plus a Livewire round trip before its own
     * markup exists, and only then can the browser start the check. All this
     * one costs the Dashboard is a query for a slug; the round trip would be
     * the expensive half, and it would sit between the User opening the panel
     * and the answer they came for.
     */
    protected static bool $isLazy = false;

    /**
     * @return array{address: string|null, header: string, fallbackMarker: string, missMarker: string}
     */
    protected function getViewData(): array
    {
        return [
            'address' => $this->addressToCheck(),

            /**
             * The vocabulary is the reader's, read from its constants rather
             * than spelled again here: absence of this header is the fast path,
             * and the two markers name the other two. A rename there has to
             * reach this widget or the diagnosis silently starts lying.
             */
            'header' => FallbackPageCacheReader::HEADER,
            'fallbackMarker' => FallbackPageCacheReader::FALLBACK_MARKER,
            'missMarker' => FallbackPageCacheReader::MISS_MARKER,
        ];
    }

    /**
     * The address the browser will ask about, or null when there is nothing
     * published to ask about yet.
     *
     * Any published Page answers the question, because the question is about
     * the installation rather than the Page. Two things narrow the choice.
     *
     * A Page saved a moment ago has just had its cached copy removed by
     * `PageObserver` and would report a miss that says nothing about the
     * webserver, so the least recently saved is preferred. That is a
     * probability, not a guarantee — a Product price change clears every Page
     * that renders it without touching a timestamp here — which is why the miss
     * state asks the User to check again rather than declaring a fault.
     *
     * The Page must also actually be reachable at its own slug. A slug that
     * collides with a route registered before the landing catch-all — the
     * framework's `/up`, the panel's own path — is answered by that route
     * instead, with a 200 and no marker on it, which this widget would read as
     * the fast path. A false green is the one answer worse than no widget at
     * all, so a shadowed Page is passed over rather than asked about.
     *
     * The address is root-relative on purpose. An absolute URL is only
     * same-origin while the host it names agrees with the host the User is on,
     * and the whole check depends on the browser letting the widget read a
     * response header — which it does for a same-origin request and refuses for
     * anything else. Keeping the scheme and host out of it makes that true by
     * construction rather than by configuration. It is a path rather than a
     * bare slug so an installation served from a subdirectory is still asking
     * about itself.
     */
    private function addressToCheck(): ?string
    {
        $slug = Page::query()
            ->published()
            ->oldest('updated_at')
            ->pluck('slug')
            ->first(fn (string $slug): bool => $this->isReachedAtItsOwnSlug($slug));

        if ($slug === null) {
            return null;
        }

        return (string) parse_url(url($slug), PHP_URL_PATH);
    }

    /**
     * Whether a request for this slug would reach the landing route at all,
     * rather than something registered ahead of it.
     *
     * Asked of the router rather than of a list of known collisions, so a route
     * added later is covered without anyone remembering this widget.
     */
    private function isReachedAtItsOwnSlug(string $slug): bool
    {
        try {
            return Route::getRoutes()->match(Request::create('/'.$slug))->getName() === 'landing.show';
        } catch (HttpException) {
            return false;
        }
    }
}
