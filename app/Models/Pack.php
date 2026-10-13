<?php

namespace App\Models;

use App\Support\Inventory\Grams;
use Database\Factories\PackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A sealed retail size of one Product (ADR-0034), sold whole and counted.
 *
 * Known by its weight alone: `grams` is its name, and no two Packs of one
 * Product weigh the same. `mrp` and `price` (the Pack Price) are minor units,
 * both above nothing, and the Pack Price never exceeds the MRP — refused on
 * save otherwise, whoever saves it. It holds no stock of its own; a Pack sold
 * is weighed out of its Product's (ADR-0035).
 */
#[Fillable(['product_id', 'grams', 'mrp', 'price', 'is_available', 'sort'])]
class Pack extends Model
{
    /** @use HasFactory<PackFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'grams' => 'integer',
            'mrp' => 'integer',
            'price' => 'integer',
            'is_available' => 'boolean',
            'sort' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Pack $pack): void {
            $problem = $pack->problem();

            if ($problem !== null) {
                throw new LogicException("Pack {$pack->getKey()} cannot be saved: {$problem}");
            }
        });
    }

    private function problem(): ?string
    {
        if ($this->grams === null || $this->grams < 1) {
            return 'its weight must be above nothing.';
        }

        if ($this->mrp === null || $this->mrp < 1 || $this->price === null || $this->price < 1) {
            return 'its MRP and Pack Price must be above nothing.';
        }

        if ($this->price > $this->mrp) {
            return 'its Pack Price is above its MRP; nothing is sold above it.';
        }

        $weighedAlready = static::query()
            ->where('product_id', $this->product_id)
            ->where('grams', $this->grams)
            ->when($this->exists, fn (Builder $query): Builder => $query->whereKeyNot($this->getKey()))
            ->exists();

        return $weighedAlready ? "its Product already has a {$this->name()} Pack." : null;
    }

    /** A Pack's name is its weight, never typed: "250 g", "1 kg", "1.5 kg". */
    public function name(): string
    {
        return $this->grams < Grams::PER_KILOGRAM ? "{$this->grams} g" : Grams::format($this->grams);
    }

    /**
     * Whether a Customer can order this Pack now: its own switch is on, and
     * its Product is published, Available and still in the catalogue. A
     * Product made unavailable takes every Pack with it; a Pack switched off
     * leaves its Product untouched.
     */
    public function isOrderable(): bool
    {
        $product = $this->product;

        return $this->is_available
            && $product !== null
            && ! $product->trashed()
            && $product->isPublished()
            && $product->is_available;
    }

    /**
     * Its Product, even one taken out of the catalogue, so a Pack can still
     * say what it is of.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
