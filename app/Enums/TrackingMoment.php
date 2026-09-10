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
    /** One load of a published Page. */
    case Visit = 'visit';

    /** A visitor touching a Checkout Field for the first time on a page load. */
    case CheckoutStarted = 'checkout_started';

    /** A visitor submitted the Checkout and an Order came into existence. */
    case Capture = 'capture';

    /** A Verification marked one of an Order's Payments Paid. */
    case Paid = 'paid';

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
