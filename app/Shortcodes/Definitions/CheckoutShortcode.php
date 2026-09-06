<?php

namespace App\Shortcodes\Definitions;

use App\Models\Page;
use App\Shortcodes\AttributeSchema;
use App\Shortcodes\Node;
use App\Shortcodes\ShortcodeDefinition;

/**
 * The only shortcode v1 registers.
 *
 * It expands to a self-contained island: markup, a scoped <style> and an inline
 * <script>. Nothing touches <head> and nothing loads an external asset, because
 * a Page's document belongs entirely to the merchant — this is the one escape
 * hatch from that rule, and it reaches no further than the point of expansion.
 *
 * Declares no attributes. The schema mechanism ships unexercised on purpose;
 * see ADR-0002.
 */
class CheckoutShortcode implements ShortcodeDefinition
{
    public const NAME = 'checkout';

    public function name(): string
    {
        return self::NAME;
    }

    public function attributes(): AttributeSchema
    {
        /**
         * No `product=` attribute in v1, so nothing references a Product by
         * key — which is why Product needs no slug of its own.
         */
        return AttributeSchema::none();
    }

    public function render(Node $node, Page $page): string
    {
        /** Soft-deleted Products have already fallen out of this relation. */
        $products = $page->products;

        if ($products->isEmpty()) {
            /**
             * Publishing a Page with no linked Product is blocked at save time,
             * so this can only be reached after publish — every linked Product
             * soft-deleted under a live campaign. Losing one of several is not
             * this case: the rest keep selling at their own prices, and the
             * Pages list is what says so. Zero visible bytes and a diagnosable
             * comment: the Page keeps rendering, the checkout goes away, and
             * the operator finds out the way they would anyway, from
             * conversions going to zero.
             *
             * A visible "temporarily unavailable" notice was rejected. Injecting
             * our own styled banner at an unknown position in a document we do
             * not own is exactly the boundary this system exists to respect.
             */
            return '<!-- giopages: checkout unavailable (no product linked) -->';
        }

        return view('shortcodes.checkout', [
            'page' => $page,

            /** Everything the Page sells, each with a quantity of its own. */
            'products' => $products,

            /**
             * Whether the Customer picks which of them to buy, rather than
             * buying all of them. Asked of the Page rather than of the Config,
             * because a picker on a Page down to one Product is a box between a
             * Customer and the only thing on offer.
             *
             * The same question the Capture asks, so the form cannot draw a
             * checkbox the server ignores or sell something nobody ticked.
             */
            'picksProducts' => $page->offersProductChoice(),

            /**
             * What this Page asks for, in its own words and its own order —
             * and only what it asks: a field the Merchant switched off is not
             * rendered at all. The same list compiles into the rules the
             * Capture is checked against, so the form and the server cannot
             * disagree about which questions exist.
             */
            'fields' => $page->checkout_config->enabledFields(),

            /**
             * What this Page charges to get the parcel to the door, in the
             * Merchant's own words. None means no delivery row and nothing
             * added; one is added with no question asked; two or more are a
             * choice the Customer makes.
             *
             * Read from the same place a Capture reads it, so the island cannot
             * quote a total the door disagrees with — a form totalling less
             * than the courier collects is a refused parcel and a fee in both
             * directions.
             */
            'deliveryOptions' => $page->checkout_config->deliveryOptions,

            /**
             * How this Page's Checkout looks: what the Merchant chose, and the
             * default for every token they left alone.
             *
             * Completed here rather than in the view so the root always
             * declares the whole named set, which is what lets every rule below
             * it read a token instead of carrying a fallback of its own. A
             * field's own overrides are not this — they ride on that field's
             * wrapper and are read off the field.
             */
            'styleTokens' => $page->checkout_config->styleTokens->completed(),
            'maxQuantity' => (int) config('giopages.checkout.max_quantity'),
        ])->render();
    }
}
