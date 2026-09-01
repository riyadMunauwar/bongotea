<?php

namespace App\Shortcodes;

use App\Models\Page;

/**
 * A Shortcode Definition declares three things and nothing else: its name, the
 * attributes it accepts, and one render().
 *
 * render() must return **visitor-independent** HTML. It runs once per cache
 * miss and its output is served byte-identical to every visitor from disk —
 * there is no session, no cookie, no CSRF token and no query string available
 * to it. Anything per-visitor has to be a client-side island in the emitted
 * markup, not something this method reads.
 */
interface ShortcodeDefinition
{
    /**
     * The registry key and the tag the parser matches, e.g. `checkout`.
     */
    public function name(): string;

    public function attributes(): AttributeSchema;

    /**
     * @param  Node  $node  the parsed `{name, attributes}` pair
     */
    public function render(Node $node, Page $page): string;
}
