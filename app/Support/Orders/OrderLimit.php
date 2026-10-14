<?php

namespace App\Support\Orders;

use App\Enums\OrderKind;
use App\Models\Product;
use App\Support\Inventory\Grams;

/**
 * An Order Limit (CONTEXT.md): the least and the most of one Product a single
 * Order may hold one way of selling, in grams, each end null for no limit.
 *
 * Measured on the Product's whole weight in the Order — every line and every
 * Pack of it added up — and never on one line, so whoever asks adds the
 * weights first. It binds a User exactly as it binds a Customer: there is no
 * way past one, and a deal is struck through a Price Override instead.
 */
final readonly class OrderLimit
{
    public function __construct(
        public OrderKind $kind,
        public ?int $minGrams,
        public ?int $maxGrams,
    ) {}

    /** The Product's limit for that way of selling: পাইকারি in whole kilograms, খুচরা by weight. */
    public static function of(Product $product, OrderKind $kind): self
    {
        return match ($kind) {
            OrderKind::Bulk => new self(
                $kind,
                $product->bulk_min_kilograms === null ? null : Grams::fromKilograms($product->bulk_min_kilograms),
                $product->bulk_max_kilograms === null ? null : Grams::fromKilograms($product->bulk_max_kilograms),
            ),
            OrderKind::Retail => new self($kind, $product->retail_min_grams, $product->retail_max_grams),
        };
    }

    /** Why the weight is refused, for a User to read — "Assam: 0.35 kg is below its খুচরা minimum of 0.5 kg." — or null where it is within. */
    public function refusal(string $productName, int $grams): ?string
    {
        $way = $this->kind === OrderKind::Bulk ? 'পাইকারি' : 'খুচরা';

        return match (true) {
            $this->minGrams !== null && $grams < $this->minGrams => sprintf('%s: %s is below its %s minimum of %s.', $productName, Grams::format($grams), $way, Grams::format($this->minGrams)),
            $this->maxGrams !== null && $grams > $this->maxGrams => sprintf('%s: %s is above its %s maximum of %s.', $productName, Grams::format($grams), $way, Grams::format($this->maxGrams)),
            default => null,
        };
    }

    /** Why the ends cannot be saved, or null where they can: each above nothing, and the most never below the least. */
    public static function problem(?int $min, ?int $max): ?string
    {
        return match (true) {
            ($min !== null && $min < 1) || ($max !== null && $max < 1) => 'each end of an Order Limit must be above nothing.',
            $min !== null && $max !== null && $max < $min => 'an Order Limit\'s maximum cannot be below its minimum.',
            default => null,
        };
    }

    /**
     * The Products whose whole weight is outside their limit that way, in the
     * order first named. The lines' weights are added up per Product here, so
     * no caller measures one line alone.
     *
     * @param  iterable<array{0: Product, 1: int}>  $weighed  each line's Product and the grams it holds
     * @return array<int, string> why each is refused, by Product id
     */
    public static function refusals(OrderKind $kind, iterable $weighed): array
    {
        $grams = [];
        $products = [];

        foreach ($weighed as [$product, $lineGrams]) {
            $grams[$product->getKey()] = ($grams[$product->getKey()] ?? 0) + $lineGrams;
            $products[$product->getKey()] = $product;
        }

        $refusals = [];

        foreach ($products as $productId => $product) {
            $refusal = self::of($product, $kind)->refusal($product->name, $grams[$productId]);

            if ($refusal !== null) {
                $refusals[$productId] = $refusal;
            }
        }

        return $refusals;
    }
}
