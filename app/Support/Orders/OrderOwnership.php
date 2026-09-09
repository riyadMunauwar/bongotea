<?php

namespace App\Support\Orders;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * What Capture hands the browser that placed an Order, and what the Order
 * Complete Page asks for before drawing the Customer (ADR-0014).
 *
 * A list of order numbers, carried in a dedicated cookie. The cookie is
 * encrypted and signed by the framework on the way out and decrypted on the
 * way in, so a number cannot be read off the wire or written in by hand.
 * Dedicated rather than the session because a gateway sends the Customer back
 * with a cross-site POST that carries no cookies and starts a fresh session;
 * this cookie is left alone by that request and sent on the same-origin visit
 * that follows, so cash and online arrive the same way.
 *
 * Ownership is a browser, not a person: the same Customer on a second device
 * sees the Order only. Read totally, like every other value we did not write
 * this request: a cookie that does not parse is an empty list, never an error.
 */
final class OrderOwnership
{
    /** Named for its purpose, so a person reading a cookie jar knows what it is. */
    public const COOKIE = 'order_ownership';

    /**
     * How long a browser is recognised for. Long enough to reopen the page
     * from history days later; short enough that a shared computer forgets.
     */
    public const LIFETIME_DAYS = 30;

    /**
     * How many Orders a browser is recognised for at once. Bounded so the
     * cookie cannot grow without limit; the oldest fall off first.
     */
    public const KEEP = 5;

    /**
     * @param  list<string>  $orderNumbers  Oldest first.
     */
    private function __construct(private readonly array $orderNumbers) {}

    /**
     * What the request's browser has been handed so far.
     */
    public static function fromRequest(Request $request): self
    {
        $stored = $request->cookie(self::COOKIE);

        $decoded = is_string($stored) ? json_decode($stored, true) : null;

        if (! is_array($decoded)) {
            return new self([]);
        }

        return new self(array_values(array_filter($decoded, 'is_string')));
    }

    /**
     * The same browser, now recognised for this Order as well.
     *
     * The number goes on the end, and the front is dropped past the bound, so
     * a browser placing many Orders keeps the most recent.
     */
    public function placing(string $orderNumber): self
    {
        $orderNumbers = array_values(array_filter(
            $this->orderNumbers,
            fn (string $placed): bool => $placed !== $orderNumber,
        ));

        $orderNumbers[] = $orderNumber;

        return new self(array_slice($orderNumbers, -self::KEEP));
    }

    public function owns(string $orderNumber): bool
    {
        return in_array($orderNumber, $this->orderNumbers, true);
    }

    /**
     * The cookie to hand the browser.
     *
     * HttpOnly because nothing in the page's script needs it; SameSite Lax
     * because the one cross-site request in the flow, the gateway's POST
     * return, has no use for it, and the same-origin visit that follows is
     * exactly what Lax sends it on. The framework encrypts the value, and
     * the Secure flag follows the session cookie's (`session.secure`), so
     * production over HTTPS marks it and a local HTTP install still works.
     */
    public function cookie(): Cookie
    {
        return cookie(
            name: self::COOKIE,
            value: json_encode($this->orderNumbers),
            minutes: self::LIFETIME_DAYS * 24 * 60,
            path: '/',
            sameSite: 'lax',
        );
    }
}
