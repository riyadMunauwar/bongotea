<?php

namespace App\Support\Couriers;

use Carbon\CarbonImmutable;

/**
 * What a Courier is holding of the Merchant's money, as that Courier reports
 * it — or the honest reason there is no figure to show.
 *
 * Three states and not a nullable integer, for the reason `ShipmentProgress`
 * has three: the difference between a Courier that documents no balance at
 * all, one that could not be asked this morning, and one holding nothing is
 * the whole of what a Merchant reads here. A blank standing in for any of
 * them is a Merchant told their money is gone.
 *
 * It is never added to COD in Flight and the two never become one number:
 * one is what a Courier says it owes, the other is what we believe is out
 * with its riders, and a Merchant reconciling the two needs them apart.
 *
 * A reported figure carries when it was read, because a figure held from an
 * earlier read is still worth showing when a later read fails — and a
 * Merchant reading a figure is entitled to know how old it is.
 */
final class CourierBalance
{
    private function __construct(
        /** Whether this Courier reports a balance at all. False is the Courier's own documentation, never a failure. */
        public readonly bool $isDocumented,
        /** The figure in minor units, or null where there is none to show. */
        public readonly ?int $amount,
        /** When that figure was read off the Courier. */
        public readonly ?CarbonImmutable $readAt,
        /** Why there is no figure, or why the figure there is could not be refreshed, worded for the Merchant. */
        public readonly ?string $error,
    ) {}

    /**
     * The Courier said what it is holding.
     *
     * `$readAt` is when it said so, which is now for a figure straight off
     * the wire and earlier for one that has been held since. `$error` is what
     * happened the last time that figure was re-read, where re-reading it
     * failed: a Merchant looking at this morning's money is owed the fact
     * that the Courier has said nothing since.
     */
    public static function of(int $amount, ?CarbonImmutable $readAt = null, ?string $error = null): self
    {
        return new self(
            isDocumented: true,
            amount: $amount,
            readAt: $readAt ?? CarbonImmutable::now(),
            error: $error,
        );
    }

    /**
     * This Courier publishes no balance at all, so there is nothing to ask
     * for and nothing missing.
     *
     * An honest answer rather than a gap, exactly as an empty
     * `deliveryAreas()` is for a Courier that reads a free-text address: two
     * of the three document no balance endpoint, and the widget says so in
     * words.
     */
    public static function notReported(): self
    {
        return new self(isDocumented: false, amount: null, readAt: null, error: null);
    }

    /**
     * This Courier reports a balance and it could not be had — it could not
     * be reached, it refused the credentials, or this installation cannot
     * speak to it at all.
     *
     * Never zero. A figure that could not be fetched reading as nothing held
     * is the one failure this whole shape exists to prevent.
     */
    public static function unavailable(string $error): self
    {
        return new self(isDocumented: true, amount: null, readAt: null, error: $error);
    }

    /** Whether there is a figure to draw. */
    public function isReported(): bool
    {
        return $this->amount !== null;
    }

    /** Whether this Courier documents a balance that could not be had. */
    public function isUnavailable(): bool
    {
        return $this->isDocumented && $this->amount === null;
    }
}
