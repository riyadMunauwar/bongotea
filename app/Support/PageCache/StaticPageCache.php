<?php

namespace App\Support\PageCache;

use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use RuntimeException;

/**
 * Writes rendered landing pages to disk as static HTML so a cache hit can be
 * answered without the framework booting.
 *
 * This class only writes and deletes; it never reads. Reading is not entirely
 * outside the application, though. A configured webserver reads the file itself
 * and PHP is never contacted — nginx does it with `try_files` — and where the
 * webserver was never configured to, `FallbackPageCacheReader` reads it above
 * the Composer autoloader. Existence is the whole contract between writer and
 * readers: a file is served because it is there, so the slug rule below is this
 * class's alone and no reader repeats it.
 *
 * The cache key is the page's slug and nothing else. Query strings are
 * deliberately excluded: every ad click arrives with `?fbclid=...`, so a
 * query-sensitive key would score a ~0% hit rate on the only traffic that
 * matters. Consequence: a landing page may not vary its server-rendered HTML
 * by query parameter. Anything that must vary reads `location.search` client
 * side.
 *
 * Every entry is written twice — `<slug>.html` and a pre-compressed
 * `<slug>.html.gz` with a matching mtime — so nginx's `gzip_static` and the
 * fallback reader's own negotiation can answer without spending CPU on
 * compression per request. The two files are written and removed as a unit; a
 * `.gz` outliving its `.html` is served as stale content with no error
 * anywhere, which is the failure this class exists to make impossible.
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
     *
     * Returns the number of Pages removed, counted as HTML files: the gzip
     * siblings go too, but they are a representation of a Page rather than a
     * Page of their own, and the count is read by an operator checking that a
     * release cleared what they expected it to.
     *
     * Counted and refused from what each unlink reported rather than from a
     * second reading of the directory. On shared hosting the release runs as a
     * different user from the one PHP-FPM wrote the files as, so an unlink can
     * fail; reporting a clean flush over a surviving file would leave the
     * webserver answering ad clicks from the previous version's HTML with the
     * operator told the opposite.
     *
     * A file the flush is told it could not remove is the only thing that is
     * a failure. This runs against a live site — `deploy/README.md` has it in
     * the release — so a cache miss served while it is working writes a fresh
     * entry behind it, and a directory read afterwards cannot tell that from
     * one of PHP-FPM's the release user could not touch. Reporting the healthy
     * case as a permissions problem would stop the release script on the one
     * sign the cache is doing its job.
     *
     * @throws RuntimeException if the cache could not be read, or if anything
     *                          could not be removed.
     */
    public function flush(): int
    {
        if (! $this->files->isDirectory($this->directory)) {
            return 0;
        }

        /**
         * Every entry, not just the HTML: a `.gz` outliving its `.html` is
         * served as stale content with no error anywhere.
         */
        $entries = $this->entriesMatching($this->directory.DIRECTORY_SEPARATOR.'*');

        $removed = 0;
        $survivors = [];

        foreach ($entries as $entry) {
            if (! $this->files->delete($entry)) {
                $survivors[] = $entry;

                continue;
            }

            if (str_ends_with($entry, '.html')) {
                $removed++;
            }
        }

        if ($survivors !== []) {
            throw new RuntimeException(sprintf(
                'The page cache at [%s] still holds %d file(s) after being flushed. Check the permissions of the user running this.',
                $this->directory,
                count($survivors),
            ));
        }

        return $removed;
    }

    public function has(string $slug): bool
    {
        $this->assertValidSlug($slug);

        return $this->files->exists($this->htmlPath($slug));
    }

    /**
     * Absolute path of the cached HTML for a slug. Exposed so the suite can
     * assert against the file the writer produced; callers should not derive
     * this themselves.
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
     * @return list<string>
     *
     * @throws RuntimeException
     */
    private function entriesMatching(string $pattern): array
    {
        $matches = $this->files->glob($pattern);

        /**
         * `glob()` reports an unreadable directory by returning false, which
         * would otherwise read as an empty cache — the one answer that must
         * never be guessed here.
         */
        if ($matches === false) {
            throw new RuntimeException("The page cache at [{$this->directory}] could not be read.");
        }

        return array_values($matches);
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
