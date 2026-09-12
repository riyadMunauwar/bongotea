<?php

namespace Database\Factories;

use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShipmentEvent>
 */
class ShipmentEventFactory extends Factory
{
    /**
     * A booking, as the first thing that happened to a parcel: our own time,
     * and no time of the Courier's — which is the ordinary case, not the
     * exception.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shipment_id' => Shipment::factory(),
            'status' => ShipmentStatus::Booked,
            'raw_status' => 'in_review',
            'occurred_at' => null,
            'recorded_at' => now(),
            'deduplication_id' => fn (array $attributes): string => ShipmentEvent::deduplicationIdFor(
                $attributes['raw_status'] ?? ShipmentStatus::Booked->value,
                $attributes['occurred_at'] ?? null,
            ),
        ];
    }
}
