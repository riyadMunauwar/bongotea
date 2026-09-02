<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

abstract class TestCase extends BaseTestCase
{
    /**
     * Dispatch a test request the way a real one arrives: with none of
     * Livewire's per-request state left over from the request before it.
     *
     * Livewire records "a component rendered during this request" in a static
     * property (`SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest`)
     * and, while it stands, injects its `<style>` and `<script>` tags into
     * every 200 HTML response. It is cleared only on Livewire's `flush-state`
     * event, which nothing in a Laravel request lifecycle fires — in production
     * the reset is the PHP process itself, which php-fpm and the CLI server
     * tear down after each request. This application runs no persistent worker
     * (no Octane), so no live response is exposed to another request's flag.
     *
     * A test process is not torn down, so that same flag walks from one test
     * into the next. What hid it for so long is that the companion state
     * deciding whether the tags have *already* been emitted lives on the
     * `FrontendAssets` container singleton, which dies with the application
     * between tests: the flag survives the rebuild, the "already emitted"
     * memory does not, and the next test's response gets the tags. That is a
     * coincidence of where Livewire happens to keep two halves of one fact, not
     * a guarantee — so this flushes per request rather than per test, which
     * also closes the case of two requests inside a single test.
     *
     * What flushing before rather than after costs: Livewire's own test setup
     * helpers are per-request state too, so `Livewire::withoutLazyLoading()`
     * and `Livewire::forceAssetInjection()` are cleared again if they are
     * called ahead of a `$this->get()`. Neither is used in this suite; if one
     * is ever wanted against an HTTP request it belongs inside this method,
     * below the flush, where it cannot be undone behind the test's back.
     *
     * It matters here because `LandingPageTest` asserts a Page comes back byte
     * for byte, and those same bytes are what `StaticPageCache` freezes onto
     * disk for every visitor after the first.
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $cookies
     * @param  array<string, mixed>  $files
     * @param  array<string, mixed>  $server
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null): TestResponse
    {
        Livewire::flushState();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
