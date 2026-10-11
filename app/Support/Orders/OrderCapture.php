<?php

namespace App\Support\Orders;

use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Support\Inventory\StockLedger;
use App\Support\Pricing\CartLine;
use App\Support\Pricing\PriceBook;
use App\Support\Pricing\PricedCart;
use App\Support\Pricing\PricedLine;
use App\Support\Pricing\ProductPrices;
use Illuminate\Support\Facades\DB;

/**
 * Capture: the moment a Cart becomes an Order.
 *
 * What is agreed here is fixed from here — the products, their prices and the
 * totals. The one rule this class exists to enforce is that *nothing priced
 * comes from the caller*: it hands over which Products and how many kilograms
 * of each, and the delivery details, and every figure on the resulting Order
 * is worked out by the pricing module (ADR-0031) — each line's Tier Price by
 * the whole Cart's Kilograms, and the Delivery Charge by the same total.
 *
 * The Checkout also hands over the Quote the Customer was shown. It is
 * compared, never believed: where the store's own figures differ, or a
 * Product in the Cart can no longer be had, Capture refuses with the figures
 * as they are now rather than placing an Order for a total the Customer did
 * not agree to (ADR-0029).
 *
 * The same rule is why the cost stamp lives here. A line records what the
 * units cost as well as what they sold for, and the figure comes from the
 * stock ledger at this instant rather than from anything the caller sent or
 * anything read back later (ADR-0022).
 */
class OrderCapture
{
    public function __construct(private readonly StockLedger $ledger) {}

    /**
     * @param  list<array{product: Product, kilograms: int}>  $lines  the Cart, one line per Product
     * @param  array{name: string|null, phone: string, email?: string|null, address: string|null, district: string|null, shop_name?: string|null, note?: string|null, payment_method: PaymentMethod}  $details
     * @param  Quote|null  $quote  what the Customer was shown; null where nobody was shown figures, as for an Order a seeder or a test captures
     *
     * @throws QuoteRefused where a Product in the Cart cannot be had now, or the Quote disagrees with the store
     * @throws CheckoutUnavailable where the Cart or the details cannot become an Order
     */
    public function capture(array $lines, array $details, ?Quote $quote = null): Order
    {
        if (blank($details['district'] ?? null)) {
            throw new CheckoutUnavailable('An Order was captured with no District.');
        }

        $this->refuseMalformed($lines);

        /**
         * Priced before the transaction opens, so a Cart that cannot be sold
         * as it stands stops the Capture rather than being quietly sold in
         * part — a COD parcel holding less than the Customer agreed to is
         * refused at the door, at the Merchant's expense.
         */
        $priced = $this->price($lines, $quote);

        $costs = $this->ledger->averageCostFor(array_map(fn (array $line): int => $line['product']->getKey(), $lines));

        /**
         * Resolved outside the transaction: firstOrCreate recovers from a
         * unique-constraint race by re-querying, and a failed insert inside an
         * open transaction is not something every database lets you continue
         * from.
         */
        $customer = Customer::forPhone($details['phone']);

        return DB::transaction(function () use ($customer, $details, $lines, $priced, $costs): Order {
            $order = $customer->orders()->create([
                'customer_name' => $details['name'],

                /**
                 * The canonical form, not the spelling typed. The digits are
                 * the information; the punctuation around them is not, and a
                 * fulfiller dialling from this field wants the number that
                 * works.
                 */
                'customer_phone' => $customer->phone,

                'customer_email' => $details['email'] ?? null,
                'customer_address' => $details['address'],
                'customer_district' => $details['district'],
                'shop_name' => self::givenOrNull($details['shop_name'] ?? null),
                'note' => self::givenOrNull($details['note'] ?? null),
                'delivery_charge' => $priced->deliveryCharge,
                'delivery_label' => $priced->deliveryBand?->label(),

                /**
                 * How the Customer said they would pay, by name. Whether money
                 * then moved is its own axis, answered by the Order's Payments
                 * and never by this column or by `status` (ADR-0013).
                 */
                'payment_method' => $details['payment_method'],
            ]);

            foreach ($lines as $index => $line) {
                $order->lines()->create($this->line($line['product'], $priced->lines[$index], $costs));
            }

            /** The lines' observer recomputed the totals; load them back. */
            return $order->refresh();
        });
    }

