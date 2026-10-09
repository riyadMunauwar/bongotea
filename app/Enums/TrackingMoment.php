<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Something that happens in the Bongo Tea domain and is worth reporting.
 *
 * The whole tracking subsystem is organised around these rather than around
 * any one ad platform's event names, so that a second platform is a Provider
 * class and nothing else. A Moment maps to zero, one or several provider
 * events, and which ones is the Provider's answer, never a caller's.
 */
enum TrackingMoment: string implements HasLabel
{
    /** One load of a storefront page. */
    case Visit = 'visit';

    /** A Product's own page was opened. */
    case ProductViewed = 'product_viewed';

    /** A Product went from none to some in a Cart. */
    case AddedToCart = 'added_to_cart';

    /** A visitor opened the Checkout with something in their Cart. */
    case CheckoutStarted = 'checkout_started';

    /** A visitor submitted the Checkout and an Order came into existence. */
    case Capture = 'capture';

    /** A Verification marked one of an Order's Payments Paid. */
    case Paid = 'paid';

    /**
     * The Moments a browser is allowed to report for itself.
     *
     * These happen in a browser and are witnessed nowhere else, so the
     * beacon is the only thing that can say they happened. Capture and Paid
     * are deliberately absent: the beacon is unauthenticated by design, and a
     * Purchase anyone could post is reported revenue the Merchant does not
     * have.
     *
     * @return list<self>
     */
    public static function reportableByBrowser(): array
    {
        return [self::Visit, self::ProductViewed, self::AddedToCart, self::CheckoutStarted];
    }

    /**
     * The Moment a browser posted under that name, or null where it is not
     * one a browser may report — an unknown word and a forged Capture read
     * the same way here, because both are simply nothing to record.
     */
    public static function fromBrowser(mixed $posted): ?self
    {
        $moment = is_string($posted) ? self::tryFrom($posted) : null;

        return in_array($moment, self::reportableByBrowser(), true) ? $moment : null;
    }

    /**
     * Whether this Moment is about Products the browser names — and so
     * carries a Selection — rather than about a page load or an Order.
     */
    public function isAboutProductsNamed(): bool
    {
        return in_array($this, [self::ProductViewed, self::AddedToCart, self::CheckoutStarted], true);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Visit => 'Visit',
            self::ProductViewed => 'Product Viewed',
            self::AddedToCart => 'Added to Cart',
            self::CheckoutStarted => 'Checkout Started',
            self::Capture => 'Capture',
            self::Paid => 'Paid',
        };
    }
}
