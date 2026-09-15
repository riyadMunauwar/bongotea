<?php

namespace App\Support\PageCache;

/**
 * Serves a cached landing page when the webserver did not.
 *
 * The static cache is only fast because the webserver reads the file itself and
 * PHP never boots. That takes configuration, and a merchant on shared hosting
 * may have the front controller running while the cache rules never took: an
 * OpenLiteSpeed vhost carrying the stock Laravel rewrite, where nobody pasted
 * `deploy/openlitespeed/giopages.conf`; a host that rewrites to the front
 * controller from its own configuration under `AllowOverride None`, so
 * `public/.htaccess` is never read at all; a `public/.htaccess` an upgrade or a
 * control panel replaced with the framework's stock copy. Their site works —
 * pages load, checkouts capture — and they simply pay a full framework boot on
 * every click of the ad traffic they bought, with no signal anywhere. This
 * class is what catches them: slower than the webserver reading the file, and
 * far faster than booting the framework.
 *
 * Two neighbouring misconfigurations sit outside that reach, and are worth
 * naming because the answer to them is not this class. Rewriting disabled
 * entirely takes the front controller's own rewrite with it — the two share the
 * `<IfModule mod_rewrite.c>` block — so `/promo` matches no file and no PHP
 * runs for this class to be part of. A document root pointed at the wrong
 * directory misses here too: the lookup below keeps the whole request path, so
 * an install reached at `/public/promo` asks for `page-cache/public/promo.html`
 * and never finds it. The fast path misses that layout as well, by its own
 * route, which is why `deploy/README.md` marks it unsupported and
 * `CachePathIndicator` is what tells the merchant.
 *
 * It is shipped enabled and self-disabling by construction. On a correctly
 * configured host it never runs on a cache hit, because the request never
 * reaches PHP at all; on every request that does reach PHP it costs a method
 * check and at most one existence test.
 *
 * It holds no dependencies at all, which is what lets one definition be loaded
 * two ways: `require_once`d by the front controller above the Composer
 * autoloader in production, and autoloaded normally in tests. Nothing here may
 * reference another class.
 *
 * **Existence is the contract.** There is no slug pattern here and no path
 * translation: the reader builds the candidate path, confirms it sits inside
 * the cache directory, confirms the file exists, and serves it. A file only
 * exists because `StaticPageCache` already validated the slug that produced it,
 * so validating it again would be a second copy of a rule that belongs to the
 * writer. This mirrors nginx, whose `try_files` knows nothing about slugs
 * either — and it means a change to what counts as a legal slug cannot break
 * the readers.
 *
 * The one thing that is duplicated, knowingly, is the shape of a filename:
 * `<slug>.html` and its `.gz` sibling are spelled out here as well as in
 * `StaticPageCache::htmlPath()`. Borrowing them would mean referencing that
 * class, and this one may reference nothing. The webserver configurations
 * spell the same two suffixes out a third time, which is the company this is
 * in. The pairing is pinned by `tests/Feature/StaticPageCacheTest.php`, which
 * holds the writer to both files and one mtime, and by the gzip cases in
 * `tests/Feature/FallbackPageCacheReaderTest.php`, which hold this reader to
 * the `.gz` sibling it negotiates for.
 *
 * The request URI is deliberately **not** URL-decoded. A legal slug is
 * lowercase alphanumeric and hyphens and never needs decoding, so leaving the
 * escapes intact turns a traversal attempt into a filename that cannot exist,
 * with the containment check as a second lock.
 */
class FallbackPageCacheReader
{
    /**
     * The one header that names which of the three paths served a page.
     *
     * Absence carries meaning: the webserver path sets nothing, because Apache
     * cannot reliably stamp a header onto a static file and so the fast path
     * has no marker it could set everywhere. The other two announce themselves.
     */
    public const HEADER = 'X-GioPages-Cache';

    public const FALLBACK_MARKER = 'fallback';

    public const MISS_MARKER = 'miss';

    /**
     * The Site Root's entry, spelled here as well as in `StaticPageCache`,
     * `public/.htaccess` and both deploy configurations — the same knowing
     * duplication as `<slug>.html` and its `.gz` sibling, and for the same
     * reason: this class may reference nothing, so it cannot borrow the name.
     */
    private const FRONT_ENTRY = '_front';

    public function __construct(private readonly string $directory) {}

