<?php

namespace App\Support\Reporting;

use App\Enums\PlaceKind;
use App\Models\Category;
use App\Models\Page;
use App\Models\Product;

/**
 * One place of the store and the Visits it drew in a window, beside the
 * Visits it drew in the comparison window.
 *
 * A Product's row also carries the Orders that held it, which is what its
 * Buy Rate is read from; no Order belongs to any other kind of place, so
 * theirs is null rather than a nought.
 */
final readonly class VisitsByPlaceRow
{
    public function __construct(
        public PlaceKind $kind,
        /** The record's id, kept though the record is gone; `Place::NO_RECORD` for Search and the Checkout. */
        public int $placeId,
        /** The record the place is, trashed included; null for Search and the Checkout, and for a record deleted for good. */
        public Page|Category|Product|null $subject,
        public int $visits,
        /** The same place's Visits over the comparison window. */
        public int $previousVisits,
        /** Every Order in the window holding this Product, Cancelled ones included; null for any place that is not a Product. */
        public ?int $orders = null,
    ) {}

    /**
     * The place, told apart from every other row — two deleted Categories
     * included, which share a name and have no record left.
     */
    public function key(): string
    {
        return "{$this->kind->value}:{$this->placeId}";
    }

    /**
     * What the row is called: the record's own name as it reads now, or the
     * kind of place where there is no record to name.
     */
    public function name(): string
    {
        return match (true) {
            $this->subject instanceof Page => $this->subject->title,
            $this->subject instanceof Category, $this->subject instanceof Product => $this->subject->name,
            $this->kind->isARecord() => "Deleted {$this->kind->getLabel()}",
            default => $this->kind->getLabel(),
        };
    }

    /**
     * The row's share of the store's Visits, from 0 to 1. Null where the
     * store counted none, since a share of nothing is not a figure.
     */
    public function shareOf(int $storeVisits): ?float
    {
        return $storeVisits === 0 ? null : $this->visits / $storeVisits;
    }

    /**
     * The Buy Rate: Orders holding this Product as a share of its Visits.
     *
     * Two counts set side by side and nobody followed from one to the other,
     * so an Order taken by phone, or placed on a later day, still counts and
     * the rate may read above one. Null for a place that is not a Product, and
     * for a Product with no Visits — a rate with no denominator is unknown.
     */
    public function buyRate(): ?float
    {
        if ($this->orders === null || $this->visits === 0) {
            return null;
        }

        return $this->orders / $this->visits;
    }
}
