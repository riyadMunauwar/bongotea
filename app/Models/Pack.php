<?php

namespace App\Models;

use App\Enums\OrderKind;
use App\Support\Inventory\Grams;
use Database\Factories\PackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A sealed size of one Product (ADR-0036), sold whole and counted, whichever
 * way the Customer buys.
 *
 * Known by its weight alone: `grams` is its name, and no two Packs of one
 * Product weigh the same. It is ticked for the ways it serves — `serves_bulk`
 * (পাইকারি) and `serves_retail` (খুচরা) — at least one, and only ways its
 * Product is sold. Only a Pack ticked খুচরা needs `mrp` and `price` (the Pack
 * Price), minor units above nothing with the Pack Price never above the MRP;
 * a price typed on a Pack serving only পাইকারি opens it to nobody. All refused
 * on save otherwise, whoever saves it. It holds no stock of its own; a Pack
 * sold is weighed out of its Product's (ADR-0035).
 */
#[Fillable(['product_id', 'grams', 'serves_bulk', 'serves_retail', 'mrp', 'price', 'is_available', 'sort'])]
class Pack extends Model
{
    /** @use HasFactory<PackFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'grams' => 'integer',
            'serves_bulk' => 'boolean',
            'serves_retail' => 'boolean',
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

        if (! $this->serves_bulk && ! $this->serves_retail) {
            return 'it must serve পাইকারি, খুচরা or both.';
        }

        $product = $this->product()->first();

        if ($product !== null && (($this->serves_bulk && ! $product->sold_bulk) || ($this->serves_retail && ! $product->sold_retail))) {
            return 'it serves a way its Product is not sold.';
        }

        if ($this->serves_retail && ($this->mrp === null || $this->mrp < 1 || $this->price === null || $this->price < 1)) {
            return 'a Pack sold খুচরা needs an MRP and a Pack Price above nothing.';
        }

        if ($this->serves_retail && $this->price > $this->mrp) {
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
        return self::nameFor($this->grams);
    }

    /** The name a Pack of this weight goes by, for a line that outlived its Pack. */
    public static function nameFor(int $grams): string
    {
        return $grams < Grams::PER_KILOGRAM ? "{$grams} g" : Grams::format($grams);
    }

    /** Whether the Pack is ticked for that way of buying; a price typed on it says nothing. */
    public function servesAs(OrderKind $kind): bool
    {
        return match ($kind) {
            OrderKind::Bulk => $this->serves_bulk,
            OrderKind::Retail => $this->serves_retail,
        };
    }

    /**
     * Whether the Pack is sold that way at all: ticked for it, and its
     * Product switched to it — whatever either's Available says. Both are
     * asked, since a Product's switch turned off outside its form leaves its
     * Packs' ticks as they were.
     */
    public function isSoldAs(OrderKind $kind): bool
    {
        return $this->servesAs($kind) && ($this->product?->isSoldAs($kind) ?? false);
    }

    /**
     * Whether a Customer can order this Pack that way now: it is sold that
     * way, its own switch is on, and its Product is published, Available and
     * still in the catalogue. A Product made unavailable takes every Pack
     * with it; a Pack switched off leaves its Product untouched.
     */
    public function isOrderableAs(OrderKind $kind): bool
    {
        $product = $this->product;

        return $this->is_available
            && $this->isSoldAs($kind)
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
