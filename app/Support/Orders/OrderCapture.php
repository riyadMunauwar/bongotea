<?php

namespace App\Support\Orders;

use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Support\Inventory\StockLedger;
use Illuminate\Support\Facades\DB;

/**
 * Capture: the moment a Cart becomes an Order.
 *
 * What is agreed here is fixed from here — the products, their prices and the
 * totals. The one rule this class exists to enforce is that *nothing priced
 * comes from the caller*: it hands over which Products and how many kilograms
 * of each, and the delivery details, and every figure on the resulting Order
 * is derived server-side from the Products themselves.
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
     *
     * @throws CheckoutUnavailable where the Cart or the details cannot become an Order
     */
    public function capture(array $lines, array $details): Order
    {
        if (blank($details['district'] ?? null)) {
            throw new CheckoutUnavailable('An Order was captured with no District.');
        }

        /**
         * The lines are built before the transaction opens, so a Cart that
         * cannot be sold as it stands stops the Capture rather than being
         * quietly sold in part — a COD parcel holding less than the Customer
         * agreed to is refused at the door, at the Merchant's expense.
         */
        $lines = $this->lines($lines);

        /**
         * Resolved outside the transaction: firstOrCreate recovers from a
         * unique-constraint race by re-querying, and a failed insert inside an
         * open transaction is not something every database lets you continue
         * from.
         */
        $customer = Customer::forPhone($details['phone']);

        return DB::transaction(function () use ($customer, $details, $lines): Order {
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

                /**
                 * How the Customer said they would pay, by name. Whether money
                 * then moved is its own axis, answered by the Order's Payments
                 * and never by this column or by `status` (ADR-0013).
                 */
                'payment_method' => $details['payment_method'],
            ]);

            foreach ($lines as $line) {
                $order->lines()->create($line);
            }

            /** The lines' observer recomputed the totals; load them back. */
            return $order->refresh();
        });
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
     * One Order Line per Product in the Cart, snapshotted.
     *
     * `product_id` is kept as a reference for the admin, but the name and the
     * price are copied because they are what gets quoted on the confirmation
     * call and collected at the door.
     *
     * @param  list<array{product: Product, kilograms: int}>  $cart
     * @return list<array{product_id: int, product_name: string, unit_price: int, unit_cost: int|null, quantity: int}>
     */
    private function lines(array $cart): array
    {
        if ($cart === []) {
            throw new CheckoutUnavailable('An Order was captured with an empty Cart.');
        }

        $seen = [];

        foreach ($cart as $line) {
            $product = $line['product'];

            if ($product->trashed()) {
                throw new CheckoutUnavailable("Product [{$product->getKey()}] is no longer sold.");
            }

            if ($line['kilograms'] < 1) {
                throw new CheckoutUnavailable("Product [{$product->getKey()}] was captured with no kilograms.");
            }

            if (isset($seen[$product->getKey()])) {
                throw new CheckoutUnavailable("Product [{$product->getKey()}] appears twice in one Cart.");
            }

            $seen[$product->getKey()] = true;
        }

        /**
         * The whole Cart's cost in one batched read, the way the catalogue
         * loads it for a list, rather than a correlated subquery per line
         * (#170). A Product nothing has ever been recorded buying is absent
         * from the map, which is read below as the same unknown a per-row
         * `averageCost()` answers with.
         */
        $costs = $this->ledger->averageCostFor(array_keys($seen));

        return array_map(fn (array $line): array => [
            'product_id' => $line['product']->getKey(),
            'product_name' => $line['product']->name,
            'unit_price' => $line['product']->price,

            /**
             * What the units cost, read off the ledger at this instant and
             * fixed from here. Taken at Capture because `Sales` is counted at
             * Capture, so margin and revenue are always counted over the same
             * set of Orders (ADR-0022). Null where nothing has ever been
             * recorded as bought, which is unknown and not free.
             */
            'unit_cost' => $costs[$line['product']->getKey()] ?? null,

            'quantity' => $line['kilograms'],
        ], $cart);
    }
}
