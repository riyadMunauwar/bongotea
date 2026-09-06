<?php

namespace App\Support\Checkout;

use App\Models\Page;
use App\Models\Product;

/**
 * What the courier will collect, reduced to a string the rendered form can
 * carry and a Capture can check it against.
 *
 * A visitor is very often holding HTML written to disk days ago: Pages are
 * served from the static cache, and an invalidation clears the file without
 * reaching the tab already open on someone's phone. So the figures on their
 * screen are the figures as they stood at render, and the only way to know
 * whether the server still agrees with them is to have written them down.
 *
 * Everything that decides the amount handed over at the door is in here — the
 * Products this Page sells, their prices, and what each Delivery Option costs —
 * and nothing else is. A field added, relabelled or restyled since the visitor
 * loaded the page leaves this untouched, because rejecting an order someone has
 * half-typed on a Page that is spending money has to be worth something, and a
 * colour change is not (ADR-0007).
 *
 * Not a secret and not signed. Anyone can compute the current one, and a
 * submission carrying it is simply a submission from a current form; what
 * nobody can do is present an *old* one and be captured against it, which is
 * the whole of what this prevents.
 */
class PricingFingerprint
{
    /**
     * How many hex characters of the digest are carried — 128 bits of it.
     *
     * This rides inside every cached page and only has to differ whenever the
     * figures differ. There is nothing to forge, so the remaining half of the
     * SHA-256 would buy bytes and no property.
     */
    private const HEX_LENGTH = 32;

    /**
     * The fingerprint of a Page as it stands now.
     *
     * Everything reads the figures through here — the view is handed this, and
     * `matches()` recomputes it from the same Page — so the form and the check
     * cannot read different things. Anything asking the Products or the
     * Delivery Options directly would be a second source, and a second source
     * is a drift waiting to happen.
     */
    public static function for(Page $page): string
    {
        return substr(hash('sha256', self::figures($page)), 0, self::HEX_LENGTH);
    }

    /**
     * Whether a submission was written against the Page as it stands now.
     *
     * Anything that is not a string fails, which is how a submission carrying
     * no fingerprint at all is refused — including one from a page cached
     * before this check existed, which is exactly the stale HTML it is for.
     */
    public static function matches(Page $page, mixed $submitted): bool
    {
        return is_string($submitted) && $submitted === self::for($page);
    }

    /**
     * Everything priced about this Page, in one canonical spelling.
     *
     * Products by id and never by position: reordering them in the admin
     * changes which one is drawn first and nothing about what is collected, and
     * a fingerprint that moved with the arrangement would refuse orders over a
     * merchandising tidy-up. A Product soft-deleted since the render does move
     * it — that Product's price was in the total the visitor agreed to, and
     * quietly selling them the rest collects a different figure than the one on
     * their screen.
     *
     * Delivery Options by position, which is the opposite treatment and for the
     * opposite reason. Their labels are not in here — ADR-0007 keeps the
     * fingerprint to figures, and renaming one is already refused where it
     * matters, by a submission naming an arrangement the Page no longer offers.
     * Without the labels, position is the only thing tying an amount to the
     * arrangement it belongs to: a bare set of amounts cannot tell a reorder
     * from the Merchant swapping 60 and 120 between "Inside Dhaka" and
     * "Outside Dhaka", and that second one is a repricing of both. So a reorder
     * refuses in-flight forms, which is the price of not being able to tell it
     * apart from a change that would collect the wrong figure at the door.
     */
    private static function figures(Page $page): string
    {
        $products = $page->products
            ->mapWithKeys(fn (Product $product): array => [$product->getKey() => $product->price])
            ->sortKeys()
            ->map(fn (int $price, int $id): string => $id.':'.$price)
            ->values()
            ->all();

        $delivery = array_map(
            fn (DeliveryOption $option): string => (string) $option->amount,
            $page->checkout_config->deliveryOptions,
        );

        return implode(',', $products).'|'.implode(',', $delivery);
    }
}
