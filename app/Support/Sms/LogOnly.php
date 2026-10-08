<?php

namespace App\Support\Sms;

use App\Enums\SmsGateway;
use App\Support\PhoneNumber;
use App\Support\Settings\SmsSettings;
use Illuminate\Support\Facades\Log;

/**
 * The gateway that sends nothing: each text is written to the application
 * log, so a development machine or a store that has not chosen a gateway yet
 * can see exactly what would have gone out, and to whom, without paying for
 * it or reaching a real phone.
 */
class LogOnly implements SmsGatewayDriver
{
    public function gateway(): SmsGateway
    {
        return SmsGateway::LogOnly;
    }

    public function __construct(private readonly SmsSettings $settings) {}

    public function refusal(): ?string
    {
        return $this->settings->refusal($this->gateway());
    }

    public function send(string $phone, string $message): TextSent
    {
        Log::info('A text message was written to the log instead of being sent.', [
            'to' => PhoneNumber::asInternational($phone) ?? $phone,
            'message' => $message,
        ]);

        return TextSent::sent();
    }
}
