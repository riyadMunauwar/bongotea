<?php

namespace App\Support\Orders;

use App\Enums\Permission;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Pack;
use App\Models\User;
use App\Support\Inventory\Grams;
use App\Support\Inventory\StockLedger;
use App\Support\Money;
use App\Support\Pricing\PackPrices;
use App\Support\Pricing\PricedPackLine;
use App\Support\Pricing\PricedRetailCart;
use App\Support\Pricing\RetailLine;
use App\Support\Pricing\RetailPriceBook;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Repricing for a retail Order (ADR-0034), beside the bulk `Repricing` and
 * never inside it: what happens when a User changes what an unsent retail
 * Order holds, and how a retail Order taken by phone is priced at all.
 *
 * The whole Order is priced again as if captured that moment — today's Pack
 * Prices, and the retail Delivery Charge and threshold read afresh — through
 * retail pricing. A line under a Price Override keeps its price, which never
 * exceeds its Pack's MRP. An Order with any Shipment is not changed at all.
 *
 * The lines arrive as the admin order form holds them for a retail Order:
 * rows of `{id, pack_id, count, override_price, override_reason}`, the
 * override price in whole taka.
 */
class RetailRepricing
{
    public function __construct(private readonly StockLedger $ledger) {}

    /**
     * A retail Order's lines as the form holds them.
     *
     * @return list<array{id: int, pack_id: int|null, count: int, override_price: string|null, override_reason: string|null}>
     */
    public static function stateOf(Order $order): array
    {
        return $order->lines()->orderBy('id')->get()->map(fn (OrderLine $line): array => [
            'id' => $line->getKey(),
            'pack_id' => $line->pack_id,
            'count' => $line->quantity,
            'override_price' => $line->isPriceOverridden() ? Money::toWholeMajorString($line->unit_price) : null,
            'override_reason' => $line->price_override_reason,
        ])->values()->all();
    }

    /**
     * Whether the rows ask for anything other than what the Order already
     * holds; only a change is repriced. A row sent without the override
     * fields compares as the stored line's, as in `Repricing::changes()`.
     *
     * @param  array<array-key, mixed>  $state
     */
    public static function changes(Order $order, array $state): bool
    {
        $stored = self::stateOf($order);
        $storedById = array_column($stored, null, 'id');

        $state = array_map(function (mixed $row) use ($storedById): mixed {
            if (! is_array($row) || array_key_exists('override_price', $row)) {
                return $row;
            }

            $kept = $storedById[$row['id'] ?? null] ?? null;

            return [...$row, 'override_price' => $kept['override_price'] ?? null, 'override_reason' => $kept['override_reason'] ?? null];
        }, $state);

        return self::fingerprint($state) !== self::fingerprint($stored);
    }

    /**
     * The rows priced at today's Pack Prices, before anything is saved, each
     * priced line under the key its row has in the form. Null while no row is
     * yet a line that can be priced, or one holds an override above its MRP.
     *
     * @param  array<array-key, mixed>  $state
     * @return array{cart: PricedRetailCart, lines: array<array-key, PricedPackLine>}|null
     */
    public function preview(array $state): ?array
    {
        $requested = self::requested($state);
        $packs = self::packs($requested);
        $requested = array_filter($requested, fn (RequestedPackLine $line): bool => $packs->has($line->packId));

        if ($requested === []) {
            return null;
        }

        try {
            $cart = self::price($requested, $packs);
        } catch (InvalidArgumentException) {
            return null;
        }

        return ['cart' => $cart, 'lines' => array_combine(array_keys($requested), $cart->lines)];
    }

