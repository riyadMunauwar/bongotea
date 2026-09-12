<?php

namespace App\Support\Couriers;

use App\Enums\ShipmentStatus;

/**
 * What a Courier answered when it took a parcel: its own identifier for the
 * Consignment, the code a Customer follows it by, where it says the parcel
 * stands, what it charged if it says, and the answer itself kept whole for
 * the record.
 *
 * The canonical status is already translated, because translating a Courier's
 * vocabulary is its driver's job and nothing outside `app/Support/Couriers`
 * may learn a Courier's words (ADR-0018). The raw word rides along beside it
 * for the timeline to show verbatim.
 */
final class ShipmentBooked
{
    /**
     * @param  string  $consignmentReference  The Courier's own identifier for the parcel, quoted back and never interpreted.
     * @param  int|null  $charge  What the Courier said it charged, in minor units, where it says at booking.
     * @param  array<string, mixed>  $response  The Courier's answer as it arrived.
     */
    public function __construct(
        public readonly string $consignmentReference,
        public readonly ?string $trackingCode,
        public readonly ShipmentStatus $status,
        public readonly ?string $rawStatus = null,
        public readonly ?int $charge = null,
        public readonly array $response = [],
    ) {}
}
