<?php

namespace App\Shortcodes;

/**
 * The expanded HTML plus whether it is safe to freeze onto disk.
 *
 * `cacheable` is the load-bearing half. Fail-soft on its own would write a
 * broken page into the static cache, where nginx serves it to every visitor
 * until someone happens to save something. Skipping the write costs a
 * framework boot per request instead, which is recoverable.
 */
class ExpansionResult
{
    public function __construct(
        public readonly string $html,
        public readonly bool $cacheable,
    ) {}
}
