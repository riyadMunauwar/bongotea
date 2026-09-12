<?php

namespace App\Support\Couriers;

use App\Enums\Courier;
use App\Support\Settings\CourierSettings;

/**
 * One thing a single Courier needs to know about a parcel that the others do
 * not ask about at all.
 *
 * Declared by the driver, which is the only thing that speaks a Courier's
 * vocabulary: the send dialog draws whatever the chosen Courier declares and
 * nothing else, so a fourth Courier brings its own questions with its driver
 * and touches no screen.
 *
 * A declaration and not a form component on purpose. Nothing in
 * `app/Support/Couriers/` knows what draws it, and the one declaration
 * answers both questions that are asked of it — what to put in front of a
 * User, and what to send when there was no User to ask.
 *
 * Every field names the `CourierSettings` property holding the installation's
 * default for it, so the dialog opens pre-filled with what the Merchant
 * decided once and is a per-parcel override rather than a second place to
 * configure the installation.
 */
final class ParcelField
{
    /**
     * The name the shared pickup-store declaration is made under.
     *
     * Named here because the declaration is shared: a driver reading what it
     * sent back off the Shipment (`Shipment::parcelDetail()`) has to spell the
     * same key this declared it under, and a second spelling in a driver would
     * send that Courier a blank store rather than fail.
     */
    public const PICKUP_STORE = 'pickupStore';

    /**
     * @param  array<string, string>|null  $options  what may be chosen, or null where anything may be typed
     */
    private function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $settingsProperty,
        public readonly ?array $options,
        public readonly bool $isNumeric,
        public readonly ?string $helperText,
    ) {}

    /** Anything the User cares to type: a Courier that takes free text for this. */
    public static function text(string $name, string $label, string $settingsProperty, ?string $helperText = null): self
    {
        return new self($name, $label, $settingsProperty, null, false, $helperText);
    }

    /** A figure: a weight in kilograms, a value in major units. */
    public static function number(string $name, string $label, string $settingsProperty, ?string $helperText = null): self
    {
        return new self($name, $label, $settingsProperty, null, true, $helperText);
    }

    /**
     * One of the Courier's own documented codes, worded for a Merchant who
     * has never read that Courier's documentation.
     *
     * @param  array<string, string>  $options
     */
    public static function choice(string $name, string $label, string $settingsProperty, array $options, ?string $helperText = null): self
    {
        return new self($name, $label, $settingsProperty, $options, false, $helperText);
    }

    /**
     * Which of the Merchant's warehouses a parcel leaves from, declared once
     * here for every Courier that has a notion of one.
     *
     * The stores to choose between are the ones the installation knows of,
     * which is the single one the Merchant registered in Settings — so this is
     * a choice of one, and `isWorthAsking()` is what keeps a Merchant with one
     * warehouse from ever being shown it. A Courier that later learns of two
     * widens the options and starts asking, here and nowhere else.
     */
    public static function pickupStore(Courier $courier, CourierSettings $settings): self
    {
        $property = CourierSettings::property($courier, 'PickupStore');
        $store = (string) $settings->{$property};

        return self::choice(self::PICKUP_STORE, 'Pickup store', $property, blank($store) ? [] : [$store => $store]);
    }

    /** Whether this is one of a fixed set of the Courier's own codes, rather than something typed. */
    public function isChoice(): bool
    {
        return $this->options !== null;
    }

    /**
     * Whether there is anything here for a User to decide.
     *
     * A choice of one is not a choice, and it is what a Merchant with a single
     * pickup store has: the store still goes out on every parcel, but nobody
     * is stopped and asked to confirm the only answer there is. The rule is
     * general rather than pickup-store-shaped, so a Courier that later knows
     * of two stores starts asking with no change here.
     */
    public function isWorthAsking(): bool
    {
        return ! $this->isChoice() || count($this->options) > 1;
    }

    /** What the Merchant decided once for the whole installation. */
    public function default(CourierSettings $settings): string
    {
        return (string) $settings->{$this->settingsProperty};
    }

    /**
     * What this parcel is actually sent with: what the User overrode it to
     * where they overrode it, the installation's default otherwise.
     *
     * A field nobody was asked takes the default whatever arrives against its
     * name, so a value posted at a question that was never on screen cannot
     * reach a Courier.
     */
    public function valueFor(mixed $given, CourierSettings $settings): string
    {
        if (! $this->isWorthAsking() || ! is_scalar($given)) {
            return $this->default($settings);
        }

        $given = trim((string) $given);

        return $given === '' ? $this->default($settings) : $given;
    }
}
