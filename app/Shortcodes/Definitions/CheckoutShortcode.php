<?php

namespace App\Shortcodes\Definitions;

use App\Models\Page;
use App\Shortcodes\AttributeSchema;
use App\Shortcodes\Node;
use App\Shortcodes\ShortcodeDefinition;
use App\Support\Checkout\PricingFingerprint;
use App\Support\Settings\GeneralSettings;

/**
 * The only shortcode v1 registers.
 *
 * It expands to a self-contained island: markup, a scoped <style> and an inline
 * <script>. Nothing here touches <head> and nothing loads an external asset,
 * because a Page's document belongs entirely to the merchant — this expansion
 * reaches no further than the point it happens at.
 *
 * One thing does reach <head>, and it is not this: `TrackingSnippet` injects
 * the Pixel there after expansion, on a Page that reports to a Destination,
 * and that snippet is the one thing on a Page that loads an external asset.
 *
 * Declares no attributes. The schema mechanism ships unexercised on purpose;
 * see ADR-0002.
 */
class CheckoutShortcode implements ShortcodeDefinition
{
    public const NAME = 'checkout';

    /**
     * The one thing the island says about itself to the rest of the page: a
     * visitor has started filling the Checkout, and here is what they have
     * ticked.
     *
     * Named here rather than wherever it is listened for, because it is the
     * island's own announcement — a Page carrying no listener raises it into
     * nothing, and the island must never learn who is listening. A Pixel is
     * what listens today; see `.ai/rules/tracking.md`.
     */
    public const STARTED_EVENT = 'gio:checkout-started';

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

        $general = app(GeneralSettings::class);

        return view('shortcodes.checkout', [
            'page' => $page,

            /** What the island calls out when a visitor starts filling it in. */
            'startedEvent' => self::STARTED_EVENT,

            /** Everything the Page sells, each with a quantity of its own. */
            'products' => $products,

            /**
             * Whether the Product List is drawn at all. Off, the panel is
             * omitted and every Product still posts a quantity of one, so the
             * Order captured is the same complete Order. Read from the Config
             * because it is the Merchant's switch alone; whether a choice is
             * offered on top of it is the Page's, below.
             */
            'showProducts' => $page->checkout_config->showProducts,

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
             * Which of them the form opens ticked, so a Customer meets a price
             * rather than a shelf totalling nothing. Asked of the Page for the
             * same reason the question above it is: a tick against something
             * this Page no longer sells is a form opening on an item it cannot
             * offer.
             *
             * Configuration and not a figure handed over at the door, so it is
             * deliberately absent from the fingerprint below — a Merchant
             * changing their mind about which box opens ticked must never
             * refuse an Order somebody is half-way through typing (ADR-0010).
             */
            'defaultSelection' => $page->defaultSelection(),

            /**
             * How the island is arranged: the stacked form the Checkout has
             * always drawn, or the form on its own for a Page whose own markup
             * already sold the Product above the fold.
             *
             * The enum rather than a boolean, because the view needs both what
             * it draws and the class it puts on the root — and a third layout
             * is then a case here rather than a second flag threaded through.
             */
            'layout' => $page->checkout_config->layout,

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

            /**
             * Everything the island says, in the Merchant's words or the
             * design's: the order button and the line beneath it, the totals
             * labels, and what each Payment Method is called. The button's
             * wording may name the total, which the view fills in from the
             * same figure the summary shows.
             *
             * Copy and not a figure, so like the Default Selection it is
             * deliberately absent from the fingerprint below.
             */
            'words' => $page->checkout_config->words,

            /**
             * How the Customer is asked to pay, if they are asked at all: the
             * Page's offered methods in its order, with or without logos, or
             * nothing where the only method is Cash on Delivery and the Page
             * hides that choice — which is every Page as it stands today.
             *
             * Read from the same reading the Capture validates against, so the
             * form cannot draw a method the server refuses. Configuration and
             * not a figure handed over at the door, so like the layout and the
             * copy it is deliberately absent from the fingerprint below: the
             * total is the same whichever method is picked, and a Merchant
             * reordering methods must never refuse an Order somebody is
             * half-way through typing (ADR-0013).
             */
            'paymentChooser' => $page->checkout_config->paymentChooser(),

            /**
             * What the courier will collect, written into the form so a
             * Capture can tell whether the figures on this visitor's screen are
             * still the figures the server would charge.
             *
             * Taken from the Page these bytes are being drawn from, which is
             * the same Page the Capture recomputes it from, so the form and the
             * check cannot disagree about the prices (ADR-0007).
             */
            'fingerprint' => PricingFingerprint::for($page),

            /**
             * The installation-wide figures the island prints and enforces:
             * the currency beside every amount and the ceiling on a line's
             * quantity. General Settings, read here rather than in the view,
             * so the view knows nothing about where a Setting comes from.
             */
            'currencySymbol' => $general->currencySymbol,
            'currencyDecimals' => $general->currencyDecimals,
            'maxQuantity' => $general->checkoutMaxQuantity,
        ])->render();
    }
}
