<?php

namespace App\Support\Couriers;

use App\Enums\Courier;
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

    public function refusal(): ?string
    {
        return $this->settings->refusal($this->courier());
    }
}
