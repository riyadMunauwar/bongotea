<?php

namespace App\Support\Orders;

use App\Support\Money;

/**
 * One line a User asks a retail Order to hold, read from the admin order
 * form (ADR-0034): which Pack, how many, and the Price Override set on it,
 * if any. Every figure is worked out by retail pricing; nothing priced comes
 * from the form but the override itself.
 */
final readonly class RequestedPackLine
{
    public function __construct(
        /** The Order Line this row was filled from, or null for a line being added. */
        public ?int $id,
        public int $packId,
        public int $count,

        /** A Pack Price set by hand, in minor units. */
        public ?int $overridePrice,
        public ?string $overrideReason,

        /** Whether the row carried the override fields at all; see `RequestedLine::$saysOverride`. */
        public bool $saysOverride,
    ) {}

    /**
     * A repeater row as the form holds it, or null where it is not yet a line
     * that can be priced — no Pack chosen, or no whole count. The override
     * price is typed in whole taka.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromState(array $row): ?self
    {
        $packId = filter_var($row['pack_id'] ?? null, FILTER_VALIDATE_INT);
        $count = filter_var($row['count'] ?? null, FILTER_VALIDATE_INT);

        if ($packId === false || $count === false || $count < 1) {
            return null;
        }

        $id = filter_var($row['id'] ?? null, FILTER_VALIDATE_INT);
        $overridePrice = $row['override_price'] ?? null;

        return new self(
            id: $id === false ? null : $id,
            packId: $packId,
            count: $count,
            overridePrice: is_numeric($overridePrice) ? Money::toMinorUnits($overridePrice) : null,
            overrideReason: filled($row['override_reason'] ?? null) ? trim((string) $row['override_reason']) : null,
            saysOverride: array_key_exists('override_price', $row),
        );
    }

    /** The same line carrying the given override instead of its own. */
    public function withOverride(?int $price, ?string $reason): self
    {
        return new self($this->id, $this->packId, $this->count, $price, $price === null ? null : $reason, true);
    }

    public function isOverridden(): bool
    {
        return $this->overridePrice !== null;
    }
}