    /**
     * Answer the request from the cache and end it, or return and let the
     * framework boot.
     *
     * The emitter lives here rather than in the front controller so that
     * framework-shipped file holds no logic worth testing: a framework upgrade
     * that rewrites `public/index.php` is a two-line reapplication.
     *
     * @param  array<string, mixed>  $server  The request's `$_SERVER`.
     */
    public function serve(array $server): void
    {
        $method = (string) ($server['REQUEST_METHOD'] ?? '');

        $hit = $this->resolve(
            (string) ($server['REQUEST_URI'] ?? ''),
            $method,
            (string) ($server['HTTP_ACCEPT_ENCODING'] ?? ''),
        );

        if ($hit === null) {
            return;
        }

        foreach ($hit['headers'] as $header) {
            header($header);
        }

        if ($hit['sendsBody']) {
            readfile($hit['path']);
        }

        exit;
    }

    /**
     * Decide what, if anything, this request is answered with.
     *
     * The headers and the body suppression come back from here rather than
     * living in the emitter, so the whole decision — which file, what the
     * client is told about it, and whether it is sent — is one pure value a
     * test can read. What is left in the emitter is a header loop and a read.
     *
     * @return array{path: string, headers: list<string>, sendsBody: bool}|null The file to serve,
     *                                                                          the headers to send with it, and whether the body follows them, or null to
     *                                                                          let the framework boot.
     */
    public function resolve(string $requestUri, string $requestMethod, string $acceptEncoding = ''): ?array
    {
        /**
         * Only the methods a static file can honestly answer. Without this a
         * POST to a checkout that happens to share a page's slug would be
         * answered from disk and the order would never reach the application.
         */
        if ($requestMethod !== 'GET' && $requestMethod !== 'HEAD') {
            return null;
        }

        /**
         * The key is the slug and nothing else: every ad click arrives with
         * `?fbclid=...`, so a query-sensitive lookup would never hit. The
         * trailing slash is trimmed so the fallback answers at the same
         * addresses the fast path canonicalises to.
         */
        $path = rtrim(explode('?', $requestUri, 2)[0], '/');

        $directory = realpath($this->directory);

        if ($directory === false) {
            return null;
        }

        /**
         * The Site Root resolves to a constant, because it has no Slug to be
         * keyed on and this reader cannot ask which Page a Merchant
         * designated — it may reference nothing, and the webserver
         * configurations that do the same lookup could not read a database
         * even if they wanted to (ADR-0023).
         *
         * This is a constant and not a translation: nothing off the wire is
         * transformed into it, and the answer is still existence — the file is
         * there because the application wrote the Front Page or the
         * Placeholder to it, or it is not there and the framework boots. That
         * is what keeps issue #23 removed.
         */
        $entry = $path === ''
            ? self::FRONT_ENTRY
            : ltrim($path, '/');

        $file = realpath($directory.DIRECTORY_SEPARATOR.$entry.'.html');

        /**
         * `realpath` answers both questions at once: it returns false for a
         * file that does not exist, and it resolves the path that a traversal
         * would reach so containment can be checked against where the file
         * actually is rather than against how it was spelled.
         */
        if ($file === false || ! str_starts_with($file, $directory.DIRECTORY_SEPARATOR) || ! is_file($file)) {
            return null;
        }

        $compressed = $file.'.gz';

        $servesCompressed = $this->acceptsGzip($acceptEncoding) && is_file($compressed);

        /**
         * PHP does not strip a body for HEAD the way a webserver does, so
         * whether one follows the headers is decided here rather than left to
         * the emitter.
         */
        return $this->answer(
            $servesCompressed ? $compressed : $file,
            $servesCompressed,
            $requestMethod === 'GET',
        );
    }

    /**
     * Whether the client will actually decode gzip.
     *
     * The header is parsed rather than searched for the substring because `q=0`
     * is an explicit refusal: a client that said no gzip and is handed gzipped
     * bytes renders nothing at all.
     */
    private function acceptsGzip(string $acceptEncoding): bool
    {
        foreach (explode(',', str_replace([' ', "\t"], '', strtolower($acceptEncoding))) as $candidate) {
            [$token, $quality] = array_pad(explode(';q=', $candidate, 2), 2, null);

            if ($token === 'gzip') {
                return $quality === null || (float) $quality > 0;
            }
        }

        return false;
    }

    /**
     * @return array{path: string, headers: list<string>, sendsBody: bool}
     */
    private function answer(string $path, bool $compressed, bool $sendsBody): array
    {
        $headers = [
            self::HEADER.': '.self::FALLBACK_MARKER,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Length: '.filesize($path),

            /**
             * The body depends on what the client accepts, announced whichever
             * representation won: a shared cache in front of this installation
             * must not hand a compressed copy to a client that cannot read it.
             */
            'Vary: Accept-Encoding',
        ];

        if ($compressed) {
            $headers[] = 'Content-Encoding: gzip';
        }

        return ['path' => $path, 'headers' => $headers, 'sendsBody' => $sendsBody];
    }
}
