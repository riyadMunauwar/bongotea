<?php

namespace App\Support\Settings;

/**
 * The installation-wide values the app read from config before Settings
 * existed: currency, phone rules, the default Delivery Charge and the
 * checkout limits.
 *
 * Every default is the config value, which in turn reads the `GIOPAGES_*`
 * environment variables — so an installation with no saved row, and the
 * whole test suite, behaves exactly as it did before (ADR-0012). Nothing
 * outside this constructor reads those config keys. Districts stay in
 * config: a structural list nobody edits per installation is not a Setting.
 */
class GeneralSettings extends SettingsGroup
{
    /** ISO code, e.g. `BDT`. */
    public string $currencyCode;

    /** The symbol drawn beside every figure. */
    public string $currencySymbol;

    /** Digits after the decimal point. Zero for JPY, three for KWD. */
    public int $currencyDecimals;

    /** Country calling code, digits only. Bangladesh is 880. */
    public string $phoneCountryCode;

    /** The leading digit dropped when dialling internationally. */
    public string $phoneTrunkPrefix;

    /** Fewest digits a national number may have once the prefixes are stripped. */
    public int $phoneNationalLengthMin;

    /** Most digits a national number may have once the prefixes are stripped. */
    public int $phoneNationalLengthMax;

    /** The one Delivery Option a new Page is born with, in major units. */
    public float $deliveryCharge;

    /**
     * Which Page answers the Site Root, by id. Zero is none, and the Site Root
     * serves the Placeholder.
     *
     * An id rather than a Slug because a Slug can be changed — the
     * `wasChanged('slug')` branch in `PageCacheInvalidator` exists for that —
     * and a rename would silently orphan the pointer. Zero being "none" also
     * makes the damaged row harmless: `coerce()` reads a stored null on a
     * non-nullable int as `0`, so a row somebody hand-edited falls back to the
     * Placeholder rather than to a Page nobody chose.
     */
    public int $frontPageId;

    /** Upper bound on a single line's quantity. */
    public int $checkoutMaxQuantity;

    /** Captures allowed per minute from one IP. */
    public int $checkoutRateLimit;

    /**
     * Where the Merchant's day begins and ends: a PHP timezone identifier.
     * Storage stays UTC; this decides how a capture time is shown and at
     * which midnight a Reporting Window is cut.
     */
    public string $timezone;

    public function __construct()
    {
        $this->currencyCode = (string) config('giopages.currency.code');
        $this->currencySymbol = (string) config('giopages.currency.symbol');
        $this->currencyDecimals = (int) config('giopages.currency.decimals');
        $this->phoneCountryCode = (string) config('giopages.phone.country_code');
        $this->phoneTrunkPrefix = (string) config('giopages.phone.trunk_prefix');
        $this->phoneNationalLengthMin = (int) config('giopages.phone.national_length.min');
        $this->phoneNationalLengthMax = (int) config('giopages.phone.national_length.max');
        $this->deliveryCharge = (float) config('giopages.delivery.charge');
        $this->frontPageId = (int) config('giopages.front_page_id');
        $this->checkoutMaxQuantity = (int) config('giopages.checkout.max_quantity');
        $this->checkoutRateLimit = (int) config('giopages.checkout.rate_limit');
        $this->timezone = (string) config('giopages.timezone');
    }

    public static function key(): string
    {
        return 'general';
    }

    /**
     * A timezone the row holds that PHP does not know reads as the one this
     * group already has — the config default on a fresh group — rather than
     * reaching Carbon and throwing on every screen that draws a date. The
     * row must never throw; the page's select refuses the value up front.
     *
     * @param  array<string, mixed>  $values
     */
    public function with(array $values): static
    {
        $group = parent::with($values);

        if (! in_array($group->timezone, timezone_identifiers_list(), true)) {
            $group->timezone = $this->timezone;
        }

        return $group;
    }
}
