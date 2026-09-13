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
 * A row is drawn for a Courier that can be used, and for one that still has
 * parcels out whether it can be used or not. What decides is the parcels and
 * never the Settings alone: a Courier switched off this morning with ten
 * parcels still with its riders is money the Merchant is owed, so it is drawn
 * with the reason there is no balance beside a figure that is entirely real.
 *
 * A Courier nobody has set up and that is carrying nothing is left out. It
 * holds nothing and can hold nothing, so a row for it says only that the
 * enum has three cases — and a Merchant who uses one Courier would read two
 * permanently empty rows for the life of the installation. The guarantee that
 * matters is the other way round, and this keeps it: a Courier holding money
 * is never absent, because an absent row reads as a Courier holding nothing.
 */
class CourierMoney
{
    public function __construct(private readonly CourierBalances $balances) {}

    /**
     * The two figures of every Courier that has either of them to answer for,
     * in the order the registry names them.
     *
     * Carrying is asked before usable, and that order is the point: a Courier
     * with a parcel out is drawn without its Settings deciding anything, so
     * switching one off can never take money off this table. A Courier absent
     * from `codInFlight()` has no Active Shipment at all — the grouped query
     * answers only for couriers that have them — which is a stronger question
     * than "is the figure zero", and the right one: a parcel out for an Order
     * already Paid online collects nothing at the door and is still a parcel
     * that Courier is carrying.
     *
     * @return list<CourierMoneyRow>
     */
    public function rows(): array
    {
        $inFlight = self::codInFlight();
        $rows = [];

        foreach (Courier::cases() as $courier) {
            if (! array_key_exists($courier->value, $inFlight) && ! $courier->isUsable()) {
                continue;
            }

            $rows[] = new CourierMoneyRow(
                courier: $courier,
                balance: $this->balances->of($courier),
                codInFlight: $inFlight[$courier->value] ?? 0,
            );
        }

        return $rows;
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
