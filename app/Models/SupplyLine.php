<?php

namespace App\Models;

use App\Support\Inventory\Grams;
use Database\Factories\SupplyLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/**
 * One Product's worth of a Supply: how many grams arrived and what a
 * kilogram of them cost (ADR-0035).
 *
 * An `Order Line` read from the buying side. It is what a Stock Movement
 * points at when it says a Supply caused it — one line, one movement — rather
 * than the document above it, so a delivery listing the same Product twice
 * cannot collide on the ledger's one-cause-one-movement key.
 *
 * Immutable in both directions, like the document it belongs to and like
 * every movement it caused.
 */
#[Fillable([
    'supply_id',
    'product_id',
    'quantity',
    'unit_cost',
])]
class SupplyLine extends Model
{
    /** @use HasFactory<SupplyLineFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('A Supply line is never edited. Void the Supply and record it again instead.');
        });

        static::deleting(function (): never {
            throw new LogicException('A Supply line is never deleted. Void the Supply instead.');
        });
    }

    /**
     * @return BelongsTo<Supply, $this>
     */
    public function supply(): BelongsTo
    {
        return $this->belongsTo(Supply::class);
    }

    /**
     * What arrived, trashed or not: a Product taken off the catalogue keeps
     * its buying history, the same way it keeps its movements.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * The movement this line caused — one row, and `MorphMany` only because
     * that is the shape of the relation the ledger writes through.
     *
     * @return MorphMany<StockMovement, $this>
     */
    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'source');
    }

    /**
     * What this line cost altogether, in minor units: its grams at its cost
     * per kilogram.
     */
    public function lineCost(): int
    {
        return Grams::cost($this->quantity, $this->unit_cost);
    }
}
