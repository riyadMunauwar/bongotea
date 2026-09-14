<?php

namespace App\Models;

use App\Support\Inventory\SupplyAlreadyVoided;
use Database\Factories\SupplyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
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
    'voided_at',
    'voided_by',
])]
class Supply extends Model
{
    /** @use HasFactory<SupplyFactory> */
    use HasFactory;

    /**
     * The only thing that may ever be written to a Supply after it is
     * recorded: that it was voided, when, and by whom.
     *
     * Named here so the guard below reads as the rule rather than as a list
     * of columns somebody has to keep in step with it.
     *
     * @var list<string>
     */
    public const VOIDING = ['voided_at', 'voided_by'];

    protected function casts(): array
    {
        return [
            'supplied_on' => 'date',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * What was delivered is what was delivered. The panel offers no edit and
     * no delete route, and this is what holds that everywhere else.
     *
     * The one exception is voiding, and it is an exception in the narrowest
     * possible sense: the document is not corrected, it is struck out. What
     * it said stays exactly as it was typed, and the two columns that may
     * change say only that it does not count — which is why they are allowed
     * through here and every other attribute is not.
     */
    protected static function booted(): void
    {
        static::updating(function (Supply $supply): void {
            $changing = array_diff(array_keys($supply->getDirty()), [...self::VOIDING, $supply->getUpdatedAtColumn()]);

            if ($changing !== []) {
                throw new LogicException('A Supply is never edited. Void it and record it again instead.');
            }

            /** Voided once, whatever the screen that asked was showing: the second one has nothing left to take back out. */
            if ($supply->getOriginal('voided_at') !== null) {
                throw new SupplyAlreadyVoided($supply);
            }
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
     * Who struck it out, where somebody has. A second name beside the one
     * that recorded it, which is most of what a void is for.
     *
     * @return BelongsTo<User, $this>
     */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * Whether this delivery has been struck out.
     *
     * A voided Supply is still a Supply and still on every list. What it is
     * not is a delivery that counts: the ledger has already taken every unit
     * of it back out, and no buying figure may add it up.
     */
    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /**
     * The deliveries that stand — what "how much have we bought" means
     * wherever anything counts or sums Supplies.
     *
     * @param  Builder<Supply>  $query
     */
    #[Scope]
    protected function notVoided(Builder $query): void
    {
        $query->whereNull('voided_at');
    }

    /**
     * The deliveries that were struck out. Read on their own only by somebody
     * asking what went wrong, which is exactly why they are still here.
     *
     * @param  Builder<Supply>  $query
     */
    #[Scope]
    protected function voided(Builder $query): void
    {
        $query->whereNotNull('voided_at');
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
