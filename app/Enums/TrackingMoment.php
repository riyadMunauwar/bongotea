<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Something that happens in the GioPages domain and is worth reporting.
 *
 * The whole tracking subsystem is organised around these rather than around
 * any one ad platform's event names, so that a second platform is a Provider
 * class and nothing else. A Moment maps to zero, one or several provider
 * events, and which ones is the Provider's answer, never a caller's.
 *
 * Visit, Capture and Paid are already the glossary's words; Checkout Started
 * is the one this subsystem adds.
 */
enum TrackingMoment: string implements HasLabel
{
    /** One load of a storefront page. */
    case Visit = 'visit';

    /** A visitor starting the checkout. */
    case CheckoutStarted = 'checkout_started';

    /** A visitor submitted the Checkout and an Order came into existence. */
    case Capture = 'capture';

    /** A Verification marked one of an Order's Payments Paid. */
    case Paid = 'paid';

    /**
     * The Moments a browser is allowed to report for itself.
     *
     * Visit and Checkout Started happen in a browser and are witnessed
     * nowhere else, so the beacon is the only thing that can say they
     * happened. Capture and Paid are deliberately absent: the beacon is
     * unauthenticated by design — it answers a document served as a file
     * with no session and no token — and a Purchase anyone holding a Page's
     * slug could post is reported revenue the Merchant does not have.
     *
     * @return list<self>
     */
    public static function reportableByBrowser(): array
    {
        return [self::Visit, self::CheckoutStarted];
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

    public function getLabel(): string
    {
        return match ($this) {
            self::Visit => 'Visit',
            self::CheckoutStarted => 'Checkout Started',
            self::Capture => 'Capture',
            self::Paid => 'Paid',
        };
    }
}
