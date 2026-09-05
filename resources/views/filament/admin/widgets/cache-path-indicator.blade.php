{{--
    The cache path indicator. `App\Filament\Widgets\CachePathIndicator` holds
    the reasoning; this is the half that runs in the User's browser.

    Every state is rendered and the one that applies is shown. The alternative —
    building the markup for whichever state won — would put the names of the
    three paths and their remediation somewhere the panel's own test seam cannot
    see, and the browser fetch is deliberately not automated, so the rendered
    copy is the only thing a test can hold this widget to.

    The states start hidden by an inline style rather than by `x-cloak`, so the
    widget cannot flash all four before Alpine reaches it. `x-show` clears that
    style again on the one that wins.
--}}
<x-filament-widgets::widget>
    <x-filament::section
        heading="Page cache"
        description="Which of the three paths answered a request for a published Page, asked from this browser."
    >
        @if ($address === null)
            <x-filament::callout
                :icon="\Filament\Support\Icons\Heroicon::OutlinedInformationCircle"
                color="gray"
                heading="Nothing published to check"
            >
                <x-slot name="description">
                    The cache only ever holds published Pages, and there are
                    none yet. Publish one and this will report which path serves
                    it.
                </x-slot>
            </x-filament::callout>
        @else
            {{--
                A HEAD request: the answer is entirely in the headers, and a
                landing page is a whole document nobody needs to download to be
                told which path sent it. `no-store` keeps the browser's own
                cache out of the way, or the check would be reporting on a copy
                it already held rather than on the installation.

                The fast path is read from the *absence* of the header. Apache
                cannot reliably stamp one onto a static file, so there is no
                marker the fast path could set everywhere; the other two
                announce themselves, and silence means the webserver answered.
            --}}
            <div
                x-data="{
                    state: 'checking',

                    init() {
                        this.check()
                    },

                    async check() {
                        this.state = 'checking'

                        try {
                            const response = await fetch(@js($address), { method: 'HEAD', cache: 'no-store' })

                            this.state = response.ok
                                ? this.pathNamedBy(response.headers.get(@js($header)))
                                : 'failed'
                        } catch (error) {
                            this.state = 'failed'
                        }
                    },

                    pathNamedBy(marker) {
                        if (marker === null) {
                            return 'webserver'
                        }

                        if (marker === @js($fallbackMarker)) {
                            return 'fallback'
                        }

                        if (marker === @js($missMarker)) {
                            return 'miss'
                        }

                        return 'failed'
                    },
                }"
                class="fi-cache-path-indicator"
            >
                <div
                    x-show="state === 'checking'"
                    class="flex items-center gap-x-2 text-sm"
                >
                    <x-filament::loading-indicator class="h-5 w-5" />

                    <span>Checking {{ $address }}&hellip;</span>
                </div>

                <x-filament::callout
                    :icon="\Filament\Support\Icons\Heroicon::OutlinedBolt"
                    color="success"
                    heading="Served by the webserver"
                    x-show="state === 'webserver'"
                    style="display: none"
                >
                    <x-slot name="description">
                        The fast path. Your webserver reads the cached file
                        straight from disk and PHP never boots, which is the
                        whole point of the cache. Nothing to do.
                    </x-slot>
                </x-filament::callout>

                <x-filament::callout
                    :icon="\Filament\Support\Icons\Heroicon::OutlinedExclamationTriangle"
                    color="warning"
                    heading="Served by the fallback reader"
                    x-show="state === 'fallback'"
                    style="display: none"
                >
                    <x-slot name="description">
                        Your Pages are cached, but your webserver is not reading
                        the cache: PHP boots on every visit to hand over a file
                        the webserver could have sent itself. It works, and it
                        costs tens of milliseconds on every ad click. First
                        check the document root points at this application's
                        public directory, or at a symlink to it. Then check your
                        webserver is configured to answer a Page from
                        public/page-cache before it reaches PHP — on nginx that
                        is the configuration in deploy/nginx; on Apache and
                        LiteSpeed it needs the host to allow .htaccess
                        overrides; OpenLiteSpeed does not read .htaccess at all,
                        so its rules go in the vhost.
                    </x-slot>
                </x-filament::callout>

                <x-filament::callout
                    :icon="\Filament\Support\Icons\Heroicon::OutlinedXCircle"
                    color="danger"
                    heading="Not cached at all"
                    x-show="state === 'miss'"
                    style="display: none"
                >
                    <x-slot name="description">
                        The Page was rendered from scratch, so no cached copy
                        was on disk when this ran and every visit is paying for
                        a full render. That render has just written one, so
                        check again. If it still says this, either
                        public/page-cache is not writable by the webserver, or a
                        Shortcode on the Page is failing to render and the Page
                        is deliberately never frozen onto disk.
                    </x-slot>
                </x-filament::callout>

                <x-filament::callout
                    :icon="\Filament\Support\Icons\Heroicon::OutlinedQuestionMarkCircle"
                    color="gray"
                    heading="The check could not complete"
                    x-show="state === 'failed'"
                    style="display: none"
                >
                    <x-slot name="description">
                        The browser could not reach {{ $address }}, or what came
                        back was not this application answering. Check the Page
                        is still published, then check again.
                    </x-slot>
                </x-filament::callout>

                <x-filament::button
                    color="gray"
                    size="sm"
                    tag="button"
                    type="button"
                    class="mt-4"
                    x-on:click="check()"
                    x-bind:disabled="state === 'checking'"
                >
                    Check again
                </x-filament::button>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
