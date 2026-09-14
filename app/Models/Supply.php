<?php

namespace App\Models;

use Database\Factories\SupplyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Goods arriving from a Supplier, recorded as one document: who they came
 * from, the day they came, the reference they can be found under, and a line
 * per Product saying how many arrived and what each one cost.
 *
 * An `Order` and its `Order Line`s read from the buying side, and the point
 * at which cost enters the system (ADR-0022). Recording one raises stock
 * there and then — there is no separate receiving step to forget — because
 * `StockLedger::recordSupply()` writes this document and its movements in one
 * transaction or writes neither.
 *
 * Immutable, refused at the model. A Supply recorded wrongly is Voided rather
 * than edited: the mistake and its correction both stay on the record, which
 * is the whole difference between a ledger and a spreadsheet.
 */
#[Fillable([
    'supplier_id',
    'supplied_on',
    'reference',
    'user_id',
])]
class Supply extends Model
{
    /** @use HasFactory<SupplyFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'supplied_on' => 'date',
        ];
    }

    /**
     * What was delivered is what was delivered. The panel offers no edit and
     * no delete route, and this is what holds that everywhere else.
     */
    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('A Supply is never edited. Void it and record it again instead.');
        });

        static::deleting(function (): never {
            throw new LogicException('A Supply is never deleted. Void it instead, so the mistake and its correction both stay on the record.');
        });
    }

    /**
     * Who it came from, trashed or not: a Supplier taken off the list keeps
     * naming every delivery they ever made.
     *
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    /**
     * A line per Product. The document is these rows; the header carries no
     * quantity and no total of its own, because a figure held apart from the
     * rows it is the sum of is a figure that can disagree with them.
     *
     * @return HasMany<SupplyLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SupplyLine::class);
    }

    /**
     * Who recorded it, so a surprising figure has somebody to ask about it.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * What the whole delivery cost, in minor units: every line's quantity at
     * the cost that line was bought at.
     *
     * Worked out from the lines each time rather than stored, so it cannot
     * drift from them.
     */
    public function totalCost(): int
    {
        return $this->lines->sum(fn (SupplyLine $line): int => $line->lineCost());
    }
}
