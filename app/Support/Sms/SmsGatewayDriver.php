<?php

namespace App\Support\Sms;

use App\Enums\SmsGateway;

/**
 * The one contract every SMS Gateway is spoken to through.
 *
 * `send()` answers rather than throws, like a Courier's `track()`: a text
 * that cannot go out never stops an Order being captured or moved along, so
 * whatever the gateway says — or does not — comes back as a `TextSent` for
 * the caller to log or retry.
 */
interface SmsGatewayDriver
{
    public function gateway(): SmsGateway;

    /**
     * Why this gateway cannot send on this installation, worded for the
     * Merchant, or null where it can.
     */
    public function refusal(): ?string;

    /**
     * Send one text to one number, as typed or as stored.
     */
    public function send(string $phone, string $message): TextSent;
}
