<?php

namespace App\Support\Orders;

use App\Enums\OrderKind;
use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Pack;
use App\Models\Product;
use App\Support\Inventory\Grams;
use App\Support\Inventory\StockLedger;
use App\Support\Pricing\CartLine;
use App\Support\Pricing\PackPrices;
use App\Support\Pricing\PriceBook;
use App\Support\Pricing\PricedCart;
use App\Support\Pricing\PricedLine;
use App\Support\Pricing\PricedPackLine;
use App\Support\Pricing\PricedRetailCart;
use App\Support\Pricing\ProductPrices;
use App\Support\Pricing\RetailLine;
use App\Support\Pricing\RetailPriceBook;
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
    /** What a retail Order's Delivery Charge is called, in place of a Delivery Band's range. */
    public const RETAIL_DELIVERY_LABEL = 'Retail delivery';

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
            $order = $this->createOrder($customer, $details, OrderKind::Bulk, $priced->deliveryCharge, $priced->deliveryBand?->label());

            foreach ($lines as $index => $line) {
                $order->lines()->create($this->line($line['product'], $priced->lines[$index], $costs));
            }

            /** The lines' observer recomputed the totals; load them back. */
            return $order->refresh();
        });
    }

    /**
     * Capture of the packet basket: an Order marked retail (ADR-0034), each
     * line one Pack's worth at its Pack Price, paying the flat retail Delivery
     * Charge — waived at the threshold — and nothing from the Tiers or the
     * Delivery Bands. The same refusals as bulk, read for Packs: a Pack
     * removed, switched off, named under another Product, or whose Product can
     * no longer be had refuses with the basket priced as it is now, and so
     * does a Quote that disagrees. Stock is not touched (ADR-0021).
     *
     * @param  list<array{packId: int, productId: int, pack: Pack|null, count: int}>  $lines  the packet basket, one line per Pack; a Pack since deleted is null
     * @param  array{name: string|null, phone: string, email?: string|null, address: string|null, district: string|null, shop_name?: string|null, note?: string|null, payment_method: PaymentMethod}  $details
     *
     * @throws RetailQuoteRefused where a Pack cannot be ordered now, or the Quote disagrees with the store
     * @throws CheckoutUnavailable where the basket or the details cannot become an Order
     */
    public function captureRetail(array $lines, array $details, ?RetailQuote $quote = null): Order
    {
        if (blank($details['district'] ?? null)) {
            throw new CheckoutUnavailable('An Order was captured with no District.');
        }

        $this->refuseMalformedRetail($lines);

        $priced = $this->priceRetail($lines, $quote);

        /** @var list<array{packId: int, productId: int, pack: Pack, count: int}> $lines */
        $costs = $this->ledger->averageCostFor(array_values(array_unique(array_map(fn (array $line): int => $line['pack']->product_id, $lines))));

        $customer = Customer::forPhone($details['phone']);

        return DB::transaction(function () use ($customer, $details, $lines, $priced, $costs): Order {
            $order = $this->createOrder($customer, $details, OrderKind::Retail, $priced->deliveryCharge, self::RETAIL_DELIVERY_LABEL);

            foreach ($lines as $index => $line) {
                $order->lines()->create($this->packLine($line['pack'], $priced->lines[$index], $costs));
            }

            return $order->refresh();
        });
    }

    /**
     * The Order row itself, before its lines, the same for either kind.
     *
     * @param  array<string, mixed>  $details
     */
    private function createOrder(Customer $customer, array $details, OrderKind $kind, int $deliveryCharge, ?string $deliveryLabel): Order
    {
        return $customer->orders()->create([
            'kind' => $kind,
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
            'delivery_charge' => $deliveryCharge,
            'delivery_label' => $deliveryLabel,

            /**
             * How the Customer said they would pay, by name. Whether money
             * then moved is its own axis, answered by the Order's Payments
             * and never by this column or by `status` (ADR-0013).
             */
            'payment_method' => $details['payment_method'],
        ]);
    }

    /**
     * The packet basket priced by the store, or a refusal: a Pack a buyer
     * cannot order now refuses before any price is compared, and the Quote,
     * where there is one, must agree with every figure.
     *
     * @param  list<array{packId: int, productId: int, pack: Pack|null, count: int}>  $lines
     */
    private function priceRetail(array $lines, ?RetailQuote $quote): PricedRetailCart
    {
        $orderable = [];
        $refused = [];

        foreach ($lines as $line) {
            $pack = $line['pack'];

            if ($pack !== null && $pack->product_id === $line['productId'] && $pack->isOrderable()) {
                $orderable[] = $line;
            } else {
                $refused[] = $line['packId'];
            }
        }

        if ($refused !== []) {
            throw new RetailQuoteRefused("Pack [{$refused[0]}] cannot be ordered now.", self::priceRetailOf($orderable), $refused);
        }

        $priced = self::priceRetailOf($lines);

        if ($quote !== null && ! $quote->agreesWith($priced)) {
            throw new RetailQuoteRefused('The Quote no longer agrees with what the store charges.', $priced);
        }

        return $priced;
    }

    /**
     * @param  list<array{pack: Pack|null, count: int}>  $lines  every Pack present
     */
    private static function priceRetailOf(array $lines): PricedRetailCart
    {
        return RetailPriceBook::current()->price(array_map(
            fn (array $line): RetailLine => new RetailLine(PackPrices::of($line['pack']), $line['count']),
            $lines,
        ));
    }

    /**
     * @param  list<array{packId: int, count: int}>  $lines
     *
     * @throws CheckoutUnavailable
     */
    private function refuseMalformedRetail(array $lines): void
    {
        if ($lines === []) {
            throw new CheckoutUnavailable('An Order was captured with an empty packet basket.');
        }

        $seen = [];

        foreach ($lines as $line) {
            if ($line['count'] < 1) {
                throw new CheckoutUnavailable("Pack [{$line['packId']}] was captured with no count.");
            }

            if (isset($seen[$line['packId']])) {
                throw new CheckoutUnavailable("Pack [{$line['packId']}] appears twice in one packet basket.");
            }

            $seen[$line['packId']] = true;
        }
    }

    /**
     * One retail Order Line, snapshotted: the Pack's Product and Category, the
     * Pack's weight, its Pack Price and MRP, and what one Pack's weight cost at
     * the Product's average this instant — the average is per kilogram, so a
     * Pack costs its grams' share of it (ADR-0035). No Tier.
     *
     * @param  array<int, int>  $costs  average costs per kilogram, by Product id
     * @return array<string, mixed>
     */
    private function packLine(Pack $pack, PricedPackLine $priced, array $costs): array
    {
        $product = $pack->product;
        $costPerKilogram = $costs[$product->getKey()] ?? null;

        return [
            'product_id' => $product->getKey(),
            'category_id' => $product->category_id,
            'pack_id' => $pack->getKey(),
            'pack_grams' => $pack->grams,
            'product_name' => $product->name,
            'unit_price' => $priced->packPrice,
            'mrp' => $priced->mrp,
            'unit_cost' => $costPerKilogram === null ? null : Grams::cost($pack->grams, $costPerKilogram),
            'quantity' => $priced->count,
            'tier_min' => null,
            'tier_max' => null,
        ];
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
     * @return array{product_id: int, category_id: int, product_name: string, unit_price: int, mrp: int, unit_cost: int|null, quantity: int, tier_min: int|null, tier_max: int|null}
     */
    private function line(Product $product, PricedLine $priced, array $costs): array
    {
        return [
            'product_id' => $product->getKey(),

            /** The Category it is sold under, fixed here so moving the Product later never moves this sale. */
            'category_id' => $product->category_id,

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