    /**
     * The Cart priced by the store, or a refusal: a Product a buyer cannot
     * have now refuses before any price is compared, and the Quote, where
     * there is one, must agree with every figure.
     *
     * @param  list<array{product: Product, kilograms: int}>  $lines
     */
    private function price(array $lines, ?Quote $quote): PricedCart
    {
        $orderable = [];
        $refusals = [];

        foreach ($lines as $line) {
            $why = self::whyUnorderable($line['product']);

            if ($why === null) {
                $orderable[] = $line;
            } else {
                $refusals[$line['product']->getKey()] = $why;
            }
        }

        if ($refusals !== []) {
            $first = array_key_first($refusals);

            throw new QuoteRefused("Product [{$first}] {$refusals[$first]}.", self::priceOf($orderable), array_keys($refusals));
        }

        $priced = self::priceOf($lines);

        if ($quote !== null && ! $quote->agreesWith($priced)) {
            throw new QuoteRefused('The Quote no longer agrees with what the store charges.', $priced);
        }

        return $priced;
    }

    /**
     * @param  list<array{product: Product, kilograms: int}>  $lines
     */
    private static function priceOf(array $lines): PricedCart
    {
        return PriceBook::current()->price(array_map(
            fn (array $line): CartLine => new CartLine(ProductPrices::of($line['product']), $line['kilograms']),
            $lines,
        ));
    }

    /**
     * Why a buyer cannot have the Product now, or null where they can. Three
     * separate answers, as the catalogue keeps them: taken out for good, never
     * or no longer published, and unavailable for now.
     */
    private static function whyUnorderable(Product $product): ?string
    {
        return match (true) {
            $product->trashed() => 'is no longer sold',
            ! $product->isPublished() => 'is not published',
            ! $product->is_available => 'is not available now',
            default => null,
        };
    }

    /**
     * @param  list<array{product: Product, kilograms: int}>  $lines
     *
     * @throws CheckoutUnavailable
     */
    private function refuseMalformed(array $lines): void
    {
        if ($lines === []) {
            throw new CheckoutUnavailable('An Order was captured with an empty Cart.');
        }

        $seen = [];

        foreach ($lines as $line) {
            $product = $line['product'];

            if ($line['kilograms'] < 1) {
                throw new CheckoutUnavailable("Product [{$product->getKey()}] was captured with no kilograms.");
            }

            if (isset($seen[$product->getKey()])) {
                throw new CheckoutUnavailable("Product [{$product->getKey()}] appears twice in one Cart.");
            }

            $seen[$product->getKey()] = true;
        }
    }

    /**
     * An optional detail as it is stored: trimmed, and null where nothing was
     * given, so the column holds two states and not three.
     */
    private static function givenOrNull(?string $detail): ?string
    {
        return filled($detail) ? trim($detail) : null;
    }

    /**
     * One Order Line, snapshotted.
     *
     * `product_id` is kept as a reference for the admin, but the name and the
     * prices are copied because they are what gets quoted on the confirmation
     * call and collected at the door.
     *
     * @param  array<int, int>  $costs  the whole Cart's average costs, read in one batch (#170)
     * @return array{product_id: int, product_name: string, unit_price: int, mrp: int, unit_cost: int|null, quantity: int, tier_min: int|null, tier_max: int|null}
     */
    private function line(Product $product, PricedLine $priced, array $costs): array
    {
        return [
            'product_id' => $product->getKey(),
            'product_name' => $product->name,
            'unit_price' => $priced->tierPrice,
            'mrp' => $priced->mrp,

            /**
             * What the units cost, read off the ledger at this instant and
             * fixed from here. Taken at Capture because `Sales` is counted at
             * Capture, so margin and revenue are always counted over the same
             * set of Orders (ADR-0022). Null where nothing has ever been
             * recorded as bought, which is unknown and not free.
             */
            'unit_cost' => $costs[$product->getKey()] ?? null,

            'quantity' => $priced->kilograms,
            'tier_min' => $priced->tier?->min,
            'tier_max' => $priced->tier?->max,
        ];
    }
}
