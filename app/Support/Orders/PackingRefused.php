<?php

namespace App\Support\Orders;

use App\Models\Pack;
use App\Models\Product;
use App\Support\Bangla;
use App\Support\Inventory\Grams;

/**
 * Raised where a bulk line asks for Kilograms its Pack cannot make exactly —
 * one kilogram in 400 g Packs (ADR-0036), at Capture or at Repricing. Never
 * rounded to a weight nobody asked for: the line is refused, naming the whole
 * Kilograms nearest to it that the Pack does make, so the buyer can choose one.
 */
class PackingRefused extends CheckoutUnavailable
{
    /**
     * @param  list<int>  $workableKilograms  The whole Kilograms nearest to those asked for that the Pack makes exactly.
     */
    public function __construct(
        public readonly int $productId,
        public readonly string $productName,
        public readonly int $kilograms,
        public readonly int $packGrams,
        public readonly array $workableKilograms,
    ) {
        parent::__construct(sprintf(
            '%s: %d kg cannot be packed in %s Packs; %s kg can.',
            $productName,
            $kilograms,
            Pack::nameFor($packGrams),
            implode(' or ', $workableKilograms),
        ));
    }

    /** The refusal a line's Kilograms in its Pack would meet, or null where they make a whole number of packets. */
    public static function of(Product $product, int $kilograms, int $packGrams): ?self
    {
        return Grams::packetsIn(Grams::fromKilograms($kilograms), $packGrams) === null
            ? new self($product->getKey(), $product->name, $kilograms, $packGrams, Grams::workableKilograms($kilograms, $packGrams))
            : null;
    }

    /** Refuses a line's Kilograms its Pack cannot make exactly. */
    public static function unlessPackable(Product $product, int $kilograms, int $packGrams): void
    {
        $refusal = self::of($product, $kilograms, $packGrams);

        if ($refusal !== null) {
            throw $refusal;
        }
    }

    /** The refusal as the buyer reads it: "Assam ২৫০ গ্রাম প্যাকেটে ১ কেজি হয় না — ২ বা ৪ কেজি নিন।" */
    public function banglaMessage(): string
    {
        return sprintf(
            '%s %s প্যাকেটে %s কেজি হয় না — %s কেজি নিন।',
            $this->productName,
            Pack::banglaNameFor($this->packGrams),
            Bangla::number($this->kilograms),
            implode(' বা ', array_map(fn (int $kilograms): string => Bangla::number($kilograms), $this->workableKilograms)),
        );
    }
}
