<?php

namespace App\Support\Orders;

use App\Enums\Permission;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\User;
use App\Support\Inventory\CommittedStock;
use App\Support\Inventory\Grams;
use Illuminate\Support\Facades\DB;

/**
 * Free to Sell as a User meets it (ADR-0037): the check Capture holds a
 * Customer to, made of an Order taken by phone or repriced, bulk and retail
 * alike.
 *
 * The Order's own weight is left out of its own check, so an edit is asked
 * only for what it adds, and taking weight off is never refused. A Cancelled
 * or deleted Order holds nothing back from anyone and is not checked at all.
 *
 * There is one way past it: a User holding `Permission::SellBeyondStock`
 * gives a reason, and the reason and the User are kept on the Order. No such
 * way past an Order Limit exists, which is why that check is not here.
 */
class StockCheck
{
    public function __construct(private readonly CommittedStock $stock) {}

    /**
     * Why each Product would take the Order past Free to Sell, by Product id —
     * "Assam: only 4 kg is free to sell to this Order, and it would hold 6 kg."
     * — empty where none would. A null Order is one not yet taken.
     *
     * Whether to check at all follows the Order as given, which may carry a
     * status not yet saved; what it already holds follows the Order as stored,
     * since that is what Free to Sell has counted. A Cancelled Order reopened
     * holds nothing yet, so it is asked for its whole weight.
     *
     * @param  array<int, int>  $wanted  grams the Order would hold, by Product id
     * @return array<int, string>
     */
    public function refusals(?Order $order, array $wanted): array
    {
        if ($wanted === [] || ($order !== null && ! $order->holdsStock())) {
            return [];
        }

        $counted = $order !== null && $order->exists && (Order::withTrashed()->find($order->getKey())?->holdsStock() ?? false);
        $beyond = $this->stock->beyondFreeToSell($wanted, $counted ? self::heldBy($order) : []);

        if ($beyond === []) {
            return [];
        }

        $names = Product::withTrashed()->whereKey(array_keys($beyond))->pluck('name', 'id');

        return array_map(
            fn (int $productId): string => sprintf('%s: only %s is free to sell to this Order, and it would hold %s.', $names[$productId], Grams::format($beyond[$productId]), Grams::format($wanted[$productId])),
            array_combine(array_keys($beyond), array_keys($beyond)),
        );
    }

    /**
     * Hold the Order to Free to Sell, or let it past for a User who may and
     * has said why — the reason and the User then put on the Order, for its
     * caller to save, and on its trail. Called inside the transaction that
     * writes the lines, before it writes them, so the Products are locked
     * while the figure is read.
     *
     * @param  array<int, int>  $wanted  grams the Order would hold, by Product id
     *
     * @throws BeyondFreeToSell where it would go past and the User may not, or gave no reason
     */
    public function enforce(Order $order, array $wanted, User $by, ?string $reason): void
    {
        $refusals = $this->refusals($order, $wanted);

        if ($refusals === []) {
            return;
        }

        $refusal = reset($refusals);

        if (! $by->can(Permission::SellBeyondStock)) {
            throw new BeyondFreeToSell($refusal);
        }

        if (blank($reason)) {
            throw new BeyondFreeToSell("{$refusal} Selling beyond stock needs a reason.");
        }

        $reason = trim($reason);

        $order->forceFill([
            'sold_beyond_stock_reason' => $reason,
            'sold_beyond_stock_by' => $by->getKey(),
        ]);

        activity()
            ->performedOn($order)
            ->causedBy($by)
            ->event('sold_beyond_stock')
            ->withProperties(['refusals' => array_values($refusals), 'reason' => $reason])
            ->log("Sold beyond stock: {$reason}");
    }

    /**
     * Each Product's whole weight across an Order's lines, by Product id —
     * every line and Pack of it added up, since they draw on one pool.
     *
     * @param  list<array{0: Product, 1: int}>  $weighed  each line's Product and grams
     * @return array<int, int>
     */
    public static function wanted(array $weighed): array
    {
        $wanted = [];

        foreach ($weighed as [$product, $grams]) {
            $wanted[$product->getKey()] = ($wanted[$product->getKey()] ?? 0) + $grams;
        }

        return $wanted;
    }

    /**
     * What the Order holds now, in grams by Product id, read where the ledger
     * reads a line's weight.
     *
     * @return array<int, int>
     */
    private static function heldBy(Order $order): array
    {
        return $order->lines()
            ->toBase()
            ->select('product_id')
            ->selectRaw('sum('.OrderLine::grams()->getValue(DB::connection()->getQueryGrammar()).') as grams')
            ->groupBy('product_id')
            ->pluck('grams', 'product_id')
            ->map(fn (mixed $grams): int => (int) $grams)
            ->all();
    }
}
