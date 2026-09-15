<?php

namespace App\Support\Pages;

use App\Models\Page;

/**
 * The canonical link a served Page carries, naming its own Slug.
 *
 * A Front Page answers at two addresses — its Slug and the Site Root — serving
 * one set of bytes from one render (ADR-0023). One document at two addresses
 * asks a crawler to choose between them, and left to choose it may well pick
 * the Site Root: shorter, and more linked to. That is the wrong one. A Slug is
 * claimed for good and may be baked into ad creatives that outlive the Page;
 * the Site Root is a dropdown a Merchant can repoint on a Tuesday. Ranking has
 * to accrue to the permanent identity, or a settings change nobody connected to
 * search quietly moves the indexed content out from under the address that
 * earned it.
 *
 * So the link is **self-referential and drawn on every Page**, not only on the
 * Front Page. That is not thoroughness, it is the byte copy: nothing in the
 * cached HTML may depend on whether a Page is the Front Page, or the bytes stop
 * being a copy and designating one would need a re-render rather than a
 * re-write. One value that is already correct at both addresses costs nothing
 * and keeps the two entries identical.
 *
 * It cannot ride in an HTTP header. The fast path is a webserver reading a
 * static file, and Apache cannot reliably stamp a header onto one — the same
 * constraint that made *absence* the marker for that path. Into the HTML or
 * nowhere.
 *
 * This is the second thing injected into a document the Merchant owns, and it
 * is a wider intrusion than the Pixel: ADR-0017's is drawn only where a
 * Merchant configured a Destination, and this is drawn everywhere. Two things
 * hold it down — it goes in only where there is a `<head>` to put it in, and it
 * stands down entirely for a Merchant who wrote a canonical of their own.
 */
class CanonicalLink
{
    /**
     * Any canonical the Merchant already wrote, however they spelled it.
     *
     * Attribute order, quoting and case are all free, because a Merchant's
     * document is a Merchant's document. Getting this wrong emits a second
     * canonical, and a crawler that finds two discards both — so the failure
     * of a miss here is worse than injecting nothing at all.
     */
    private const AUTHORED = '/<link\b[^>]*\brel\s*=\s*["\']?\s*canonical\b/i';

    /**
     * Where the link goes, and the only place it is worth going.
     *
     * A canonical outside the head is ignored by the crawlers that read one, so
     * a document with no head is left exactly as the Merchant wrote it rather
     * than having a `<link>` appended that would buy nothing.
     */
    private const INSERTION_POINT = '</head>';

    public function injectedInto(string $html, Page $page): string
    {
        $position = stripos($html, self::INSERTION_POINT);

        if ($position === false) {
            return $html;
        }

        /**
         * Only the head is searched for the Merchant's own canonical, and the
         * order of these two checks is the reason why.
         *
         * A `rel="canonical"` in the *body* is not a canonical — this class's
         * own insertion rule says so, and so does every crawler that reads one.
         * It is a code sample, a documentation page about canonicals, an
         * escaped tag in a blog post. Scanning the whole document for it would
         * let any of those suppress the real link, and the Front Page would
         * then answer at two addresses with no canonical between them: exactly
         * the outcome this exists to prevent, reached by a Merchant writing
         * about the feature.
         */
        if (preg_match(self::AUTHORED, substr($html, 0, $position)) === 1) {
            return $html;
        }

        return substr_replace($html, $this->forPage($page)."\n", $position, 0);
    }

    /**
     * The tag itself. Absolute, because a canonical must be — and absolute
     * URLs are already frozen into cached HTML, so this depends on nothing the
     * served bytes did not already depend on.
     */
    private function forPage(Page $page): string
    {
        $address = e(route('landing.show', $page->slug));

        return '<link rel="canonical" href="'.$address.'">';
    }
}
