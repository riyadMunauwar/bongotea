<?php

namespace App\Support\Couriers;

/**
 * What one Courier answered when it was handed a batch of parcels: the ones
 * it took, the ones it said no to, and whether it stopped answering partway
 * through.
 *
 * Answers rather than throws, like `ShipmentProgress` and unlike `book()`.
 * A single booking is one parcel and one outcome, so an exception says all
 * there is to say; a batch has an outcome per parcel and can go silent
 * halfway, and a Courier that took forty parcels and then dropped the line
 * must be able to say both — or the forty it took are real parcels with no
 * record and the next sweep books them again (ADR-0020).
 *
 * Everything is keyed by our own invoice reference, which is the one name
 * both sides of the call agree on: it is what the booking told the Courier
 * and what the Courier echoes back, and it names one attempt rather than an
 * Order (ADR-0018).
 *
 * A parcel named in neither list is a parcel nothing is known about. Its row
 * stays as it was — written, undispatched — and the next sweep offers it
 * again, which is exactly what an answer that could not be read should cost.
 */
final class BatchBooked
{
    /**
     * @param  array<string, ShipmentBooked>  $accepted  by invoice reference: what the Courier said as it took each parcel.
     * @param  array<string, string>  $refused  by invoice reference: why the Courier said no, in its own words.
     * @param  string|null  $error  why the Courier stopped answering, or null where it answered throughout.
     */
    private function __construct(
        public readonly array $accepted,
        public readonly array $refused,
        public readonly ?string $error,
    ) {}

    /**
     * The Courier answered about the whole batch.
     *
     * @param  array<string, ShipmentBooked>  $accepted
     * @param  array<string, string>  $refused
     */
    public static function of(array $accepted, array $refused = []): self
    {
        return new self($accepted, $refused, null);
    }

    /**
     * The Courier stopped answering, having settled this much of the batch
     * first.
     *
     * Both halves matter. What it settled is written down, because those are
     * real parcels; the silence stops this Courier's whole run, because every
     * later batch would fail the same way and cost a call to find out.
     *
     * @param  array<string, ShipmentBooked>  $accepted
     * @param  array<string, string>  $refused
     */
    public static function silencedAfter(string $error, array $accepted = [], array $refused = []): self
    {
        return new self($accepted, $refused, $error);
    }

    /** Whether the Courier answered about the whole batch rather than going quiet partway. */
    public function answeredThroughout(): bool
    {
        return $this->error === null;
    }
}
