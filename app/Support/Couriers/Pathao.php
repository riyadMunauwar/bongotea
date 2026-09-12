<?php

namespace App\Support\Couriers;

use App\Enums\Courier;
use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Support\Settings\CourierSettings;

/**
 * Pathao: an OAuth password grant issuing an access token that expires, a
 * store a parcel leaves from, many parcels to a booking, and both a status
 * and a message announced back.
 *
 * The credentials here are the four the token grant takes. The access token
 * itself is not among them and never becomes a Setting: it is derived state
 * a machine issues and ages out, so it is cached by the driver that obtained
 * it and refreshed before it expires, rather than being something a Merchant
 * is expected to paste again every few hours.
 *
 * Pathao populates city, zone and area from the address it is given, so
 * nothing here resolves an area. The price plan that would have quoted a
 * charge up front is given up with them: it takes a city and a zone as
 * mandatory integers, and asking a Customer for either is a question the
 * Checkout will not grow for a courier's sake.
 */
class Pathao implements CourierDriver
{
    /**
     * Parcels one booking call may carry.
     *
     * Pathao documents a bulk endpoint and no ceiling for it, so this is a
     * deliberate conservative cut rather than a limit anybody published: a
     * refused oversized batch would cost a whole sweep's parcels, and the
     * sweep is the retry either way.
     */
    private const BATCH_LIMIT = 100;

    /**
     * How long a Shipment is left alone before Pathao is asked about it
     * again: four hours, because Pathao announces a change as it happens and
     * this is only the backstop against an announcement that never arrived.
     */
    private const RE_ASK_INTERVAL_SECONDS = 14400;

    public function __construct(private readonly CourierSettings $settings) {}

    public function courier(): Courier
    {
        return Courier::Pathao;
    }

    /**
     * @return list<string>
     */
    public function credentialProperties(): array
    {
        return ['pathaoClientId', 'pathaoClientSecret', 'pathaoUsername', 'pathaoPassword'];
    }

    public function supportsBulk(): bool
    {
        return true;
    }

    public function supportsWebhook(): bool
    {
        return true;
    }

    /**
     * Pathao reports no balance at all. The widget says so in words rather
     * than drawing a zero, because a zero is a Merchant told their money is
     * gone.
     */
    public function supportsBalance(): bool
    {
        return false;
    }

    public function needsAreaResolution(): bool
    {
        return false;
    }

    public function batchLimit(): int
    {
        return self::BATCH_LIMIT;
    }

    public function reAskIntervalSeconds(): int
    {
        return self::RE_ASK_INTERVAL_SECONDS;
    }

    /**
     * Pathao's booking is built by its own ticket. Until then it says it
     * cannot be used, which leaves an Order exactly as it was and words
     * itself back to the operator rather than failing in front of them —
     * and never says Pathao refused, because Pathao was never asked.
     */
    public function book(Shipment $shipment): ShipmentBooked
    {
        throw new CourierNotUsable('Pathao cannot be booked from here yet.');
    }

    /**
     * Pathao's own vocabulary arrives with its booking. Every word reads as
     * Unknown until then, which is what an unread status has always meant: a
     * parcel still to be asked about, never one concluded about.
     */
    public function canonicalStatus(string $rawStatus): ShipmentStatus
    {
        return ShipmentStatus::Unknown;
    }

    /**
     * What Pathao is told about a parcel: which of the Merchant's stores it
     * leaves from, how fast it is to go, what it holds, and what it weighs.
     *
     * The store is declared as a choice and is therefore not put in front of a
     * User until this installation knows of more than one — which, while a
     * store is a single Setting, is never. Declaring it rather than leaving it
     * out is what makes a Merchant with two stores a change to `pickupStores()`
     * and to nothing else.
     *
     * @return list<ParcelField>
     */
    public function parcelFields(): array
    {
        return [
            ParcelField::pickupStore($this->courier(), $this->settings),

            ParcelField::choice(
                'deliveryType',
                'Delivery type',
                CourierSettings::property($this->courier(), 'DeliveryType'),
                [
                    '48' => 'Normal delivery',
                    '12' => 'On demand',
                ],
            ),

            ParcelField::choice(
                'itemType',
                'What is inside',
                CourierSettings::property($this->courier(), 'ItemType'),
                [
                    '2' => 'A parcel',
                    '1' => 'A document',
                ],
            ),

            ParcelField::number(
                'parcelWeight',
                'Weight (kg)',
                CourierSettings::property($this->courier(), 'ParcelWeight'),
            ),
        ];
    }

    public function refusal(): ?string
    {
        return $this->settings->refusal($this->courier());
    }
}
