<?php

namespace App\Support\Reporting;

/**
 * Where a window's Visits went, place by place, most visited first.
 *
 * The places are a breakdown of the store's figure and never its source: a
 * load of no place, and every load counted before places were, is the
 * store's alone. `unmatched()` is that difference, said rather than hidden.
 *
 * Null for the store's Visits, and no rows, where the Merchant is not
 * counting Visits at all.
 */
final readonly class VisitsByPlace
{
    public function __construct(
        public ReportingWindow $window,
        /** The store's own Visits over the window; null where they are not counted. */
        public ?int $visits,
        /** @var list<VisitsByPlaceRow> */
        public array $rows,
    ) {}

    public function isCounted(): bool
    {
        return $this->visits !== null;
    }

    /**
     * The store's Visits no place accounts for: a load of an address that
     * resolved to nothing, or a day counted before places were. Null where
     * Visits are not counted.
     */
    public function unmatched(): ?int
    {
        if ($this->visits === null) {
            return null;
        }

        return max(0, $this->visits - array_sum(array_map(fn (VisitsByPlaceRow $row): int => $row->visits, $this->rows)));
    }
}
