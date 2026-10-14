<?php

namespace App\Support\Orders;

use App\Enums\OrderKind;
use App\Enums\Permission;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Pack;
use App\Models\Product;
use App\Models\User;
use App\Support\Inventory\StockLedger;
use App\Support\Money;
use App\Support\Pricing\CartLine;
use App\Support\Pricing\PriceBook;
use App\Support\Pricing\PricedCart;
use App\Support\Pricing\PricedLine;
use App\Support\Pricing\ProductPrices;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Repricing: what happens when a User changes what an unsent Order holds, and
 * how an Order taken by phone is priced in the first place.
 *
 * The whole Order is priced again as if captured that moment — today's Tier
 * Prices for its new total Kilograms, and its Delivery Charge read afresh —
 * through the one pricing module the storefront answers to, so a phone buyer
 * and a web buyer pay the same. A line under a Price Override keeps its price
 * and still counts toward the total Kilograms. An Order with any Shipment is
 * not changed at all, because what the courier will collect has already been
 * handed to them.
 *
 * The lines arrive as the admin order form holds them: a list of rows of
 * `{id, product_id, kilograms, pack_id, override_price, override_reason}`,
 * the override price in whole taka.
 *
 * Each line is supplied in a পাইকারি Pack of its Product (ADR-0036), the
 * Product's default where the row names none. A line keeps the Pack and the
 * weight it recorded until a User chooses another; either way its Kilograms
 * must make a whole number of them, and a Pack its Product no longer offers
 * পাইকারি is refused even on a line that already holds it. The Pack never
 * prices the line.
 */
class Repricing
{
    public function __construct(private readonly StockLedger $ledger) {}

    /**
     * An Order's lines as the form holds them.
     *
     * @return list<array{id: int, product_id: int|null, kilograms: int, pack_id: int|null, override_price: string|null, override_reason: string|null}>
     */
    public static function stateOf(Order $order): array
    {
        return $order->lines()->orderBy('id')->get()->map(fn (OrderLine $line): array => [
            'id' => $line->getKey(),
            'product_id' => $line->product_id,
            'kilograms' => $line->quantity,
            'pack_id' => $line->pack_id,
            'override_price' => $line->isPriceOverridden() ? Money::toWholeMajorString($line->unit_price) : null,
            'override_reason' => $line->price_override_reason,
        ])->values()->all();
    }

    /**
     * Whether the rows ask for anything other than what the Order already
     * holds. Only a change is repriced: an Order whose lines are untouched
     * keeps the prices agreed at Capture, whatever a Product costs today.
     *
     * @param  array<array-key, mixed>  $state
     */
    public static function changes(Order $order, array $state): bool
    {
        $stored = self::stateOf($order);
        $storedById = array_column($stored, null, 'id');

        /**
         * A row sent without the override fields — a User who may not see
         * them — says nothing about the override, so it compares as the
         * stored line's. Otherwise a details-only save on an Order holding an
         * override would reprice every other line unasked.
         */
        $state = array_map(function (mixed $row) use ($storedById): mixed {
            if (! is_array($row) || array_key_exists('override_price', $row)) {
                return $row;
            }

            $kept = $storedById[$row['id'] ?? null] ?? null;

            return [
                ...$row,
                'override_price' => $kept['override_price'] ?? null,
                'override_reason' => $kept['override_reason'] ?? null,
            ];
        }, $state);

        return self::fingerprint($state) !== self::fingerprint($stored);
    }

    /**
     * The rows priced at today's prices, before anything is saved: the whole
     * Cart, and each priced line under the key its row has in the form. Null
     * while no row is yet a line that can be priced.
     *
     * @param  array<array-key, mixed>  $state
     * @return array{cart: PricedCart, lines: array<array-key, PricedLine>}|null
     */
    public function preview(array $state): ?array
    {
        $requested = self::requested($state);
        $products = self::products($requested);
        $requested = array_filter($requested, fn (RequestedLine $line): bool => $products->get($line->productId)?->isSoldAs(OrderKind::Bulk) ?? false);

        if ($requested === []) {
            return null;
        }

        /**
         * A row may hold a price set by hand above its tea's MRP — typed a
         * moment ago, or carried onto another tea in the form — which the
         * pricing module refuses. Nothing is shown for it until the save,
         * which says why.
         */
        try {
            $cart = $this->price($requested, $products);
        } catch (InvalidArgumentException) {
            return null;
        }

        return ['cart' => $cart, 'lines' => array_combine(array_keys($requested), $cart->lines)];
    }

