<?php

namespace App\Models;

use App\Enums\AdjustmentDirection;
use Database\Factories\AdjustmentCauseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Why a shelf was out by what an Adjustment moved: one of a list the Merchant
 * keeps and adds to (ADR-0040).
 *
 * Picked rather than typed, because "what am I losing to damage" is asked of a
 * year of Adjustments at once and only answers if the same thing is spelled the
 * same way every time. Each says which way it may move stock, so only the
 * causes that fit a change are offered.
 *
 * Renamed in place, which renames it on every Count that carries it — it is
 * the same cause, spelled right. Retired rather than deleted once anything
 * carries it: off the list from then on, still on every Count it explains.
 * Only one nobody has used can be deleted.
 */
#[Fillable(['name', 'direction'])]
class AdjustmentCause extends Model
{
    /** @use HasFactory<AdjustmentCauseFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'direction' => AdjustmentDirection::class,
            'retired_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (AdjustmentCause $cause): void {
            if ($cause->isUsed()) {
                throw new LogicException("Adjustment cause {$cause->getKey()} explains a Stock Count, so it is retired rather than deleted.");
            }
        });
    }

    /**
     * @return HasMany<StockCount, $this>
     */
    public function stockCounts(): HasMany
    {
        return $this->hasMany(StockCount::class);
    }

    public function isUsed(): bool
    {
        return $this->stockCounts()->exists();
    }

    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }

    /**
     * Whether this cause may explain a change of so many grams: on the list,
     * and going the way the change went.
     */
    public function fits(int $difference): bool
    {
        return ! $this->isRetired() && $this->direction->fits($difference);
    }

    public function retire(): void
    {
        $this->forceFill(['retired_at' => now()])->save();
    }

    public function reinstate(): void
    {
        $this->forceFill(['retired_at' => null])->save();
    }

    /**
     * The causes still on the list.
     *
     * @param  Builder<AdjustmentCause>  $query
     */
    #[Scope]
    protected function current(Builder $query): void
    {
        $query->whereNull('retired_at');
    }
}
