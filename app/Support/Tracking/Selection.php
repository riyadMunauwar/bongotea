<?php

namespace App\Support\Tracking;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * What a Moment says was being bought, and what it is worth.
 *
 * There is no cart in this domain, so this is not one: it is the reading every
 * Provider needs to report money — a line per Product with its quantity and
 * its price, and the one figure the event carries as its value. An Order
 * answers it from what was captured; a visitor who has only started filling
 * the Checkout answers it from what is ticked in front of them.
 *
 * Written in our own terms and never a platform's. A driver turns it into
 * `contents` or into whatever its own platform calls the same thing, and
 * nothing here knows which.
 *
 * Money is minor units throughout, as everywhere else in the system; a driver
 * converts at the wire.
 */
final class Selection
{
    /**
     * @param  list<array{id: string, quantity: int, unit_price: int}>  $lines
     * @param  int  $value  what the event reports as its worth, in minor units
     */
    private function __construct(
        public readonly array $lines,
        public readonly int $value,
    ) {}

    /**
     * What an Order was for, worth what the Order came to.
     *
     * The value is the total and not the sum of the lines, because the total
     * already carries the Delivery Charge — the same figure the Merchant's own
     * Dashboard calls Sales, so the two never disagree.
     */
    public static function forOrder(Order $order): self
    {
        return self::forLines($order->lines, (int) $order->total);
    }

    /**
     * What a set of Order Lines was for, worth what they come to unless a
     * caller knows better — an Order does, because its total carries the
     * Delivery Charge the lines do not.
     *
     * @param  Collection<int, OrderLine>  $lines
     */
    public static function forLines(Collection $lines, ?int $value = null): self
    {
        $selection = $lines->map(fn (OrderLine $line): array => [
            'id' => self::identify($line->product_id, $line->product_name),
            'quantity' => (int) $line->quantity,
            'unit_price' => (int) $line->unit_price,
        ])->values()->all();

        return new self($selection, $value ?? self::sum($selection));
    }

    /**
     * What a visitor has in front of them: the Products named, each at the
     * quantity given, priced from the Products themselves.
     *
     * Prices are read here and never accepted from whoever named the
     * Products, exactly as every figure on an Order is derived at Capture —
     * what arrives is a list of names and a count, and nothing
     * that arrives can say what anything costs.
     *
     * The value is what those Products come to and carries no Delivery
     * Charge. The Charge is what it costs to bring the parcel rather than
     * part of what was chosen, and it enters a reported value at the Purchase,
     * where the figure has to agree with the Merchant's own Sales. So this is
     * deliberately smaller than the Total the form shows, which is what the
     * courier collects.
     *
     * Nothing chosen is a Selection worth nothing, not a Selection of
     * everything: a Product Picker whose Default Selection is empty opens on
     * an empty form, and that is what the visitor is looking at.
     *
     * @param  Collection<int, Product>  $products
     * @param  array<int, int>  $quantities  how many of each, keyed by Product id
     */
    public static function forProducts(Collection $products, array $quantities): self
    {
        $lines = $products->map(fn (Product $product): array => [
            'id' => self::identify($product->getKey(), $product->name),
            'quantity' => max(1, (int) ($quantities[$product->getKey()] ?? 1)),
            'unit_price' => (int) $product->price,
        ])->values()->all();

        return new self($lines, self::sum($lines));
    }

    /**
     * A stored Selection, read totally and forgivingly like every other
     * stored shape here: a line missing what a line needs is dropped, and
     * anything that is not a Selection at all is nothing to report.
     *
     * Null and an empty Selection are the same answer to a caller — both mean
     * there is no money on this event — so the distinction is not kept.
     */
    public static function fromArray(mixed $stored): ?self
    {
        if (! is_array($stored)) {
            return null;
        }

        $lines = [];

        foreach ((array) ($stored['lines'] ?? []) as $line) {
            if (! is_array($line) || ! isset($line['id'], $line['quantity'], $line['unit_price'])) {
                continue;
            }

            $lines[] = [
                'id' => (string) $line['id'],
                'quantity' => (int) $line['quantity'],
                'unit_price' => (int) $line['unit_price'],
            ];
        }

        return new self($lines, (int) ($stored['value'] ?? self::sum($lines)));
    }

    /**
     * @return array{lines: list<array{id: string, quantity: int, unit_price: int}>, value: int}
     */
    public function toArray(): array
    {
        return ['lines' => $this->lines, 'value' => $this->value];
    }

    /** How many things are being bought: the quantities summed, not the lines counted. */
    public function quantity(): int
    {
        return array_sum(array_column($this->lines, 'quantity'));
    }

    /**
     * A line's Product as it is identified to a Provider: the Product's id
     * while the Product exists, and the name it was bought under otherwise,
     * so a catalogue match survives a deletion as far as it can.
     */
    private static function identify(int|string|null $productId, ?string $productName): string
    {
        return (string) ($productId ?? $productName);
    }

    /**
     * @param  list<array{id: string, quantity: int, unit_price: int}>  $lines
     */
    private static function sum(array $lines): int
    {
        $value = 0;

        foreach ($lines as $line) {
            $value += $line['unit_price'] * $line['quantity'];
        }

        return $value;
    }
}
