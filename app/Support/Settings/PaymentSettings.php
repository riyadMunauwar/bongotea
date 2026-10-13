<?php

namespace App\Support\Settings;

use App\Enums\PaymentMethod;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * What the Merchant has decided, once for the installation, about each
 * Payment Method: whether it is on, what the gateway needs to be spoken to,
 * whether that is the sandbox or real money, and the logo the Checkout draws.
 *
 * One row for every method rather than one per method, because the usable
 * list is read against all of them at once and a row per method would be a
 * query per method on every render. The properties follow one naming
 * rule so that nothing here lists methods by hand: a method's properties are
 * its name in camel case, then `Enabled`, `Sandbox`, `Logo`, and each of its
 * credentials — so `sslcommerzEnabled` and `sslcommerzStoreId`. A method with
 * no `Enabled` property here has no settings yet and is offered nowhere.
 *
 * This is the one place the usable-method rule lives (`refusal()`): on, every
 * credential filled, and, for an online method, the installation currency is
 * BDT. Everything that draws or validates a method asks it, through
 * `PaymentMethod::isUsable()`, and nothing asks any other question.
 *
 * Every default leaves the installation as it was before this group existed:
 * Cash on Delivery on, every gateway off with blank credentials (ADR-0012).
 */
class PaymentSettings extends SettingsGroup
{
    /**
     * The one currency the online methods take. Every gateway in the set is a
     * Bangladeshi one, and a Merchant who has set another currency would be
     * sending the gateway a figure in the wrong money.
     */
    public const CURRENCY = 'BDT';

    /**
     * Where a Merchant's replacement logo is written: the public local disk,
     * the same way a Product's image is (ADR-0011), under a directory of its
     * own. The row holds only the path.
     */
    public const LOGO_DISK = 'local';

    public const LOGO_DIRECTORY = 'payment-methods';

    /** Cash on Delivery: on, so the store keeps its one way to order. */
    public bool $cashOnDeliveryEnabled = true;

    /** A replacement logo for Cash on Delivery, as a path on the logo disk; blank draws the default. */
    public string $cashOnDeliveryLogo = '';

    /** SSLCommerz: off until the Merchant has typed their store's credentials. */
    public bool $sslcommerzEnabled = false;

    /** Whether Payments go to SSLCommerz's sandbox rather than to real money. */
    public bool $sslcommerzSandbox = true;

    /** The store id SSLCommerz issued. A credential, and so sealed and write-only. */
    public string $sslcommerzStoreId = '';

    /** The store password SSLCommerz issued. */
    public string $sslcommerzStorePassword = '';

    /** A replacement logo for SSLCommerz; blank draws the default. */
    public string $sslcommerzLogo = '';

    /** bKash: off until the Merchant has typed their merchant account's credentials. */
    public bool $bkashEnabled = false;

    /** Whether Payments go to bKash's sandbox rather than to real money. */
    public bool $bkashSandbox = true;

    /** The application key bKash issued at onboarding. A credential, and so sealed and write-only. */
    public string $bkashAppKey = '';

    /** The application secret bKash issued at onboarding. */
    public string $bkashAppSecret = '';

    /** The merchant account username bKash's token grant takes. */
    public string $bkashUsername = '';

    /** The merchant account password bKash's token grant takes. */
    public string $bkashPassword = '';

    /**
     * Where bKash's sandbox answers, up to and including the API version.
     * bKash hands the hosts over at onboarding rather than printing them in
     * its documentation, so each is a setting with the usual one as default.
     */
    public string $bkashSandboxHost = 'https://tokenized.sandbox.bka.sh/v1.2.0-beta';

    /** Where bKash answers for real money. */
    public string $bkashLiveHost = 'https://tokenized.pay.bka.sh/v1.2.0-beta';

    /** A replacement logo for bKash; blank draws the default. */
    public string $bkashLogo = '';

    /** aamarPay: off until the Merchant has typed their store's credentials. */
    public bool $aamarpayEnabled = false;

    /** Whether Payments go to aamarPay's sandbox rather than to real money. */
    public bool $aamarpaySandbox = true;

    /** The store id aamarPay issued. A credential, and so sealed and write-only. */
    public string $aamarpayStoreId = '';

    /** The signature key aamarPay issued. */
    public string $aamarpaySignatureKey = '';

    /** A replacement logo for aamarPay; blank draws the default. */
    public string $aamarpayLogo = '';

    /** PayStation: off until the Merchant has typed their merchant credentials. */
    public bool $paystationEnabled = false;

    /** Whether Payments go to PayStation's sandbox rather than to real money. */
    public bool $paystationSandbox = true;

    /** The merchant id PayStation issued. A credential, and so sealed and write-only. */
    public string $paystationMerchantId = '';

    /** The password PayStation issued beside the merchant id. */
    public string $paystationPassword = '';

    /** A replacement logo for PayStation; blank draws the default. */
    public string $paystationLogo = '';

    public static function key(): string
    {
        return 'payment';
    }