    /**
     * The override a form row would be written with, where it is above its
     * tea's MRP — so the form can refuse it on the row before the pricing
     * module does — or null where it is not. Settled exactly as `apply()`
     * settles it, so an override a User without the Permission cannot see,
     * kept from before the MRP dropped, is caught too.
     *
     * @param  array<string, mixed>  $row
     * @return array{override: int, mrp: int}|null
     */
    public static function overrideAboveMrp(?Order $order, array $row, User $by): ?array
    {
        $line = RequestedLine::fromState($row);

        if ($line === null) {
            return null;
        }

        $kept = $line->id === null || $order === null ? null : $order->lines()->find($line->id);

        try {
            $settled = self::withSettledOverride($line, $kept, $by->can(Permission::OverridePrices));
        } catch (InvalidArgumentException) {
            return null;
        }

        $product = $settled->isOverridden() ? Product::withTrashed()->find($settled->productId) : null;

        if ($product === null || ! $product->isSoldAs(OrderKind::Bulk) || $settled->overridePrice <= $product->mrp) {
            return null;
        }

        return ['override' => $settled->overridePrice, 'mrp' => $product->mrp];
    }

    /**
     * Why a form row cannot be supplied in the Pack it names, or null where
     * it can or the row is not yet a line — so the form can refuse it on the
     * Pack before `apply()` does. The same check `apply()` makes.
     *
     * @param  array<string, mixed>  $row
     */
    public static function packProblem(?Order $order, array $row): ?string
    {
        $row = self::formRow($order, $row);

        return $row === null ? null : self::packRefusal($row['product'], $row['pack'], $row['kept']);
    }

    /**
     * Why a form row's Kilograms cannot be made in its Pack, or null where
     * they can, the Pack is itself refused or the row is not yet a line — so
     * the form can refuse it on the Kilograms, naming weights that work. Read
     * against the weight the line would record: its own where it keeps its
     * Pack, the Pack's today otherwise.
     *
     * @param  array<string, mixed>  $row
     */
    public static function packingProblem(?Order $order, array $row): ?string
    {
        $row = self::formRow($order, $row);

        if ($row === null || $row['pack'] === null) {
            return null;
        }

        return PackingRefused::of($row['product'], $row['line']->kilograms, self::recordedGrams($row['pack'], $row['kept']))?->getMessage();
    }

    /**
     * A form row read as the line it would be: its Product, the Pack it would
     * be supplied in and the stored line it was filled from. Null while it is
     * not yet a line, or names no Product.
     *
     * @param  array<string, mixed>  $row
     * @return array{line: RequestedLine, product: Product, pack: Pack|null, kept: OrderLine|null}|null
     */
    private static function formRow(?Order $order, array $row): ?array
    {
        $line = RequestedLine::fromState($row);
        $product = $line === null ? null : Product::withTrashed()->find($line->productId);

        if ($line === null || $product === null) {
            return null;
        }

        return [
            'line' => $line,
            'product' => $product,
            'pack' => $line->packId === null ? $product->defaultBulkPack : Pack::query()->find($line->packId),
            'kept' => $line->id === null || $order === null ? null : $order->lines()->find($line->id),
        ];
    }

