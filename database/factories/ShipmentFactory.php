<?php

namespace Database\Factories;

use App\Enums\Courier;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shipment>
 */
class ShipmentFactory extends Factory
{
    /**
     * A parcel as a Courier has just taken it: Booked, with a Consignment and
     * a tracking code, told to collect the Order's total.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** Read once for the whole row, however many of its fields come off the Order; `definition()` runs per Shipment, so the memo never outlives one. */
        $order = null;

        $forOrder = function (array $attributes) use (&$order): Order {
            return $order ??= Order::findOrFail($attributes['order_id']);
        };

        return [
            'order_id' => Order::factory(),
            'courier' => Courier::Steadfast,
            'status' => ShipmentStatus::Booked,
            'raw_status' => 'in_review',
            'invoice_reference' => fn (array $attributes): string => Shipment::nextInvoiceReference($forOrder($attributes)),
            'consignment_reference' => (string) fake()->numberBetween(1_000_000, 9_999_999),
            'tracking_code' => strtoupper(fake()->bothify('????####')),
            'collect_amount' => fn (array $attributes): int => $forOrder($attributes)->amountDueOnDelivery(),
            'recipient_name' => fn (array $attributes): string => $forOrder($attributes)->customer_name ?? fake()->name(),
            'recipient_phone' => fn (array $attributes): string => $forOrder($attributes)->customer_phone,
            'recipient_address' => fn (array $attributes): string => $forOrder($attributes)->customer_address ?? fake()->streetAddress(),
            'recipient_district' => fn (array $attributes): ?string => $forOrder($attributes)->customer_district,
            'booked_at' => now(),
        ];
    }

    /** A parcel that arrived: the attempt is over and the Order may not be sent again. */
    public function delivered(): static
    {
        return $this->state(fn (): array => ['status' => ShipmentStatus::Delivered, 'raw_status' => 'delivered']);
    }

    /**
     * A parcel that came back. The attempt is over and the Order is free to
     * be sent again — which is exactly what makes a second attempt's invoice
     * reference a question worth asking (ADR-0018).
     */
    public function returned(): static
    {
        return $this->state(fn (): array => ['status' => ShipmentStatus::Returned, 'raw_status' => 'cancelled']);
    }

    /** A parcel a User wrote off: a time and a name, with the Courier's last word left standing. */
    public function abandoned(): static
    {
        return $this->state(fn (): array => ['status' => ShipmentStatus::OnHold, 'raw_status' => 'hold', 'abandoned_at' => now()]);
    }
}
