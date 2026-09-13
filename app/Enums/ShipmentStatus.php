<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where one Shipment stands, in the system's own words rather than any
 * Courier's.
 *
 * Seven states for three couriers that between them speak three vocabularies
 * and agree on almost nothing (ADR-0018). Each Courier's raw word is
 * translated here on its driver and nowhere else, and kept beside the
 * canonical state on the row so a timeline can still show what the Courier
 * actually said.
 *
 * A state a given Courier never emits is expected, not a defect: Steadfast
 * never says In Transit and RedEx never says Unknown. That asymmetry is why
 * courier figures are always broken down by Courier and never blended.
 *
 * **Returned is not Cancelled.** Two of the three couriers say "cancelled"
 * for a parcel that came back, and it lands here as Returned, because
 * `OrderStatus::Cancelled` is a decision a User makes and never one a courier
 * in Dhaka gets to make for them.
 *
 * Its own axis, like `PaymentState`: nothing here ever writes `OrderStatus`
 * and `OrderStatus` never writes this.
 */
enum ShipmentStatus: string implements HasColor, HasLabel
{
    /**
     * Written down and not handed to anybody yet: where a bulk booking
     * leaves a parcel between the row being written and the sweep
     * dispatching it (ADR-0020).
     *
     * The one state here that no Courier ever reports and that no driver's
     * status table may translate a word into. It exists because a batch
     * writes every row before anything is sent — which is what makes a batch
     * interrupted halfway leave a record of each parcel it booked and a retry
     * create no duplicates — and a row waiting to go is emphatically not a
     * row a Courier has accepted.
     */
    case Written = 'written';

    case Booked = 'booked';
    case InTransit = 'in_transit';
    case OnHold = 'on_hold';
    case Delivered = 'delivered';
    case PartlyDelivered = 'partly_delivered';
    case Returned = 'returned';
    case Unknown = 'unknown';

    public function getLabel(): string
    {
        return match ($this) {
            self::Written => 'Awaiting dispatch',
            self::Booked => 'Booked',
            self::InTransit => 'In transit',
            self::OnHold => 'On hold',
            self::Delivered => 'Delivered',
            self::PartlyDelivered => 'Partly delivered',
            self::Returned => 'Returned',
            self::Unknown => 'Unknown',
        };
    }

    /**
     * Delivered is the outcome the Merchant is paid for; Returned is the one
     * that costs them and demands a decision. On Hold and Unknown are both
     * "somebody has to look at this", and the states where a parcel is simply
     * on its way — waiting to go among them — share the quiet colour.
     */
    public function getColor(): string
    {
        return match ($this) {
            self::Delivered => 'success',
            self::PartlyDelivered => 'info',
            self::Returned => 'danger',
            self::OnHold, self::Unknown => 'warning',
            self::Written, self::Booked, self::InTransit => 'gray',
        };
    }

    /**
     * Whether this is as far as the parcel goes: the Courier has finished
     * with it, whatever it decided.
     *
     * What separates an attempt still worth waiting on from one that is over
     * — half of `Shipment::isActive()`, and the half the Courier owns. The
     * other half is the Merchant's: a Shipment written off is Abandoned, and
     * that is a time and a name rather than a state here (ADR-0018).
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Delivered, self::PartlyDelivered, self::Returned => true,
            self::Written, self::Booked, self::InTransit, self::OnHold, self::Unknown => false,
        };
    }

    /**
     * The states an attempt is over in, by name, for a query that cannot ask
     * an object.
     *
     * @return list<string>
     */
    public static function terminalValues(): array
    {
        return self::valuesWhere(fn (self $status): bool => $status->isTerminal());
    }

    /**
     * Whether the parcel got to the door — all of it, or some of it.
     *
     * The half of terminal that settles an Order's delivery. Returned is over
     * too, and is the opposite: a parcel that came back is a question a User
     * has to answer, and the Order is one to send again (ADR-0018). So "is
     * this Order still to be sent" cannot be read off `isTerminal()`, which
     * says only that the Courier has stopped.
     */
    public function isArrival(): bool
    {
        return match ($this) {
            self::Delivered, self::PartlyDelivered => true,
            self::Written, self::Booked, self::InTransit, self::OnHold, self::Returned, self::Unknown => false,
        };
    }

    /**
     * The states a parcel got to the door in, by name, for a query that
     * cannot ask an object.
     *
     * @return list<string>
     */
    public static function arrivalValues(): array
    {
        return self::valuesWhere(fn (self $status): bool => $status->isArrival());
    }

    /**
     * @param  callable(self): bool  $wanted
     * @return list<string>
     */
    private static function valuesWhere(callable $wanted): array
    {
        return array_values(array_map(
            fn (self $status): string => $status->value,
            array_filter(self::cases(), $wanted),
        ));
    }
}
