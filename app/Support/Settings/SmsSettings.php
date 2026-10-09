<?php

namespace App\Support\Settings;

use App\Enums\OrderText;
use App\Enums\SmsGateway;

/**
 * What the store sends by text message, through which SMS Gateway, and to
 * whom on the Merchant's side.
 *
 * Every Order Text and each Staff Alert is a switch and a Bangla template. The
 * Order Texts follow one naming rule — the kind's value, then `Enabled` or
 * `Template` — so nothing here lists them by hand. The gateway's own
 * properties follow the Courier rule: its name, then each credential.
 *
 * The defaults are the mockup's voice, as the storefront groups' are, and
 * the gateway is the log-only one: a fresh installation texts nobody until a
 * Merchant chooses a gateway and pays for it.
 */
class SmsSettings extends SettingsGroup
{
    /** The gateway that sends, by its stored name. */
    public string $gateway = SmsGateway::LogOnly->value;

    /** The API token GioSMS issues under Settings → API Tokens. Sealed and write-only. */
    public string $giosmsApiToken = '';

    /** The sender id registered with GioSMS, at most 16 characters. */
    public string $giosmsSenderId = '';

    /**
     * The Merchant's own numbers, each as typed, that Staff Alerts go to.
     *
     * @var list<string>
     */
    public array $staffNumbers = [];

    public bool $placedEnabled = true;

    public string $placedTemplate = '{name}, আপনার অর্ডার {order_number} পেয়েছি। মোট {kilograms} কেজি, {total}। শীঘ্রই কল করে নিশ্চিত করব। {link}';

    public bool $confirmedEnabled = true;

    public string $confirmedTemplate = 'আপনার অর্ডার {order_number} নিশ্চিত হয়েছে। মোট {total}। {link}';

    public bool $sentEnabled = true;

    public string $sentTemplate = 'আপনার অর্ডার {order_number} কুরিয়ারে পাঠানো হয়েছে। পণ্য হাতে পেয়ে মূল্য দিন। {link}';

    public bool $cancelledEnabled = true;

    public string $cancelledTemplate = 'আপনার অর্ডার {order_number} বাতিল করা হয়েছে। কোনো প্রশ্ন থাকলে আমাদের কল করুন।';

    /** The Staff Alert for a new Order. */
    public bool $newOrderAlertEnabled = true;

    public string $newOrderAlertTemplate = 'নতুন অর্ডার {order_number}: {name}, {kilograms} কেজি, {total}';

    /** The Staff Alert for a new Enquiry. */
    public bool $newEnquiryAlertEnabled = true;

    public string $newEnquiryAlertTemplate = 'নতুন প্রশ্ন: {name}, {phone} ({topic})';

    public static function key(): string
    {
        return 'sms';
    }

    /**
     * @return list<string>
     */
    public static function secrets(): array
    {
        return ['giosmsApiToken'];
    }

    /**
     * A row holding something other than a list of numbers reads as no
     * numbers, before the base assigns it: a `TypeError` from `load()` would
     * take down every request (see `.ai/rules/settings.md`).
     *
     * @param  array<string, mixed>  $values
     */
    public function with(array $values): static
    {
        if (array_key_exists('staffNumbers', $values)) {
            $values['staffNumbers'] = is_array($values['staffNumbers'])
                ? array_values(array_filter(array_map(
                    fn (mixed $number): string => is_scalar($number) ? trim((string) $number) : '',
                    $values['staffNumbers'],
                ), filled(...)))
                : [];
        }

        return parent::with($values);
    }

    /**
     * The gateway that sends; a name nothing knows reads as the log-only one,
     * so a damaged row sends nothing rather than failing every text.
     */
    public function gateway(): SmsGateway
    {
        return SmsGateway::fromStored($this->gateway) ?? SmsGateway::LogOnly;
    }

    /**
     * Why this gateway cannot send on this installation, worded for the
     * Merchant, or null where it can: the one wording of "usable", which
     * every driver's `refusal()` asks rather than restates.
     */
    public function refusal(SmsGateway $gateway): ?string
    {
        return match ($gateway) {
            SmsGateway::GioSms => blank($this->giosmsApiToken) || blank($this->giosmsSenderId)
                ? 'GioSMS has a blank API token or sender id in Settings.'
                : null,
            SmsGateway::LogOnly => null,
        };
    }

    public function isOn(OrderText $text): bool
    {
        return $this->{$text->value.'Enabled'};
    }

    public function template(OrderText $text): string
    {
        return $this->{$text->value.'Template'};
    }
}
