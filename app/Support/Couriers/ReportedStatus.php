<?php

namespace App\Support\Couriers;

use App\Enums\ShipmentStatus;
use DateTimeInterface;

/**
 * One state a Courier reported a parcel to be in, in its own word for it and
 * at its own time where it gave one.
 *
 * The canonical state is already translated, because translating a Courier's
 * vocabulary is its driver's job and nothing outside `app/Support/Couriers`
 * may learn a Courier's words (ADR-0018). The raw word rides along beside it
 * for the timeline to show verbatim, and is what a Shipment Event is
 * deduplicated by — so a Courier that repeats itself repeats itself in one
 * string and not in two spellings.
 *
 * `occurredAt` is the Courier's own time and is null for most of what most
 * couriers say. It is never stood in for here: ours is written by
 * `Shipment::recordEvent()` and is always there, and a time invented at this
 * seam would be ours wearing the Courier's name.
 */
final class ReportedStatus
{
    public function __construct(
        public readonly ShipmentStatus $status,
        public readonly ?string $rawStatus = null,
        public readonly DateTimeInterface|string|null $occurredAt = null,
    ) {}
}
