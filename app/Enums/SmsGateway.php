<?php

namespace App\Enums;

use App\Support\Sms\GioSms;
use App\Support\Sms\LogOnly;
use App\Support\Sms\SmsGatewayDriver;
use Filament\Support\Contracts\HasLabel;

/**
 * A company the store sends text messages through, by name.
 *
 * The enum is the registry, as `Courier` and `PaymentMethod` are (ADR-0013):
 * a later gateway is one case here and one class implementing
 * `SmsGatewayDriver`, with no config list and no provider line. One gateway
 * at a time is the one that sends, chosen in `SmsSettings`.
 *
 * `LogOnly` sends nothing anywhere and writes each text to the application
 * log. It is what a fresh installation and a development machine send
 * through, so nothing reaches a real phone until a Merchant chooses a gateway
 * and pays for it.
 */
enum SmsGateway: string implements HasLabel
{
    case GioSms = 'giosms';
    case LogOnly = 'log';

    public function getLabel(): string
    {
        return match ($this) {
            self::GioSms => 'GioSMS',
            self::LogOnly => 'Log only (sends nothing)',
        };
    }

    /**
     * What a stored name is, or null for one nothing knows.
     */
    public static function fromStored(mixed $stored): ?self
    {
        return is_string($stored) ? self::tryFrom($stored) : null;
    }

    /**
     * The driver behind this gateway, resolved from the container so it
     * takes its Settings and its HTTP client by injection.
     */
    public function driver(): SmsGatewayDriver
    {
        return app(match ($this) {
            self::GioSms => GioSms::class,
            self::LogOnly => LogOnly::class,
        });
    }
}
