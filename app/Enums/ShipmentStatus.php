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
     * "somebody has to look at this", and the two states where a parcel is
     * simply moving share the quiet colour.
     */
    public function getColor(): string
    {
        return match ($this) {
            self::Delivered => 'success',
            self::PartlyDelivered => 'info',
            self::Returned => 'danger',
            self::OnHold, self::Unknown => 'warning',
            self::Booked, self::InTransit => 'gray',
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
            self::Booked, self::InTransit, self::OnHold, self::Unknown => false,
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
        return array_values(array_map(
            fn (self $status): string => $status->value,
            array_filter(self::cases(), fn (self $status): bool => $status->isTerminal()),
        ));
    }
}
