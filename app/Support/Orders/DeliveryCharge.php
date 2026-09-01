<?php

namespace App\Support\Orders;

use App\Support\Money;

/**
 * The one place that answers "what is delivery on this order?".
 *
 * Flat config in v1. It takes the District anyway — not because it uses it,
 * but because the District is the input the eventual answer keys off, and a
 * caller that already passes it does not change when a zone table or a
 * per-page override arrives. The Order snapshots whatever this returns, so
 * changing the derivation later changes nothing historical.
 */
class DeliveryCharge
{
    /**
     * Minor units to charge for delivering to a District.
     */
    public function forDistrict(string $district): int
    {
        return Money::toMinorUnits(config('giopages.delivery.charge'));
    }

    /**
     * The charge for every District the merchant sells to.
     *
     * The Checkout island embeds this so it can show a total before submitting.
     * A map rather than a single figure because the map is visitor-independent
     * either way — it is merchant configuration, so it stays cacheable — and
     * building it from `forDistrict()` means the island is already correct on
     * the day the flat charge becomes a zone table.
     *
     * @return array<string, int>
     */
    public function byDistrict(): array
    {
        $charges = [];

        foreach ((array) config('giopages.districts') as $district) {
            $charges[$district] = $this->forDistrict($district);
        }

        return $charges;
    }
}
