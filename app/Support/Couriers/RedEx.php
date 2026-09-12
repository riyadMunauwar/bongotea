<?php

namespace App\Support\Couriers;

use App\Enums\Courier;
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

    public function refusal(): ?string
    {
        return $this->settings->refusal($this->courier());
    }
}