    /**
     * Every credential, and nothing else: the store id is a credential too,
     * since together with the password it is the whole of what SSLCommerz
     * asks for, bKash's four are what its token grant takes, aamarPay's
     * store id and signature key sign every call, and PayStation's merchant
     * id and password start one. A host is not a credential. What a
     * gateway's `credentialProperties()` names must be here.
     *
     * @return list<string>
     */
    public static function secrets(): array
    {
        return [
            'sslcommerzStoreId',
            'sslcommerzStorePassword',
            'bkashAppKey',
            'bkashAppSecret',
            'bkashUsername',
            'bkashPassword',
            'aamarpayStoreId',
            'aamarpaySignatureKey',
            'paystationMerchantId',
            'paystationPassword',
        ];
    }

    /**
     * The methods the Merchant has switched on, in the enum's order —
     * including one that is on but not yet usable, so a screen can word why.
     *
     * @return list<PaymentMethod>
     */
    public function enabledMethods(): array
    {
        return array_values(array_filter(PaymentMethod::cases(), $this->isEnabled(...)));
    }

    /**
     * The methods an Order can be paid by on this installation.
     *
     * @return list<PaymentMethod>
     */
    public function usableMethods(): array
    {
        return array_values(array_filter(PaymentMethod::cases(), $this->isUsable(...)));
    }

    /**
     * The methods the Checkout and the Retry Page offer: whatever the store
     * can take payment by now, and Cash on Delivery alone where it can take
     * nothing, because an Order is always owed a way to be settled.
     *
     * @return non-empty-list<PaymentMethod>
     */
    public function offeredMethods(): array
    {
        return $this->usableMethods() ?: [PaymentMethod::CashOnDelivery];
    }

    /**
     * Whether a method has its settings here yet: an `Enabled` property by
     * the naming rule. A gateway still to come has none, which is how one is
     * held off without a list anywhere; every method has them now.
     */
    public static function hasSettings(PaymentMethod $method): bool
    {
        return property_exists(static::class, self::property($method, 'Enabled'));
    }

    /**
     * Whether the Merchant has switched a method on. A method with no settings
     * here yet is off.
     */
    public function isEnabled(PaymentMethod $method): bool
    {
        return self::hasSettings($method) && $this->{self::property($method, 'Enabled')} === true;
    }

    public function isUsable(PaymentMethod $method): bool
    {
        return $this->refusal($method) === null;
    }

    /**
     * Why a method cannot be offered, in the words a screen draws beside it,
     * or null where it can. The one wording of the rule, so no two screens
     * can disagree about what is allowed: a method is
     * usable when it is on, every credential it needs is filled, and, for an
     * online method, the installation currency is BDT.
     */
    public function refusal(PaymentMethod $method): ?string
    {
        $label = $method->getLabel();

        if (! self::hasSettings($method)) {
            return "{$label} is not available yet.";
        }

        if (! $this->isEnabled($method)) {
            return "{$label} is switched off in Settings.";
        }

        foreach ($this->credentialsFor($method) as $credential) {
            if (blank($this->{$credential})) {
                return "{$label} has blank credentials in Settings.";
            }
        }

        if ($method->isOnline() && ! $this->currencyIsSupported()) {
            return "{$label} takes ".self::CURRENCY." only, and the currency is {$this->currencyCode()}.";
        }

        return null;
    }

    /**
     * The credential properties a method needs: its secrets, by the naming
     * rule. Empty for Cash on Delivery, which talks to no gateway.
     *
     * @return list<string>
     */
    public function credentialsFor(PaymentMethod $method): array
    {
        /** The prefix and then a capital, so a method whose name begins another's cannot claim its credentials. */
        $pattern = '/^'.preg_quote(self::prefix($method), '/').'[A-Z]/';

        return array_values(array_filter(
            static::secrets(),
            fn (string $name): bool => preg_match($pattern, $name) === 1,
        ));
    }

    /**
     * Whether the installation currency is one the online methods take.
     */
    public function currencyIsSupported(): bool
    {
        return $this->currencyCode() === self::CURRENCY;
    }

    /**
     * The installation currency as the General settings have it, upper-cased
     * so a code typed in lower case still reads as the same money.
     */
    public function currencyCode(): string
    {
        return Str::upper(app(GeneralSettings::class)->currencyCode);
    }

    /**
     * The path of the Merchant's replacement logo for a method on the logo
     * disk, or null where they have kept the default.
     */
    public function logoPathFor(PaymentMethod $method): ?string
    {
        $property = self::property($method, 'Logo');

        if (! property_exists($this, $property) || blank($this->{$property})) {
            return null;
        }

        return $this->{$property};
    }

    /**
     * Where the Checkout fetches a method's logo from: the Merchant's
     * replacement where one is stored, else the default shipped with the
     * method. Absolute, so it reads the same from anywhere it is drawn.
     */
    public function logoUrlFor(PaymentMethod $method): string
    {
        $path = $this->logoPathFor($method);

        return $path === null ? asset($method->logoPath()) : url(Storage::disk(self::LOGO_DISK)->url($path));
    }

    /**
     * A method's property of the given kind, by the naming rule.
     */
    public static function property(PaymentMethod $method, string $suffix): string
    {
        return self::prefix($method).$suffix;
    }

    private static function prefix(PaymentMethod $method): string
    {
        return Str::camel($method->value);
    }
}
