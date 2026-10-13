<?php

namespace App\Support\Couriers;

use App\Enums\Courier;
use App\Models\Order;
use App\Support\Inventory\Grams;
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
        public readonly bool $isWeight = false,
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
     * The parcel's weight in kilograms. The Merchant's default stands for a
     * bulk Order, as it always has; a retail Order is weighed from its Packs
     * instead (ADR-0034), because a basket of 250 g Packs is nothing like the
     * parcel a Merchant has in mind when they set one figure for every Order.
     */
    public static function weight(string $name, string $label, string $settingsProperty, ?string $helperText = null): self
    {
        return new self($name, $label, $settingsProperty, null, true, $helperText, isWeight: true);
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
     * The stores to choose between are the ones the Merchant registered with
     * this Courier, by the id that Courier knows each by — so a Merchant with
     * a single warehouse has a choice of one and `isWorthAsking()` keeps them
     * from ever being shown it, and a Merchant who registers a second is asked
     * from that moment on, with no change here or anywhere the dialog is
     * drawn. The default the dialog opens on is the Merchant's own
     * (`settingsProperty`), so the common case is still one tap.
     */
    public static function pickupStore(Courier $courier, CourierSettings $settings): self
    {
        return self::choice(
            self::PICKUP_STORE,
            'Pickup store',
            CourierSettings::property($courier, 'PickupStore'),
            $settings->pickupStoresFor($courier),
            'Where this parcel is collected from. Your other orders are unaffected.',
        );
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

    /**
     * What the Merchant decided once for the whole installation — except a
     * retail Order's weight, which is what its Packs weigh, in kilograms.
     */
    public function default(CourierSettings $settings, ?Order $order = null): string
    {
        if ($this->isWeight && $order !== null && $order->isRetail()) {
            return rtrim(rtrim(number_format($order->grams() / Grams::PER_KILOGRAM, 3, '.', ''), '0'), '.');
        }

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
    public function valueFor(mixed $given, CourierSettings $settings, ?Order $order = null): string
    {
        if (! $this->isWorthAsking() || ! is_scalar($given)) {
            return $this->default($settings, $order);
        }

        $given = trim((string) $given);

        return $given === '' ? $this->default($settings, $order) : $given;
    }
}
