<?php

use App\Models\Page;
use App\Shortcodes\AttributeSchema;
use App\Shortcodes\Node;
use App\Shortcodes\ShortcodeDefinition;
use App\Shortcodes\ShortcodeRegistry;
use App\Support\PageCache\StaticPageCache;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Page cache isolation
|--------------------------------------------------------------------------
|
| The static cache is pointed at a scratch directory for every feature test.
| public/page-cache is served by nginx publish-blind, so a file left there by
| the suite would be real content on a real site.
|
*/

pest()->beforeEach(function (): void {
    $this->cacheDirectory = storage_path('framework/testing/page-cache-'.bin2hex(random_bytes(4)));

    $this->app->instance(StaticPageCache::class, new StaticPageCache(
        $this->app->make(Filesystem::class),
        $this->cacheDirectory,
    ));

    $this->cache = $this->app->make(StaticPageCache::class);
})->in('Feature');

pest()->afterEach(function (): void {
    if (isset($this->cacheDirectory)) {
        (new Filesystem)->deleteDirectory($this->cacheDirectory);
    }
})->in('Feature');

/*
|--------------------------------------------------------------------------
| Livewire state isolation
|--------------------------------------------------------------------------
|
| Livewire remembers, for the length of a request, that it rendered a
| component, and injects its own styles and scripts into every HTML response
| after that. A test process is one long-lived request as far as that flag is
| concerned, so a test that opens the admin leaves Livewire injecting itself
| into the public Pages rendered by every test that follows.
|
*/

pest()->beforeEach(function (): void {
    Livewire::flushState();
})->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * Register a throwaway Shortcode Definition for the duration of one test.
 *
 * The registry is a singleton rebuilt per test, so this is how a test
 * exercises the shortcode *mechanism* without coupling itself to the one
 * shortcode v1 happens to ship.
 *
 * @param  Closure(Node, Page): string  $render
 */
function registerShortcode(string $name, Closure $render): void
{
    app(ShortcodeRegistry::class)->register(new class($name, $render) implements ShortcodeDefinition
    {
        public function __construct(
            private readonly string $name,
            private readonly Closure $render,
        ) {}

        public function name(): string
        {
            return $this->name;
        }

        public function attributes(): AttributeSchema
        {
            return AttributeSchema::none();
        }

        public function render(Node $node, Page $page): string
        {
            return ($this->render)($node, $page);
        }
    });
}
