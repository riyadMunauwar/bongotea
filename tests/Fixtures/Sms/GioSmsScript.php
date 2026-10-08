<?php

namespace Tests\Fixtures\Sms;

use App\Enums\SmsGateway;
use App\Support\Settings\SmsSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * GioSMS's send call, as its documentation describes it: a Bearer token, a
 * JSON body of `to` in 8801… form, `message`, `sender_id` and `type`, a 202
 * carrying `data.message_id` when the text is queued, and
 * `{"success": false, "message": …}` with 400, 401, 402 or 429 otherwise.
 */
final class GioSmsScript extends SmsGatewayScript
{
    private const SEND_URL = 'https://api.giosms.com/api/v1/send';

    private const TOKEN = 'gio_test_token_1';

    private const SENDER_ID = 'BongoTea';

    private const MESSAGE_ID = 'msg_abc123def456';

    private const VALIDATION_MESSAGE = 'The sender id is not registered to this account.';

    public function gateway(): SmsGateway
    {
        return SmsGateway::GioSms;
    }

    public function makeUsable(): void
    {
        app(SmsSettings::class)->with([
            'gateway' => SmsGateway::GioSms->value,
            'giosmsApiToken' => self::TOKEN,
            'giosmsSenderId' => self::SENDER_ID,
        ])->save();
    }

    public function fakeAccepted(): void
    {
        Http::fake([self::SEND_URL => fn (Request $request) => Http::response([
            'success' => true,
            'message' => 'SMS queued successfully',
            'data' => [
                'message_id' => self::MESSAGE_ID,
                'to' => $request['to'],
                'status' => 'queued',
                'sms_count' => 1,
                'cost' => 0.25,
                'queued_at' => '2026-02-10T12:00:00+00:00',
            ],
        ], 202)]);
    }

    public function acceptedReference(): string
    {
        return self::MESSAGE_ID;
    }

    public function fakeAuthFailure(): void
    {
        Http::fake([self::SEND_URL => Http::response(['success' => false, 'message' => 'Unauthenticated.'], 401)]);
    }

    public function fakeValidationError(): void
    {
        Http::fake([self::SEND_URL => Http::response(['success' => false, 'message' => self::VALIDATION_MESSAGE], 400)]);
    }

    public function validationMessage(): string
    {
        return self::VALIDATION_MESSAGE;
    }

    public function fakeOutage(): void
    {
        Http::fake([self::SEND_URL => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);
    }

    public function fakeServerError(): void
    {
        Http::fake([self::SEND_URL => Http::response('Bad Gateway', 502)]);
    }

    public function fakeRateLimited(): void
    {
        Http::fake([self::SEND_URL => Http::response(['success' => false, 'message' => 'SMS rate limit exceeded. Limit: 60/min'], 429)]);
    }

    public function assertSent(string $phone, string $message): void
    {
        Http::assertSentCount(1);

        Http::assertSent(fn (Request $request): bool => $request->url() === self::SEND_URL
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
            && $request['to'] === $phone
            && $request['message'] === $message
            && $request['sender_id'] === self::SENDER_ID
            && $request['type'] === 'transactional');
    }

    public function sent(): array
    {
        return Http::recorded()
            ->filter(fn (array $pair): bool => $pair[0]->url() === self::SEND_URL)
            ->map(fn (array $pair): array => ['to' => $pair[0]['to'], 'message' => $pair[0]['message']])
            ->values()
            ->all();
    }
}
