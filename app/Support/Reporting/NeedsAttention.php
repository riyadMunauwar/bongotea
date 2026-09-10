<?php

namespace App\Support\Reporting;

/**
 * What the Needs Attention widget draws: the Orders an operator must act
 * on as of one instant, regardless of any Reporting Window. A to-do list
 * rather than a report, which is why it carries no window and why an empty
 * one is a plain statement that there is nothing to do.
 */
final readonly class NeedsAttention
{
    public function __construct(
        /**
         * In `AttentionReason`'s order, oldest first within each, so the
         * longest-waiting call is at the top.
         *
         * @var list<NeedsAttentionRow>
         */
        public array $rows,
    ) {}
}
