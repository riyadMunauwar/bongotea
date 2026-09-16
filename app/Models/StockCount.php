<?php

namespace App\Models;

use App\Enums\AdjustmentCause;
use Database\Factories\StockCountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/**
 * What a User physically counted of one Product.
 *
 * The number recorded is what they were holding in their head at the shelf,
 * never a difference: how far the ledger was out is worked out rather than
 * typed, and written down as an Adjustment that this count is the cause of.
 * Nobody sets a quantity anywhere in this system, and this is the screen that
 * would have been where they did.
 *
 * Immutable, refused at the model. A wrong count is answered by counting
 * again — which is what somebody would physically do anyway — and the second
 * count is measured against the ledger the first one left behind.
 *
 * Recorded through `StockLedger::count()`, which writes this row and its
 * Adjustment together or writes neither. A count with no movement behind it
 * would be a shelf somebody looked at and a figure that never heard about it.
 */
#[Fillable([
    'product_id',
    'quantity',
    'cause',
    'note',
    'user_id',
])]
class StockCount extends Model
{
    /** @use HasFactory<StockCountFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'cause' => AdjustmentCause::class,
        ];
    }

    /**
     * A count is a thing that happened, and the panel offers no way to edit
     * or delete one. This is what holds that everywhere else.
     */
    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('A Stock Count is never edited. Count again instead.');
        });

        static::deleting(function (): never {
            throw new LogicException('A Stock Count is never deleted. Count again instead.');
        });
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * Who counted, so a surprising correction has somebody to ask about it.
     *
     * `withTrashed()` because a User who has left is filed away rather than
     * erased: the name is the whole point of stamping it, so it must keep
     * rendering here after they are gone.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * The Adjustment this count caused — one row, and `MorphMany` only
     * because that is the shape of the relation the ledger writes through.
     *
     * @return MorphMany<StockMovement, $this>
     */
    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'source');
    }
}
