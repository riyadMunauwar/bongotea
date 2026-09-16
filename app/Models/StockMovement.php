<?php

namespace App\Models;

use App\Enums\StockMovementReason;
use Database\Factories\StockMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use LogicException;

/**
 * One thing that happened to a quantity of a Product: how many units moved
 * and which way, why, what a unit was worth when it did, who caused it and
 * what caused it.
 *
 * The ledger is the figure. A Product's On Hand is the sum of its movements
 * and is held nowhere else (ADR-0021), so a row here is not a record of a
 * change to a stock level — it *is* the stock level, together with every
 * other row.
 *
 * **Written by `StockLedger` and by nothing else.** That is the one rule this
 * model exists to keep: a movement written from somewhere else is a figure
 * with no cause behind it, and On Hand is only worth reading because every
 * unit of it can be accounted for.
 *
 * Immutable in both directions, refused here rather than left to convention.
 * A mistake is answered by another movement, and the mistake and its
 * correction both stay on the record.
 */
#[Fillable([
    'product_id',
    'quantity',
    'reason',
    'unit_cost',
    'user_id',
    'source_type',
    'source_id',
])]
class StockMovement extends Model
{
    /** @use HasFactory<StockMovementFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'reason' => StockMovementReason::class,
            'unit_cost' => 'integer',
        ];
    }

    /**
     * History never rewrites itself, and the model is where that is enforced:
     * a row written is a row kept, whatever a later screen, command or
     * relation tries to do to it.
     */
    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('A Stock Movement is never edited. Write another movement instead.');
        });

        static::deleting(function (): never {
            throw new LogicException('A Stock Movement is never deleted. Write the movement that reverses it instead.');
        });
    }

    /**
     * The rows that still stand: everything except a Void and the movement
     * it reversed.
     *
     * Both halves, because they are one thing that did not happen. A void and
     * the row it undoes share a cause and a Product and differ only in their
     * reason, which is what lets one clause find the pair — and what the
     * ledger's own unique key already indexes.
     *
     * Nothing is hidden from what is *held*: the pair sums to nothing, so On
     * Hand and Value at Cost come to the same figure either way. What it
     * changes is whether anything was ever bought at all. A Product whose
     * only costed rows were a delivery that never happened has no known cost
     * rather than the price on it — reporting that price would be quoting a
     * delivery the Merchant has just struck out (ADR-0022).
     *
     * @param  Builder<StockMovement>  $query
     */
    #[Scope]
    protected function standing(Builder $query): void
    {
        $query->whereNotExists(fn (QueryBuilder $voids): QueryBuilder => $voids
            ->selectRaw('1')
            ->from('stock_movements as voids')
            ->where('voids.reason', StockMovementReason::Void)
            ->whereColumn('voids.source_type', 'stock_movements.source_type')
            ->whereColumn('voids.source_id', 'stock_movements.source_id')
            ->whereColumn('voids.product_id', 'stock_movements.product_id'));
    }

    /**
     * The Product this moved, trashed or not: a soft-deleted Product keeps
     * its movements and stays readable in its own history, so a relation that
     * resolved to null would tear the hole this is written to avoid.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * Who caused it, where a person did. Null is ordinary rather than
     * missing: a movement a Courier's word will one day write has no User
     * behind it at all.
     *
     * `withTrashed()` because a User who has left is filed away rather than
     * erased, and the name a person put on a movement must keep rendering
     * after they are gone — that name is the only thing that lets a surprising
     * figure be asked about.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * What caused it — the Stock Count today, a Supply's line and a Shipment
     * when those arrive.
     *
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
