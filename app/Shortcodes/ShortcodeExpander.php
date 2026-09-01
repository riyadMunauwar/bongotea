<?php

namespace App\Shortcodes;

use App\Models\Page;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Expands a Page's stored body on render.
 *
 * `pages.body` keeps the literal `[checkout]` forever — expansion never touches
 * what the merchant typed. The static cache removed the per-request cost that
 * was the only argument for baking expansion in at save time, while expanding
 * on save would make every price edit a data migration over every linked Page.
 * See ADR-0001.
 */
class ShortcodeExpander
{
    public function __construct(
        private readonly ShortcodeRegistry $registry,
        private readonly ShortcodeParser $parser,
    ) {}

    public function expand(Page $page): ExpansionResult
    {
        $cacheable = true;

        $html = $this->parser->replace($page->body, function (Node $node) use ($page, &$cacheable): string {
            try {
                $definition = $this->registry->get($node->name);

                $typed = new Node(
                    $node->name,
                    $definition->attributes()->coerce($node->name, $node->attributes),
                );

                return $definition->render($typed, $page);
            } catch (Throwable $exception) {
                /**
                 * An unexpected failure, as opposed to the expected "nothing to
                 * sell" case a definition handles itself. Log it, emit nothing
                 * visible, and refuse the cache write so the failure cannot
                 * outlive the request that caused it.
                 */
                Log::error('Shortcode expansion failed.', [
                    'shortcode' => $node->name,
                    'page_id' => $page->getKey(),
                    'page_slug' => $page->slug,
                    'exception' => $exception,
                ]);

                $cacheable = false;

                return '<!-- giopages: ['.$node->name.'] failed to render -->';
            }
        });

        return new ExpansionResult($html, $cacheable);
    }
}
