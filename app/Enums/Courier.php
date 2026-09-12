<?php

namespace App\Enums;

use App\Support\Couriers\CourierDriver;
use App\Support\Couriers\Pathao;
use App\Support\Couriers\RedEx;
use App\Support\Couriers\Steadfast;
use Filament\Support\Contracts\HasLabel;

/**
 * A delivery company a parcel can be handed to, by name.
 *
 * The enum is the registry, exactly as `PaymentMethod` and `TrackingProvider`
 * are (ADR-0013): the name lives here and every behaviour lives on a driver
 * behind one contract, so adding a fourth Courier is one case here and one
 * class implementing `CourierDriver` — no config file listing couriers, no
 * service-provider registration, nothing to keep in step.
 *
 * A Merchant supplies what each Courier needs once for the whole installation
 * (`CourierSettings`), and one that has not been supplied is offered nowhere.
 * Why it cannot be used is never asked here or restated at a call site: the
 * question goes to the driver, which is the contract that declares it.
 *
 * Stored as a string column and cast, never as a native ENUM, for the reason
 * `OrderStatus` is one.
 */
enum Courier: string implements HasLabel
{
    case Steadfast = 'steadfast';
    case Pathao = 'pathao';
    case RedEx = 'redex';

    public function getLabel(): string
    {
        return match ($this) {
            self::Steadfast => 'Steadfast',
            self::Pathao => 'Pathao',
            self::RedEx => 'RedEx',
        };
    }

    /**
     * What a stored name is, whatever it happens to hold.
     *
     * Total, like every other name read back from a row or a request: a name
     * nothing knows, or something that is not a name at all, is null rather
     * than an exception, and the caller drops it.
     */
    public static function fromStored(mixed $stored): ?self
    {
        return is_string($stored) ? self::tryFrom($stored) : null;
    }

    /**
     * The driver behind this Courier, resolved from the container so it takes
     * its Settings — and, once there is a call to make, its HTTP client
     * factory — by injection.
     */
    public function driver(): CourierDriver
    {
        return app(match ($this) {
            self::Steadfast => Steadfast::class,
            self::Pathao => Pathao::class,
            self::RedEx => RedEx::class,
        });
    }

    /**
     * Why this Courier cannot be used on this installation, worded for the
     * Merchant, or null where it can.
     *
     * Asked, never answered: the driver is the one place the wording comes
     * from, so the admin, the booking dialog and the sweep all print the same
     * sentence and none of them restates the rule.
     */
    public function refusal(): ?string
    {
        return $this->driver()->refusal();
    }

    public function isUsable(): bool
    {
        return $this->refusal() === null;
    }
}