    /**
     * Write the rows onto the Order, priced afresh, with its Delivery Charge
     * and band read from the new total Kilograms.
     *
     * A User without the Permission to override never changes an override:
     * whatever their rows say, each line keeps the override it already had.
     * A changed override is stamped with who gave it and put on the Order's
     * trail with its reason.
     *
     * @param  array<array-key, mixed>  $state
     *
     * @throws LogicException where the Order already has a Shipment
     * @throws InvalidArgumentException where the rows are not lines an Order can hold
     * @throws PackingRefused where a line's Kilograms are not a whole number of its Pack
     */
    public function apply(Order $order, array $state, User $by): void
    {
        if ($order->hasAnyShipment()) {
            throw new LogicException("Order {$order->order_number} has a Shipment; its lines are locked.");
        }

        $stored = $order->lines()->get()->keyBy(fn (OrderLine $line): int => (int) $line->getKey());
        $canOverride = $by->can(Permission::OverridePrices);

        $lines = array_values(array_map(
            fn (RequestedLine $line): RequestedLine => self::withSettledOverride($line, self::keptLine($line, $stored), $canOverride),
            self::requested($state),
        ));

        if ($lines === []) {
            throw new InvalidArgumentException('An Order holds at least one line.');
        }

        $products = self::products($lines);

        foreach ($lines as $line) {
            if (! $products->has($line->productId)) {
                throw new InvalidArgumentException("Product [{$line->productId}] does not exist.");
            }

            /** Even a line the Order already holds: a Product no longer sold পাইকারি has no Tiers to price it by (ADR-0036). */
            if (! $products[$line->productId]->isSoldAs(OrderKind::Bulk)) {
                throw new InvalidArgumentException("Product [{$line->productId}] is not sold পাইকারি.");
            }
        }

        $packs = self::packs($lines, $products, $stored);
        $cart = $this->price($lines, $products);
        $costs = $this->ledger->averageCostFor(array_values(array_unique(array_map(fn (RequestedLine $line): int => $line->productId, $lines))));
        $wasTotal = $order->total;
        $wasPriced = $stored->isNotEmpty();

        DB::transaction(function () use ($order, $by, $lines, $stored, $products, $packs, $cart, $costs, $wasTotal, $wasPriced): void {
            /**
             * Asked again under the Order's row lock, which `CourierBooker`
             * books under too, so a parcel booked a moment ago is never
             * repriced underneath.
             */
            if (Order::withTrashed()->lockForUpdate()->findOrFail($order->getKey())->hasAnyShipment()) {
                throw new LogicException("Order {$order->order_number} has a Shipment; its lines are locked.");
            }

            $keptIds = array_filter(array_map(fn (RequestedLine $line): ?int => self::keptLine($line, $stored)?->getKey(), $lines));

            $stored->except($keptIds)->each(fn (OrderLine $line): ?bool => $line->delete());

            foreach ($lines as $index => $line) {
                $this->write($order, $line, self::keptLine($line, $stored), $products[$line->productId], $packs[$index], $cart->lines[$index], $costs, $by);
            }

            $order->fill([
                'delivery_charge' => $cart->deliveryCharge,
                'delivery_label' => $cart->deliveryBand?->label(),
            ])->save();

            if ($wasPriced && $wasTotal !== $order->total) {
                activity()
                    ->performedOn($order)
                    ->causedBy($by)
                    ->event('repriced')
                    ->withProperties(['was' => $wasTotal, 'now' => $order->total])
                    ->log('Repriced: was '.Money::format($wasTotal).' → now '.Money::format($order->total).'.');
            }
        });
    }

