<?php

namespace App\Support\Couriers;

/**
 * One question put to a Courier about one parcel, and what writing its answer
 * down came to.
 *
 * The answer itself is `ShipmentProgress` — the Courier's own outcome, which
 * a driver decides. This is what happened next: how many timeline rows the
 * answer actually wrote, which is not how many states it reported, because a
 * Courier that re-imports its whole history every poll reports the same
 * states over and over and the database writes each of them once (ADR-0019).
 */
final class ShipmentPolled
{
    /**
     * @param  ShipmentProgress  $progress  What the Courier said, as its driver read it.
     * @param  int  $recorded  Timeline rows this poll wrote. Nought where the Courier said nothing at all, and nought again where everything it said was already known.
     */
    public function __construct(
        public readonly ShipmentProgress $progress,
        public readonly int $recorded,
    ) {}
}
