<?php

namespace App\Support\Sms;

use App\Enums\SmsGateway;
use App\Support\PhoneNumber;
use App\Support\Settings\SmsSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;

/**
 * GioSMS: a Bearer token, one send call, and a 202 carrying a message id.
 *
 * Its refusals arrive as `{"success": false, "message": …}` under 400 (the
 * text or the account), 401 (the token), 402 (no balance) or 404 (an unknown
 * sender id); 429 and anything from 500 up say nothing about the text, so
 * those alone are worth asking again.
 */
class GioSms implements SmsGatewayDriver
{
    private const SEND_URL = 'https://api.giosms.com/api/v1/send';

    /** GioSMS's own word for a text that is not an OTP and not a promotion — and the queue priority that goes with it. */
    private const TYPE = 'transactional';

    /** Seconds to connect, and for the whole call: nobody is waiting on a text, but a worker is. */
    private const CONNECT_TIMEOUT = 5;

    private const TIMEOUT = 15;

    public function __construct(
        private readonly SmsSettings $settings,
        private readonly Http $http,
    ) {}

    public function gateway(): SmsGateway
    {
        return SmsGateway::GioSms;
    }

    public function refusal(): ?string
    {
        return $this->settings->refusal($this->gateway());
    }

    public function send(string $phone, string $message): TextSent
    {
        $to = PhoneNumber::asInternational($phone);

        if ($to === null) {
            return TextSent::refused("[{$phone}] is not a number the dialling rules can read.");
        }

        try {
            $response = $this->http
                ->withToken($this->settings->giosmsApiToken)
                ->acceptJson()
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::TIMEOUT)
                ->post(self::SEND_URL, [
                    'to' => $to,
                    'message' => $message,
                    'sender_id' => $this->settings->giosmsSenderId,
                    'type' => self::TYPE,
                ]);
        } catch (ConnectionException $exception) {
            return TextSent::unreachable("GioSMS could not be reached: {$exception->getMessage()}");
        }

        $status = $response->status();

        if ($status >= 500 || $status === 429 || $status === 408) {
            return TextSent::unreachable("GioSMS would not take the call just now (HTTP {$status}).");
        }

        if ($response->successful() && $response->json('success') === true) {
            return TextSent::sent($response->json('data.message_id'));
        }

        $said = $response->json('message');

        return TextSent::refused(is_string($said) && $said !== ''
            ? "GioSMS refused the text (HTTP {$status}): {$said}"
            : "GioSMS refused the text (HTTP {$status}).");
    }
}
