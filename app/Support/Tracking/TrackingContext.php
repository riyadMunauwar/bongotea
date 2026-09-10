<?php

namespace App\Support\Tracking;

use Illuminate\Http\Request;

/**
 * What the browser told us at the moment something happened: where it was,
 * who it looked like, and which ad click brought it.
 *
 * None of it is personal in the sense the Order's own columns are, and all of
 * it is what a Provider matches on itself — which is why it is carried
 * un-hashed and kept apart from the Order.
 *
 * The click identifiers are a bag keyed by the name each Provider knows them
 * under (`fbc`, `fbp`, `ttclid`), so one shape serves every Provider and each
 * takes only what it recognises. A visitor whose browser ran no script sends
 * none, and everything still works with an empty bag.
 */
class TrackingContext
{
    /**
     * @param  array<string, string>  $clickIds
     */
    private function __construct(
        public readonly ?string $sourceUrl,
        public readonly ?string $ipAddress,
        public readonly ?string $userAgent,
        public readonly array $clickIds,
    ) {}

    /**
     * Nothing known: what a Moment recorded outside a request has, and what a
     * Provider is handed when the browser is long gone.
     */
    public static function none(): self
    {
        return new self(null, null, null, []);
    }

    /**
     * What one request says about the browser that made it. The source URL is
     * passed in rather than read off the request, because the address that
     * matters to a Provider is the Page the visitor was on, and a Capture
     * arrives as a POST to the Checkout endpoint.
     */
    public static function fromRequest(Request $request, ?string $sourceUrl = null): self
    {
        return new self(
            $sourceUrl,
            $request->ip(),
            $request->userAgent(),
            [],
        );
    }
}
