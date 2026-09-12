<?php

namespace App\Support\Couriers;

use App\Enums\Courier;
use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Support\Settings\CourierSettings;

/**
 * RedEx: one static access token, one parcel to a call, a delivery area it
 * insists on knowing by id, and a callback announcing progress.
 *
 * The awkward one, and the reason two of this feature's decisions look the
 * way they do. It has no bulk endpoint, so two hundred parcels is two hundred
 * sequential requests and a batch cannot live in a web request under any
 * circumstances (ADR-0020). And it will not take an address in free text:
 * its area list is matched against the Customer's address and District and
 * the best guess is put in front of a User to confirm, because a parcel sent
 * to a guessed depot is a parcel in the wrong city.
 *
 * It also demands a weight and a declared value the system holds nowhere,
 * which is what the Merchant's per-Courier defaults exist to supply.
 */
class RedEx implements CourierDriver
{
    /**
     * One parcel to a call. Declared rather than absent so that a batch is
     * the same loop for every Courier and nothing has to ask whether there is
     * a bulk endpoint before cutting one.
     */
    private const BATCH_LIMIT = 1;

    /**
     * How long a Shipment is left alone before RedEx is asked about it again:
     * four hours, because RedEx calls back as a parcel moves and this is only
     * the backstop against a callback that never arrived.
     */
    private const RE_ASK_INTERVAL_SECONDS = 14400;

    public function __construct(private readonly CourierSettings $settings) {}

    public function courier(): Courier
    {
        return Courier::RedEx;
    }

    /**
     * @return list<string>
     */
    public function credentialProperties(): array
    {
        return ['redexAccessToken'];
    }

    public function supportsBulk(): bool
    {
        return false;
    }

    public function supportsWebhook(): bool
    {
        return true;
    }

    public function supportsBalance(): bool
    {
        return false;
    }

    public function needsAreaResolution(): bool
    {
        return true;
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
     * RedEx's booking is built by its own ticket, along with the area
     * matching it insists on. Until then it says it cannot be used, which
     * leaves an Order exactly as it was and words itself back to the
     * operator — and never says RedEx refused, because RedEx was never asked.
     */
    public function book(Shipment $shipment): ShipmentBooked
    {
        throw new CourierNotUsable('RedEx cannot be booked from here yet.');
    }

    /**
     * RedEx's own vocabulary arrives with its booking. Every word reads as
     * Unknown until then, which is what an unread status has always meant: a
     * parcel still to be asked about, never one concluded about.
     */
    public function canonicalStatus(string $rawStatus): ShipmentStatus
    {
        return ShipmentStatus::Unknown;
    }

    /**
     * What RedEx is told about a parcel: which pickup store it leaves from,
     * what it weighs and what it is worth.
     *
     * The weight and the declared value are the two RedEx refuses a parcel
     * without and the system holds neither — no Page asks a Customer how heavy
     * their order is — so the Merchant's defaults are what make a RedEx
     * booking possible at all, and this dialog is where an unusual parcel
     * departs from them.
     *
     * The store is declared as a choice and so is never put in front of a User
     * while this installation knows of only one.
     *
     * @return list<ParcelField>
     */
    public function parcelFields(): array
    {
        return [
            ParcelField::pickupStore($this->courier(), $this->settings),

            ParcelField::number(
                'parcelWeight',
                'Weight (kg)',
                CourierSettings::property($this->courier(), 'ParcelWeight'),
                'RedEx refuses a parcel with no weight.',
            ),

            ParcelField::number(
                'declaredValue',
                'Declared value',
                CourierSettings::property($this->courier(), 'DeclaredValue'),
                'What the parcel is declared to be worth, in major units.',
            ),
        ];
    }

    public function refusal(): ?string
    {
        return $this->settings->refusal($this->courier());
    }
}
