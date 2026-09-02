---
paths:
  - 'tests/**'
---

# Tests

## Livewire per-request state is flushed in TestCase::call(), not in test files
Livewire's `SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest` is static, so it survives the application rebuild between tests and makes Livewire inject its `<style>`/`<script>` tags into later HTML responses — which breaks `LandingPageTest`'s byte-for-byte guarantee and would freeze the injected bytes into `StaticPageCache`.

`Tests\TestCase::call()` calls `Livewire::flushState()` before every simulated request, so each request starts like a fresh process. Do not add a file-local `beforeEach`/`afterEach` flush to a test that renders an admin screen; it is already handled, and a per-test hook would not cover two requests inside one test.

Production is not exposed: no persistent worker (no Octane), so the PHP process teardown is the reset.
