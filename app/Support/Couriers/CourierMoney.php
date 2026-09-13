<?php

namespace App\Support\Couriers;

use App\Enums\Courier;
use App\Models\Shipment;

/**
 * How much of the Merchant's money the couriers are between them holding,
 * one row per Courier, as two figures that are never one.
 *
 * **Balance** is what a Courier says about its own account, and only one of
 * the three says anything at all. **COD in Flight** is counted here, from the
 * Merchant's own Orders — what every Active Shipment was sent out to collect
 * — so every Courier has that figure alike whatever it does or does not
 * publish.
 *
 * Keeping them apart is the whole point. A Courier's balance is money it has
 * collected and not yet paid over; COD in Flight is money nobody has
 * collected yet. Added together they would double-count a parcel delivered this morning and
 * be a figure a Merchant could not check against anything. Shown side by side
 * they are what a Merchant chases a Courier with.
 *
 * One row for every Courier, including one nobody has set up: a Courier
 * missing from the table reads as a Courier holding nothing, and the reason
 * there is no figure — switched off, no credentials, unreachable — is the
 * thing worth saying.
 */
class CourierMoney
{
    public function __construct(private readonly CourierBalances $balances) {}

    /**
     * Every Courier's two figures, in the order the registry names them.
     *
     * @return list<CourierMoneyRow>
     */
    public function rows(): array
    {
        $inFlight = self::codInFlight();

        return array_map(
            fn (Courier $courier): CourierMoneyRow => new CourierMoneyRow(
                courier: $courier,
                balance: $this->balances->of($courier),
                codInFlight: $inFlight[$courier->value] ?? 0,
            ),
            Courier::cases(),
        );
    }

    /**
     * What each Courier's Active Shipments were sent out to collect, keyed by
     * the name the Courier is stored under.
     *
     * `collect_amount` and not the Order's total: it is what the Courier was
     * actually told to collect at the door, which is nothing at all for an
     * Order already Paid online (ADR-0018). Reading the Order instead would
     * put money at a door that nobody is going to collect there.
     *
     * Active is `Shipment::active()` and nothing else — a parcel delivered,
     * returned, refused at booking or abandoned is money no Courier is still
     * carrying. A Courier with no parcels out is absent from this and reads
     * as zero, which for this figure is the truth rather than a stand-in.
     *
     * Deliberately including a batch written and not yet dispatched: a
     * Shipment is Active from the moment it is written, and a morning's
     * Orders are money the Merchant is owed whether the sweep has offered
     * them to the Courier yet or not. Do not narrow this to the dispatched
     * rows — Active is one reading, and a second one here would be a figure
     * that disagrees with the Orders list.
     *
     * One grouped query for every Courier at once, because this is drawn on
     * the Dashboard beside eight other widgets.
     *
     * @return array<string, int>
     */
    private static function codInFlight(): array
    {
        return Shipment::query()
            ->active()
            ->groupBy('courier')
            ->selectRaw('courier, SUM(collect_amount) as cod_in_flight')
            ->pluck('cod_in_flight', 'courier')
            ->map(fn (mixed $amount): int => (int) $amount)
            ->all();
    }
}
