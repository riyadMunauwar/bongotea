<?php

namespace App\Support\Reporting;

/**
 * The three glossary figures that are ratios of the counts a span comes to,
 * for anything that carries `$orders`, `$sales`, `$cancelled` and
 * `$visits`. Kept as methods over the counts, so a rate is never stored
 * beside the counts it disagrees with, and written once, so no two screens
 * can round differently.
 *
 * @property-read int $orders
 * @property-read int $sales
 * @property-read int $cancelled
 * @property-read int|null $visits
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

    /**
     * Orders as a share of Visits, from 0 to 1 — the Conversion Rate.
     *
     * Every Order counts towards it, Cancelled ones included, because the
     * Visit that produced one was converted whatever became of the Order
     * afterwards; that is the same reading `Orders` as a figure already
     * takes, and it is what keeps the rate comparable to the ad platform's.
     *
     * Null, not zero, where Visits are not counted: a rate with no
     * denominator is not zero, it is unknown, and a Merchant who has not
     * switched counting on must be told that rather than shown a 0.0% they
     * would read as a store that sells nothing. Zero where a span was counted
     * and had no Visits at all.
     */
    public function conversionRate(): ?float
    {
        if ($this->visits === null) {
            return null;
        }

        return $this->visits === 0 ? 0.0 : $this->orders / $this->visits;
    }
}
