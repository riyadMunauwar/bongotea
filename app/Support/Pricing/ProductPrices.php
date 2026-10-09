<?php

namespace App\Support\Pricing;

use App\Models\Product;
use LogicException;

/**
 * What the pricing module needs to know about a Product, and nothing else:
 * its own Tiers, in order, the first starting at one and the last open-ended
 * (ADR-0031). The Regular Price is the first Tier's.
 */
final readonly class ProductPrices
{
    /**
     * @param  non-empty-list<Tier>  $tiers
     */
    public function __construct(
        public int|string $id,
        public array $tiers,
    ) {}

    public static function of(Product $product): self
    {
        return new self($product->getKey(), $product->priceTiers());
    }

    public function regularPrice(): int
    {
        return $this->tiers[0]->price;
    }

    /** An empty Cart sits in the first Tier, so it shows the Regular Price. */
    public function tierFor(int $totalKilograms): Tier
    {
        if ($totalKilograms === 0) {
            return $this->tiers[0];
        }

        foreach ($this->tiers as $tier) {
            if ($tier->contains($totalKilograms)) {
                return $tier;
            }
        }

        throw new LogicException("No Tier of Product {$this->id} covers {$totalKilograms} kg; a Product's Tiers must cover every whole kilogram from one.");
    }

    /** The first Tier above the Cart's weight that is cheaper than the one it is in, or null where none is. */
    public function nextDropAbove(int $totalKilograms): ?Tier
    {
        $current = $this->tierFor($totalKilograms);

        foreach ($this->tiers as $tier) {
            if ($tier->min > $totalKilograms && $tier->price < $current->price) {
                return $tier;
            }
        }

        return null;
    }
}
