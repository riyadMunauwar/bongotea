<?php

namespace App\Support\Couriers;

use App\Enums\Courier;
use App\Support\Settings\CourierSettings;

/**
 * Steadfast: a static API key and secret key on every call, five hundred
 * parcels to a booking, an address in free text, and nothing at all coming
 * back on its own.
 *
 * The one Courier of the three that announces nothing. Polling is not a
 * backstop for a missed webhook here, it is the only channel there is, which
 * is why it is asked again far more often than the other two and why a
 * Shipment Event carries our own time beside the Courier's.
 *
 * Steadfast is also the only one that will say what it is holding of the
 * Merchant's money.
 */
class Steadfast implements CourierDriver
{
    /**
     * Parcels one booking call may carry, as Steadfast documents its bulk
     * endpoint.
     */
    private const BATCH_LIMIT = 500;

    /**
     * How long a Shipment is left alone before Steadfast is asked about it
     * again: half an hour, because nothing else will ever tell us. The
     * timeline's resolution is this number.
     */
    private const RE_ASK_INTERVAL_SECONDS = 1800;

    public function __construct(private readonly CourierSettings $settings) {}

    public function courier(): Courier
    {
        return Courier::Steadfast;
    }

    /**
     * @return list<string>
     */
    public function credentialProperties(): array
    {
        return ['steadfastApiKey', 'steadfastSecretKey'];
    }

    public function supportsBulk(): bool
    {
        return true;
    }

    public function supportsWebhook(): bool
    {
        return false;
    }

    public function supportsBalance(): bool
    {
        return true;
    }

    /**
     * Steadfast takes the Customer's address as the Checkout captured it, so
     * there is nothing to resolve and nothing for a User to confirm.
     */
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