    /**
     * The override a form row would be written with, where it is above its
     * Pack's MRP, or null where it is not — settled exactly as `apply()`
     * settles it.
     *
     * @param  array<string, mixed>  $row
     * @return array{override: int, mrp: int}|null
     */
    public static function overrideAboveMrp(?Order $order, array $row, User $by): ?array
    {
        $line = RequestedPackLine::fromState($row);

        if ($line === null) {
            return null;
        }

        $kept = $line->id === null || $order === null ? null : $order->lines()->find($line->id);

        try {
            $settled = self::withSettledOverride($line, $kept, $by->can(Permission::OverridePrices));
        } catch (InvalidArgumentException) {
            return null;
        }

        $pack = $settled->isOverridden() ? Pack::query()->find($settled->packId) : null;

        if ($pack === null || $settled->overridePrice <= $pack->mrp) {
            return null;
        }

        return ['override' => $settled->overridePrice, 'mrp' => $pack->mrp];
    }

    /**
     * Write the rows onto the retail Order, priced afresh, with the retail
     * Delivery Charge read from the new subtotal.
     *
     * @param  array<array-key, mixed>  $state
     *
     * @throws LogicException where the Order already has a Shipment, or is not retail
     * @throws InvalidArgumentException where the rows are not lines the Order can hold
     */
    public function apply(Order $order, array $state, User $by): void
    {
        if (! $order->isRetail()) {
            throw new LogicException("Order {$order->order_number} is bulk; it is repriced by its Tiers.");
        }

        if ($order->hasAnyShipment()) {
            throw new LogicException("Order {$order->order_number} has a Shipment; its lines are locked.");
        }

        $stored = $order->lines()->get()->keyBy(fn (OrderLine $line): int => (int) $line->getKey());
        $canOverride = $by->can(Permission::OverridePrices);

        $lines = array_values(array_map(
            fn (RequestedPackLine $line): RequestedPackLine => self::withSettledOverride($line, self::keptLine($line, $stored), $canOverride),
            self::requested($state),
        ));

        if ($lines === []) {
            throw new InvalidArgumentException('An Order holds at least one line.');
        }

        $packs = self::packs($lines);

        foreach ($lines as $line) {
            if (! $packs->has($line->packId)) {
                throw new InvalidArgumentException("Pack [{$line->packId}] does not exist.");
            }
        }

        $cart = self::price($lines, $packs);
        $costs = $this->ledger->averageCostFor(array_values(array_unique($packs->map(fn (Pack $pack): int => $pack->product_id)->all())));
        $wasTotal = $order->total;
        $wasPriced = $stored->isNotEmpty();

        DB::transaction(function () use ($order, $by, $lines, $stored, $packs, $cart, $costs, $wasTotal, $wasPriced): void {
            /** Asked again under the Order's row lock, which `CourierBooker` books under too. */
            if (Order::withTrashed()->lockForUpdate()->findOrFail($order->getKey())->hasAnyShipment()) {
                throw new LogicException("Order {$order->order_number} has a Shipment; its lines are locked.");
            }

            $keptIds = array_filter(array_map(fn (RequestedPackLine $line): ?int => self::keptLine($line, $stored)?->getKey(), $lines));

            $stored->except($keptIds)->each(fn (OrderLine $line): ?bool => $line->delete());

            foreach ($lines as $index => $line) {
                $this->write($order, $line, self::keptLine($line, $stored), $packs[$line->packId], $cart->lines[$index], $costs, $by);
            }

            $order->fill([
                'delivery_charge' => $cart->deliveryCharge,
                'delivery_label' => OrderCapture::RETAIL_DELIVERY_LABEL,
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
     * @param  array<array-key, int>  $costs  average costs per kilogram, by Product id
     */
    private function write(Order $order, RequestedPackLine $line, ?OrderLine $kept, Pack $pack, PricedPackLine $priced, array $costs, User $by): void
    {
        $samePack = $kept !== null && (int) $kept->pack_id === $line->packId;
        $overrideChanged = $line->isOverridden() && ! ($samePack
            && $kept->isPriceOverridden()
            && $kept->unit_price === $line->overridePrice
            && $kept->price_override_reason === $line->overrideReason);
        $overrideRemoved = ! $line->isOverridden() && $samePack && $kept->isPriceOverridden();
        $costPerKilogram = $costs[$pack->product_id] ?? null;

        $row = $kept ?? $order->lines()->make();

        $row->fill([
            'product_id' => $pack->product_id,
            'category_id' => $samePack ? $kept->category_id : $pack->product->category_id,
            'pack_id' => $pack->getKey(),
            'pack_grams' => $samePack ? $kept->pack_grams : $pack->grams,
            'product_name' => $samePack ? $kept->product_name : $pack->product->name,
            'unit_price' => $priced->packPrice,
            'mrp' => $priced->mrp,

            /** Stamped once, when the line first sells its Pack, and never moved after (ADR-0022). */
            'unit_cost' => $samePack ? $kept->unit_cost : ($costPerKilogram === null ? null : Grams::cost($pack->grams, $costPerKilogram)),

            'quantity' => $line->count,
            'tier_min' => null,
            'tier_max' => null,
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
                ->withProperties(['product' => $row->product_name, 'pack' => $pack->name(), 'price' => $line->overridePrice, 'reason' => $line->overrideReason])
                ->log("Set {$row->product_name} {$pack->name()} to ".Money::format($line->overridePrice)." a pack by hand: {$line->overrideReason}");
        }

        if ($overrideRemoved) {
            activity()
                ->performedOn($order)
                ->causedBy($by)
                ->event('price_override_removed')
                ->withProperties(['product' => $row->product_name, 'pack' => $pack->name()])
                ->log("Took the hand-set price off {$row->product_name} {$pack->name()}.");
        }
    }

    /**
     * @param  array<array-key, RequestedPackLine>  $lines
     * @param  Collection<int, Pack>  $packs
     */
    private static function price(array $lines, Collection $packs): PricedRetailCart
    {
        return RetailPriceBook::current()->price(array_values(array_map(
            fn (RequestedPackLine $line): RetailLine => new RetailLine(PackPrices::of($packs[$line->packId]), $line->count, $line->overridePrice),
            $lines,
        )));
    }

    /**
     * The override a line is written with; the same rule as bulk Repricing,
     * read for Packs: a deal agreed for one Pack is not a deal for another.
     */
    private static function withSettledOverride(RequestedPackLine $line, ?OrderLine $kept, bool $canOverride): RequestedPackLine
    {
        if (! $canOverride || ! $line->saysOverride) {
            $keeps = $kept !== null && (int) $kept->pack_id === $line->packId && $kept->isPriceOverridden();

            return $line->withOverride($keeps ? $kept->unit_price : null, $keeps ? $kept->price_override_reason : null);
        }

        $carriedOver = $kept !== null
            && (int) $kept->pack_id !== $line->packId
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
     * @param  Collection<int, OrderLine>  $stored
     */
    private static function keptLine(RequestedPackLine $line, Collection $stored): ?OrderLine
    {
        return $line->id === null ? null : $stored->get($line->id);
    }

    /**
     * Every Pack the rows name, with its Product, keyed by id. A Pack deleted
     * since cannot be priced at all.
     *
     * @param  array<array-key, RequestedPackLine>  $lines
     * @return Collection<int, Pack>
     */
    private static function packs(array $lines): Collection
    {
        return Pack::query()
            ->with('product')
            ->whereKey(array_map(fn (RequestedPackLine $line): int => $line->packId, $lines))
            ->get()
            ->keyBy(fn (Pack $pack): int => (int) $pack->getKey());
    }

    /**
     * @param  array<array-key, mixed>  $state
     * @return array<array-key, RequestedPackLine>
     */
    private static function requested(array $state): array
    {
        $lines = [];

        foreach ($state as $key => $row) {
            $line = is_array($row) ? RequestedPackLine::fromState($row) : null;

            if ($line !== null) {
                $lines[$key] = $line;
            }
        }

        return $lines;
    }

    /**
     * @param  array<array-key, mixed>  $state
     * @return list<array<int, mixed>>
     */
    private static function fingerprint(array $state): array
    {
        return array_values(array_map(function (mixed $row): array {
            $line = is_array($row) ? RequestedPackLine::fromState($row) : null;

            return $line === null
                ? ['incomplete']
                : [$line->id, $line->packId, $line->count, $line->overridePrice, $line->overridePrice === null ? null : $line->overrideReason];
        }, $state));
    }
}
