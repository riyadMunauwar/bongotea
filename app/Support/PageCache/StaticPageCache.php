<?php

namespace App\Support\PageCache;

use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;

/**
 * Writes rendered landing pages to disk as static HTML so nginx can serve them
 * with `try_files` and PHP never boots on a cache hit.
 *
 * The cache key is the page's slug and nothing else. Query strings are
 * deliberately excluded: every ad click arrives with `?fbclid=...`, so a
 * query-sensitive key would score a ~0% hit rate on the only traffic that
 * matters. Consequence: a landing page may not vary its server-rendered HTML
 * by query parameter. Anything that must vary reads `location.search` client
 * side.
 *
 * Every entry is written twice — `<slug>.html` and a pre-compressed
 * `<slug>.html.gz` with a matching mtime — so nginx's `gzip_static` can answer
 * without spending CPU on compression per request. The two files are written
 * and removed as a unit; a `.gz` outliving its `.html` is served as stale
 * content with no error anywhere, which is the failure this class exists to
 * make impossible.
 */
class StaticPageCache
{
    /**
     * Flat, lowercase slugs only — the character class that makes directory
     * traversal impossible by construction rather than by sanitising.
     */
    private const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public function __construct(
        private readonly Filesystem $files,
        private readonly string $directory,
    ) {}

    /**
     * Write a rendered page to the cache, replacing any existing entry.
     */
    public function put(string $slug, string $html): void
    {
        $this->assertValidSlug($slug);

        $this->files->ensureDirectoryExists($this->directory);

        $htmlPath = $this->htmlPath($slug);
        $gzipPath = $this->gzipPath($slug);

        /**
         * Written to a sibling temp file and renamed so a concurrent request
         * never reads a half-written page. Level 9: this is off the request's
         * critical path, and the bytes are served thousands of times.
         */
        $this->writeAtomically($htmlPath, $html);
        $this->writeAtomically($gzipPath, (string) gzencode($html, 9));

        /**
         * nginx recommends the compressed file share the original's timestamp.
         * Both files are stamped from one reading of the clock so they cannot
         * straddle a second boundary.
         */
        $timestamp = time();
        touch($htmlPath, $timestamp);
        touch($gzipPath, $timestamp);
    }

    /**
     * Remove a single page from the cache, both representations.
     *
     * Silent when the entry is absent: invalidation fires on every save,
     * including saves of pages that were never cached.
     */
    public function forget(string $slug): void
    {
        $this->assertValidSlug($slug);

        /**
         * Filtered to what exists rather than deleting blind: invalidation
         * fires on every save, and most saves are of pages nothing ever
         * requested, so unlinking a missing file is the common case and must
         * not raise a warning.
         */
        $present = array_filter(
            [$this->htmlPath($slug), $this->gzipPath($slug)],
            fn (string $path): bool => $this->files->exists($path),
        );

        if ($present !== []) {
            $this->files->delete($present);
        }
    }

    /**
     * Empty the cache. The blunt instrument for a template or global change,
     * where per-URL invalidation has nothing to enumerate.
     */
    public function flush(): void
    {
        if ($this->files->isDirectory($this->directory)) {
            $this->files->cleanDirectory($this->directory);
        }
    }

    public function has(string $slug): bool
    {
        $this->assertValidSlug($slug);

        return $this->files->exists($this->htmlPath($slug));
    }

    /**
     * Absolute path of the cached HTML for a slug. Exposed for assertions and
     * for the artisan command; callers should not derive this themselves.
     */
    public function htmlPath(string $slug): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.$slug.'.html';
    }

    public function gzipPath(string $slug): string
    {
        return $this->htmlPath($slug).'.gz';
    }

    /**
     * @return list<string> Every slug currently held in the cache.
     */
    public function cachedSlugs(): array
    {
        if (! $this->files->isDirectory($this->directory)) {
            return [];
        }

        return array_values(array_map(
            fn (string $path): string => basename($path, '.html'),
            $this->files->glob($this->directory.DIRECTORY_SEPARATOR.'*.html') ?: [],
        ));
    }

    private function assertValidSlug(string $slug): void
    {
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            throw new InvalidArgumentException(
                "[{$slug}] is not a cacheable slug. Flat, lowercase, hyphen-separated slugs only.",
            );
        }
    }

    private function writeAtomically(string $path, string $contents): void
    {
        $temporaryPath = $path.'.'.bin2hex(random_bytes(6)).'.tmp';

        $this->files->put($temporaryPath, $contents);
        $this->files->move($temporaryPath, $path);
    }
}