    /**
     * @param  array<array-key, int>  $costs
     */
    private function write(Order $order, RequestedLine $line, ?OrderLine $kept, Product $product, Pack $pack, PricedLine $priced, array $costs, User $by): void
    {
        $sameProduct = $kept !== null && (int) $kept->product_id === $line->productId;
        $overrideChanged = $line->isOverridden() && ! ($sameProduct
            && $kept->isPriceOverridden()
            && $kept->unit_price === $line->overridePrice
            && $kept->price_override_reason === $line->overrideReason);
        $overrideRemoved = ! $line->isOverridden() && $sameProduct && $kept->isPriceOverridden();

        $row = $kept ?? $order->lines()->make();

        $row->fill([
            'product_id' => $line->productId,
            'category_id' => $sameProduct ? $kept->category_id : $product->category_id,
            'product_name' => $sameProduct ? $kept->product_name : $product->name,
            'pack_id' => $pack->getKey(),
            'pack_grams' => self::recordedGrams($pack, $kept),
            'unit_price' => $priced->tierPrice,
            'mrp' => $priced->mrp,

            /**
             * What the units cost is stamped once, when the line first sells
             * its Product, and never moves after (ADR-0022).
             */
            'unit_cost' => $sameProduct ? $kept->unit_cost : ($costs[$line->productId] ?? null),

            'quantity' => $line->kilograms,
            'tier_min' => $priced->tier?->min,
            'tier_max' => $priced->tier?->max,
            'price_override_reason' => $line->overrideReason,
            'price_overridden_by' => match (true) {
                ! $line->isOverridden() => null,
                $overrideChanged => $by->getKey(),
                default => $kept->price_overridden_by,
            },
        ])->save();

        if ($overrideChanged) {
            activity()
                ->performedOn($order)
                ->causedBy($by)
                ->event('price_overridden')
                ->withProperties(['product' => $row->product_name, 'price' => $line->overridePrice, 'reason' => $line->overrideReason])
                ->log("Set {$row->product_name} to ".Money::format($line->overridePrice)." per kg by hand: {$line->overrideReason}");
        }

        if ($overrideRemoved) {
            activity()
                ->performedOn($order)
                ->causedBy($by)
                ->event('price_override_removed')
                ->withProperties(['product' => $row->product_name])
                ->log("Took the hand-set price off {$row->product_name}.");
        }
    }

    /**
     * @param  array<array-key, RequestedLine>  $lines
     * @param  Collection<int, Product>  $products
     */
    private function price(array $lines, Collection $products): PricedCart
    {
        return PriceBook::current()->price(array_values(array_map(
            fn (RequestedLine $line): CartLine => new CartLine(ProductPrices::of($products[$line->productId]), $line->kilograms, $line->overridePrice),
            $lines,
        )));
    }

    /**
     * The override a line is written with. Only a User who may override, and
     * whose row said something about it, changes one; an override with no
     * reason is never written, because a price nobody can explain is a margin
     * nobody can trust.
     */
    private static function withSettledOverride(RequestedLine $line, ?OrderLine $kept, bool $canOverride): RequestedLine
    {
        if (! $canOverride || ! $line->saysOverride) {
            $keeps = $kept !== null && (int) $kept->product_id === $line->productId && $kept->isPriceOverridden();

            return $line->withOverride($keeps ? $kept->unit_price : null, $keeps ? $kept->price_override_reason : null);
        }

        /**
         * A row switched to another Product still carries the old line's
         * override in the form; a deal agreed for one tea is not a deal for
         * another, so it is dropped unless the User typed a new one.
         */
        $carriedOver = $kept !== null
            && (int) $kept->product_id !== $line->productId
            && $kept->isPriceOverridden()
            && $kept->unit_price === $line->overridePrice
            && $kept->price_override_reason === $line->overrideReason;

        if ($carriedOver) {
            return $line->withOverride(null, null);
        }

        if ($line->isOverridden() && $line->overrideReason === null) {
            throw new InvalidArgumentException('A Price Override needs a reason.');
        }

        return $line->withOverride($line->overridePrice, $line->overrideReason);
    }

    /**
     * The Pack each line is supplied in, under the line's key: the one its row
     * names, else its Product's default পাইকারি Pack. Refused where there is
     * none, where it is another Product's or no longer offered পাইকারি — even
     * on a line already holding it — where a Pack newly put on the line is
     * switched off, and where the line's Kilograms are not a whole number of
     * the weight it would record.
     *
     * @param  list<RequestedLine>  $lines
     * @param  Collection<int, Product>  $products
     * @param  Collection<int, OrderLine>  $stored
     * @return list<Pack>
     *
     * @throws InvalidArgumentException
     * @throws PackingRefused
     */
    private static function packs(array $lines, Collection $products, Collection $stored): array
    {
        $named = Pack::query()
            ->with('product')
            ->whereKey(array_filter(array_map(fn (RequestedLine $line): ?int => $line->packId, $lines)))
            ->get()
            ->keyBy(fn (Pack $pack): int => (int) $pack->getKey());

        return array_map(function (RequestedLine $line) use ($products, $stored, $named): Pack {
            $product = $products[$line->productId];
            $pack = $line->packId === null ? $product->defaultBulkPack : $named->get($line->packId);
            $kept = self::keptLine($line, $stored);
            $refusal = self::packRefusal($product, $pack, $kept);

            if ($refusal !== null) {
                throw new InvalidArgumentException($refusal);
            }

            /** @var Pack $pack */
            PackingRefused::unlessPackable($product, $line->kilograms, self::recordedGrams($pack, $kept));

            return $pack;
        }, $lines);
    }

