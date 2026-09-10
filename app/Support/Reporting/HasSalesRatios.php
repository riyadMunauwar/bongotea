<?php

namespace App\Support\Reporting;

/**
 * The two glossary figures that are ratios of the counts a span of Orders
 * comes to, for anything that carries `$orders`, `$sales` and
 * `$cancelled`: the Overview's figures and a leaderboard row alike. Kept
 * as methods over the counts, so a rate is never stored beside the counts
 * it disagrees with, and written once, so the Overview and the leaderboard
 * cannot round differently.
 *
 * @property-read int $orders
 * @property-read int $sales
 * @property-read int $cancelled
 */
trait HasSalesRatios
{
    /**
     * The Orders that count towards Sales.
     */
    public function notCancelled(): int
    {
        return $this->orders - $this->cancelled;
    }

    /**
     * Sales over the Orders that count towards it, in minor units, rounded.
     * Zero, not undefined, when there are none.
     */
    public function averageOrderValue(): int
    {
        $counted = $this->notCancelled();

        return $counted === 0 ? 0 : (int) round($this->sales / $counted);
    }

    /**
     * Cancelled as a share of Orders, from 0 to 1. Zero when there are none.
     */
    public function cancellationRate(): float
    {
        return $this->orders === 0 ? 0.0 : $this->cancelled / $this->orders;
    }
}
