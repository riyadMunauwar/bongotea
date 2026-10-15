<?php

namespace App\Support\Tracking;

use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Pack;
use App\Models\Product;
use App\Support\Pricing\CartLine;
use App\Support\Pricing\PackPrices;
use App\Support\Pricing\PriceBook;
use App\Support\Pricing\PricedLine;
use App\Support\Pricing\PricedPackLine;
use App\Support\Pricing\ProductPrices;
use App\Support\Pricing\RetailLine;
use App\Support\Pricing\RetailPriceBook;
use Illuminate\Support\Collection;

/**
 * What a Moment says was being bought, and what it is worth.
 *
 * Not the Cart, which lives in the browser: it is the reading every Provider
 * needs to report money — a line per Product with its quantity and its price,
 * and the one figure the event carries as its value. An Order answers it from
 * what was captured; a Moment the browser reports answers it from the
 * Products and Kilograms the browser named.
 *
 * Written in our own terms and never a platform's. A driver turns it into
 * `contents` or into whatever its own platform calls the same thing, and
 * nothing here knows which.
 *
 * Money is minor units throughout, as everywhere else in the system; a driver
 * converts at the wire.
 *
 * Every Selection says which kind of Cart it came from, `bulk` or `retail`
 * (ADR-0034), and — where it is known — which Cart, by id and by the name it
 * bore (ADR-0038), so an Added to Cart in Eid Gift Packs can be told apart. A Pack is reported as its Product — the Product's id, the Pack
 * count as the quantity, the Pack Price as the unit price — with its weight
 * as the line's `variant`, so the ad platforms see one tea however it is sold.
 */
final class Selection
{
    public const string BULK = 'bulk';

    public const string RETAIL = 'retail';

    /**
     * @param  list<array{id: string, quantity: int, unit_price: int, variant?: string}>  $lines
     * @param  int  $value  what the event reports as its worth, in minor units
     * @param  string  $cart  which kind of Cart it came from: `bulk` or `retail`
     * @param  int|null  $cartId  the Cart itself (ADR-0038), where it is known
     * @param  string|null  $cartName  the name that Cart bore then
     */
    private function __construct(
        public readonly array $lines,
        public readonly int $value,
        public readonly string $cart = self::BULK,
        public readonly ?int $cartId = null,
        public readonly ?string $cartName = null,
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
        return self::forLines($order->lines, (int) $order->total, $order->isRetail() ? self::RETAIL : self::BULK, $order->cart_id, $order->cart_name);
    }

    /**
     * What a set of Order Lines was for, worth what they come to unless a
     * caller knows better — an Order does, because its total carries the
     * Delivery Charge the lines do not.
     *
     * @param  Collection<int, OrderLine>  $lines
     */
    public static function forLines(Collection $lines, ?int $value = null, string $cart = self::BULK, ?int $cartId = null, ?string $cartName = null): self
    {
        $selection = $lines->map(fn (OrderLine $line): array => [
            'id' => self::identify($line->product_id, $line->product_name),
            'quantity' => (int) $line->quantity,
            'unit_price' => (int) $line->unit_price,
            ...($line->isPack() ? ['variant' => Pack::nameFor($line->pack_grams)] : []),
        ])->values()->all();

        return new self($selection, $value ?? self::sum($selection), $cart, $cartId, $cartName);
    }

    /**
     * What a visitor has in front of them: the Products named, each at the
     * Kilograms given, priced by the pricing module as if they were a Cart of
     * their own — so each line is at the Tier the Kilograms named reach.
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
     * deliberately smaller than the Total the Checkout shows, which is what
     * the courier collects.
     *
     * Nothing named is a Selection worth nothing, not a Selection of
     * everything.
     *
     * @param  Collection<int, Product>  $products
     * @param  array<int, int>  $kilograms  how many of each, keyed by Product id
     */
    public static function forProducts(Collection $products, array $kilograms, ?Cart $from = null): self
    {
        $priced = PriceBook::current()->price($products->map(fn (Product $product): CartLine => new CartLine(
            ProductPrices::of($product),
            max(1, (int) ($kilograms[$product->getKey()] ?? 1)),
        ))->values()->all());

        $lines = array_map(fn (PricedLine $line): array => [
            'id' => self::identify($line->productId, null),
            'quantity' => $line->kilograms,
            'unit_price' => $line->tierPrice,
        ], $priced->lines);

        return new self($lines, $priced->subtotal, self::BULK, $from?->getKey(), $from?->name);
    }

    /**
     * What a visitor has in their packet basket: the Packs named, each at the
     * count given, priced by retail pricing (ADR-0034) and reported as their
     * Products. Like `forProducts()`, prices are read here and never taken
     * from whoever named the Packs, and no Delivery Charge rides on top.
     *
     * @param  Collection<int, Pack>  $packs
     * @param  array<int, int>  $counts  how many of each, keyed by Pack id
     */
    public static function forPacks(Collection $packs, array $counts, ?Cart $from = null): self
    {
        $packs = $packs->values();

        $priced = RetailPriceBook::current()->price($packs->map(fn (Pack $pack): RetailLine => new RetailLine(
            PackPrices::of($pack),
            max(1, (int) ($counts[$pack->getKey()] ?? 1)),
        ))->all());

        $lines = array_map(fn (PricedPackLine $line, Pack $pack): array => [
            'id' => self::identify($pack->product_id, null),
            'quantity' => $line->count,
            'unit_price' => $line->packPrice,
            'variant' => $pack->name(),
        ], $priced->lines, $packs->all());

        return new self($lines, $priced->subtotal, self::RETAIL, $from?->getKey(), $from?->name);
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
                ...(is_string($line['variant'] ?? null) ? ['variant' => $line['variant']] : []),
            ];
        }

        /** A row written before Selections named their Cart was bulk, which is all there was. */
        $cart = ($stored['cart'] ?? null) === self::RETAIL ? self::RETAIL : self::BULK;

        return new self(
            $lines,
            (int) ($stored['value'] ?? self::sum($lines)),
            $cart,
            is_int($stored['cart_id'] ?? null) ? $stored['cart_id'] : null,
            is_string($stored['cart_name'] ?? null) ? $stored['cart_name'] : null,
        );
    }

    /**
     * @return array{lines: list<array{id: string, quantity: int, unit_price: int, variant?: string}>, value: int, cart: string, cart_id?: int, cart_name?: string}
     */
    public function toArray(): array
    {
        return [
            'lines' => $this->lines,
            'value' => $this->value,
            'cart' => $this->cart,
            ...($this->cartId === null ? [] : ['cart_id' => $this->cartId, 'cart_name' => $this->cartName]),
        ];
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
