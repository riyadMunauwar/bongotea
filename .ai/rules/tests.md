---
paths:
  - 'tests/**'
---

# Tests

## Livewire per-request state is flushed in TestCase::call(), not in test files
Livewire's `SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest` is static, so it survives the application rebuild between tests and makes Livewire inject its `<style>`/`<script>` tags into later HTML responses — which breaks `LandingPageTest`'s byte-for-byte guarantee and would freeze the injected bytes into `StaticPageCache`.

`Tests\TestCase::call()` calls `Livewire::flushState()` before every simulated request, so each request starts like a fresh process. Do not add a file-local `beforeEach`/`afterEach` flush to a test that renders an admin screen; it is already handled, and a per-test hook would not cover two requests inside one test.

Production is not exposed: no persistent worker (no Octane), so the PHP process teardown is the reset.

## The suite runs in one process and needs more than PHP's default memory
`phpunit.xml` sets `memory_limit` to 512M. The feature tests drive the real admin screens through Livewire, and a single Filament form is a few hundred component objects the run holds on to — so what accumulates over a full run is the sum of every screen it has opened, not the cost of any one test. At PHP's 128M default the run dies partway through with a fatal in whatever happened to allocate next (`Field.php`, `ExtendedCompilerEngine.php`), which reads like a defect in the test that was running and is not one.

Note `php artisan test` shells out to Pest without passing `-d` flags on, so `php -d memory_limit=... artisan test` changes nothing. Raise it in `phpunit.xml`, or run `php -d memory_limit=1G vendor/bin/pest` directly.

## The test client sends no cookies on a JSON request unless told to, and a cookie once given stays for the whole test
`postJson()`/`getJson()` go through `prepareCookiesForJsonRequest()`, which sends nothing unless `$this->withCredentials()` was called — so `withCookie(...)->postJson(...)` silently posts with no cookie, and a test proving a bad cookie is tolerated passes vacuously. Call `withCredentials()` first (`CheckoutCaptureTest`'s ownership-bound test does). Plain `get()`/`post()` do send them. And `withCookie()` writes `$defaultCookies` on the TestCase, so every later request in the same test carries it: put the cookie-free visit *before* the owned one, as the note test does, rather than after. A capture response's cookie is read back decrypted with `getCookie(name)`; `getCookie(name, decrypt: false)` is how to assert it was encrypted on the wire.