    /**
     * Why a line of this Product cannot be supplied in this Pack, or null
     * where it can: there is none, it is another Product's or no longer
     * offered পাইকারি — even to a line already holding it — or it is newly
     * put on the line while switched off.
     */
    private static function packRefusal(Product $product, ?Pack $pack, ?OrderLine $kept): ?string
    {
        return match (true) {
            $pack === null => "{$product->name} has no পাইকারি Pack to be sent in.",
            (int) $pack->product_id !== $product->getKey() || ! $pack->isSoldAs(OrderKind::Bulk) => "{$product->name} is no longer sent in {$pack->name()} Packs পাইকারি.",
            ! self::keepsPack($pack, $kept) && ! $pack->is_available => "{$product->name}'s {$pack->name()} Packs are switched off.",
            default => null,
        };
    }

    /** Whether a line stays in the Pack it already recorded. */
    private static function keepsPack(Pack $pack, ?OrderLine $kept): bool
    {
        return $kept !== null && (int) $kept->pack_id === $pack->getKey() && $kept->pack_grams !== null;
    }

    /** The Pack weight a line records: its own while it keeps its Pack, so an edited Pack moves no Order; the Pack's today otherwise. */
    private static function recordedGrams(Pack $pack, ?OrderLine $kept): int
    {
        return self::keepsPack($pack, $kept) ? $kept->pack_grams : $pack->grams;
    }

    /**
     * The stored line a row was filled from. A row naming a line that is not
     * this Order's is a line being added.
     *
     * @param  Collection<int, OrderLine>  $stored
     */
    private static function keptLine(RequestedLine $line, Collection $stored): ?OrderLine
    {
        return $line->id === null ? null : $stored->get($line->id);
    }

    /**
     * Every Product the rows name, keyed by id. A Product taken out of the
     * catalogue still has its Tiers, so a line already holding it can be
     * repriced; one deleted outright cannot be priced at all.
     *
     * @param  array<array-key, RequestedLine>  $lines
     * @return Collection<int, Product>
     */
    private static function products(array $lines): Collection
    {
        return Product::withTrashed()
            ->whereKey(array_map(fn (RequestedLine $line): int => $line->productId, $lines))
            ->get()
            ->keyBy(fn (Product $product): int => (int) $product->getKey());
    }

    /**
     * @param  array<array-key, mixed>  $state
     * @return array<array-key, RequestedLine>
     */
    private static function requested(array $state): array
    {
        $lines = [];

        foreach ($state as $key => $row) {
            $line = is_array($row) ? RequestedLine::fromState($row) : null;

            if ($line !== null) {
                $lines[$key] = $line;
            }
        }

        return $lines;
    }

    /**
     * The rows reduced to what changes a price, so an untouched form compares
     * equal however its values were typed. An incomplete row is a change.
     *
     * @param  array<array-key, mixed>  $state
     * @return list<array<int, mixed>>
     */
    private static function fingerprint(array $state): array
    {
        return array_values(array_map(function (mixed $row): array {
            $line = is_array($row) ? RequestedLine::fromState($row) : null;

            return $line === null
                ? ['incomplete']
                : [$line->id, $line->productId, $line->kilograms, $line->packId, $line->overridePrice, $line->overridePrice === null ? null : $line->overrideReason];
        }, $state));
    }
}
