<?php

namespace App\Support\Orders;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Page;
use Illuminate\Support\Facades\DB;

/**
 * Capture: the moment a visitor submits the Checkout and an Order comes into
 * existence.
 *
 * What is agreed here is fixed from here — the products, their prices and the
 * totals. The one rule this class exists to enforce is that *nothing priced
 * comes from the request*: the visitor sends a Page, a quantity and their
 * delivery details, and every figure on the resulting Order is derived
 * server-side from the Page's own Product.
 */
class OrderCapture
{
    public function __construct(private readonly DeliveryCharge $deliveryCharge) {}

    /**
     * @param  array{name: string, phone: string, address: string, district: string, quantity: int}  $details
     */
    public function capture(Page $page, array $details): Order
    {
        $product = $page->soleProduct();

        if ($product === null) {
            throw new CheckoutUnavailable("Page [{$page->slug}] has no single product to sell.");
        }

        /**
         * Resolved outside the transaction: firstOrCreate recovers from a
         * unique-constraint race by re-querying, and a failed insert inside an
         * open transaction is not something every database lets you continue
         * from.
         */
        $customer = Customer::forPhone($details['phone']);

        return DB::transaction(function () use ($page, $product, $customer, $details): Order {
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

                'customer_address' => $details['address'],
                'customer_district' => $details['district'],
                'delivery_charge' => $this->deliveryCharge->forDistrict($details['district']),
            ]);

            /**
             * The snapshot. `product_id` is kept as a reference for the admin,
             * but the name and the price are copied because they are what gets
             * quoted on the confirmation call and collected at the door.
             */
            $order->lines()->create([
                'product_id' => $product->getKey(),
                'product_name' => $product->name,
                'unit_price' => $product->price,
                'quantity' => $details['quantity'],
            ]);

            /** The line's observer recomputed the totals; load them back. */
            return $order->refresh();
        });
    }
}
