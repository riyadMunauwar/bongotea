<?php

namespace App\Support\Orders;

use App\Support\Money;

/**
 * One line a User asks an Order to hold, read from the admin order form: which
 * Product, how many Kilograms, the Pack they are supplied in, and the Price
 * Override set on it, if any. Every figure on the line is worked out from
 * these by the pricing module; nothing priced comes from the form but the
 * override itself, and the Pack prices nothing.
 */
final readonly class RequestedLine
{
    public function __construct(
        /** The Order Line this row was filled from, or null for a line being added. */
        public ?int $id,
        public int $productId,
        public int $kilograms,

        /** The পাইকারি Pack the Kilograms are supplied in, or null for the Product's default (ADR-0036). */
        public ?int $packId,

        /** A Tier Price set by hand, in minor units. */
        public ?int $overridePrice,
        public ?string $overrideReason,

        /**
         * Whether the row carried the override fields at all. A User who may
         * not override never sends them, which means "leave the line's
         * override as it is" rather than "take it away".
         */
        public bool $saysOverride,
    ) {}

    /**
     * A repeater row as the form holds it, or null where it is not yet a line
     * that can be priced — no Product chosen, or no whole number of Kilograms.
     * The override price is typed in whole taka.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromState(array $row): ?self
    {
        $productId = filter_var($row['product_id'] ?? null, FILTER_VALIDATE_INT);
        $kilograms = filter_var($row['kilograms'] ?? null, FILTER_VALIDATE_INT);

        if ($productId === false || $kilograms === false || $kilograms < 1) {
            return null;
        }

        $id = filter_var($row['id'] ?? null, FILTER_VALIDATE_INT);
        $packId = filter_var($row['pack_id'] ?? null, FILTER_VALIDATE_INT);
        $overridePrice = $row['override_price'] ?? null;

        return new self(
            id: $id === false ? null : $id,
            productId: $productId,
            kilograms: $kilograms,
            packId: $packId === false ? null : $packId,
            overridePrice: is_numeric($overridePrice) ? Money::toMinorUnits($overridePrice) : null,
            overrideReason: filled($row['override_reason'] ?? null) ? trim((string) $row['override_reason']) : null,
            saysOverride: array_key_exists('override_price', $row),
        );
    }

    /** The same line carrying the given override instead of its own. */
    public function withOverride(?int $price, ?string $reason): self
    {
        return new self($this->id, $this->productId, $this->kilograms, $this->packId, $price, $price === null ? null : $reason, true);
    }

    public function isOverridden(): bool
    {
        return $this->overridePrice !== null;
    }
}
