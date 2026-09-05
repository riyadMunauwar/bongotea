<?php

namespace App\Support\Orders;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Page;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Capture: the moment a visitor submits the Checkout and an Order comes into
 * existence.
 *
 * What is agreed here is fixed from here — the products, their prices and the
 * totals. The one rule this class exists to enforce is that *nothing priced
 * comes from the request*: the visitor sends a Page, a quantity per item, the
 * name of the delivery arrangement they chose and their own details, and every
 * figure on the resulting Order is derived server-side from the Page's own
 * Products and its own Delivery Options.
 */
class OrderCapture
{
    /**
     * A detail this Page's Checkout does not ask for arrives null and is stored
     * null, which is why every column but the phone is nullable: an Order taken
     * on a form with no address box records that it has no address, rather than
     * a blank that reads as a Customer who declined to give one.
     *
     * @param  array{name: string|null, phone: string, email: string|null, address: string|null, district: string|null, delivery_option: string|null, selected: list<int>|null, quantities: array<int, int>}  $details
     */
    public function capture(Page $page, array $details): Order
    {
        if ($page->products->isEmpty()) {
            throw new CheckoutUnavailable("Page [{$page->slug}] has nothing left to sell.");
        }

        /**
         * A name arrives; the amount against it is read from the Page. What the
         * submission cannot do is carry a figure — a delivery charge accepted
         * from the request is a courier collecting whatever the visitor typed.
         */
        $delivery = $page->checkout_config->deliveryFor($details['delivery_option']);

        if ($delivery === null && $page->checkout_config->offersDelivery()) {
            throw new CheckoutUnavailable(
                "Page [{$page->slug}] was captured without one of the delivery options it offers."
            );
        }

        /**
         * The lines are built before the transaction opens, so a Product the
         * submission gave no quantity for stops the Capture rather than being
         * quietly sold at one — a COD parcel holding an item the Customer never
         * agreed to is refused at the door, at the Merchant's expense.
         */
        $lines = $this->lines($page, $details['quantities'], $details['selected']);

        /**
         * Resolved outside the transaction: firstOrCreate recovers from a
         * unique-constraint race by re-querying, and a failed insert inside an
         * open transaction is not something every database lets you continue
         * from.
         */
        $customer = Customer::forPhone($details['phone']);

        return DB::transaction(function () use ($page, $customer, $details, $delivery, $lines): Order {
            $order = $customer->orders()->create([
                'page_id' => $page->getKey(),

                /** The attribution that survives the Page being renamed or deleted. */
                'page_slug' => $page->slug,

                'customer_name' => $details['name'],

                /**
                 * The canonical form, not the spelling typed. The digits are
                 * the information; the punctuation around them is not, and a
                 * fulfiller dialling from this field wants the number that
                 * works.
                 */
                'customer_phone' => $customer->phone,

                'customer_email' => $details['email'],
                'customer_address' => $details['address'],
                'customer_district' => $details['district'],

                /**
                 * The arrangement in the Merchant's own words, beside what it
                 * cost. Snapshotted like a Product's name and price, and for
                 * the same reason: renaming or repricing "Inside Dhaka" next
                 * month must not rewrite what this Customer agreed to today.
                 *
                 * Null and zero where the Page offers no delivery at all, which
                 * is the Merchant saying there is nothing to charge for.
                 */
                'delivery_label' => $delivery?->label,
                'delivery_charge' => $delivery?->amount ?? 0,
            ]);

            foreach ($lines as $line) {
                $order->lines()->create($line);
            }

            /** The lines' observer recomputed the totals; load them back. */
            return $order->refresh();
        });
    }

    /**
     * One Order Line per Product the Customer is buying, snapshotted.
     *
     * `product_id` is kept as a reference for the admin, but the name and the
     * price are copied because they are what gets quoted on the confirmation
     * call and collected at the door.
     *
     * @param  array<int, int>  $quantities  keyed by Product id, per StoreOrderRequest
     * @param  list<int>|null  $selected  Product ids the Customer ticked, or null where the Page never asked
     * @return list<array{product_id: int, product_name: string, unit_price: int, quantity: int}>
     */
    private function lines(Page $page, array $quantities, ?array $selected): array
    {
        /**
         * The one place that decides what is in the parcel, asked of the Page.
         * A submission carries a list of ids and nothing more; the names, the
         * prices and the fact that a Product is on sale here all come from the
         * Page, like every other figure on the Order.
         */
        $products = $page->productsChosen($selected);

        if ($products->isEmpty()) {
            throw new CheckoutUnavailable("Page [{$page->slug}] was captured with nothing chosen to sell.");
        }

        return $products->map(function (Product $product) use ($page, $quantities): array {
            $quantity = $quantities[$product->getKey()] ?? null;

            if ($quantity === null) {
                throw new CheckoutUnavailable(
                    "Page [{$page->slug}] was captured with no quantity for product [{$product->getKey()}]."
                );
            }

            return [
                'product_id' => $product->getKey(),
                'product_name' => $product->name,
                'unit_price' => $product->price,
                'quantity' => $quantity,
            ];
        })->values()->all();
    }
}
