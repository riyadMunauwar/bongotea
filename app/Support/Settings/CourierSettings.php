<?php

namespace App\Support\Settings;

use App\Enums\Courier;
use Illuminate\Support\Str;
use ReflectionProperty;

/**
 * What the Merchant has decided, once for the installation, about each
 * Courier: whether it is on, what it needs to be spoken to, and what an
 * ordinary parcel of theirs looks like.
 *
 * One row for every Courier rather than one per Courier, for the reason
 * `PaymentSettings` is one: the list is asked about all at once whenever a
 * chooser is drawn, and a row per Courier would be a query per Courier. One
 * account per Courier, which is what keeps this a settings group at all —
 * ADR-0012's reasoning about an unbounded user-created list carrying secrets
 * does not reach a list bounded by an enum.
 *
 * The properties follow one naming rule, so nothing here lists couriers by
 * hand: a Courier's properties are its name in camel case, then `Enabled`,
 * each of its credentials, and each of the five defaults. A Courier with no
 * `Enabled` property here has no settings yet and is offered nowhere.
 *
 * This is the one place the usable-Courier rule lives (`refusal()`). Every
 * driver's `refusal()` asks it and nothing restates it.
 *
 * Deliberately not here: Pathao's access token. It is derived state a machine
 * issues and expires, not a credential a Merchant types, and a settings group
 * holds values a human owns — so it is cached where the driver that obtains
 * it can find it and nowhere else.
 */
class CourierSettings extends SettingsGroup
{
    /**
     * What a Merchant decides per Courier about an ordinary parcel, by
     * property suffix.
     *
     * These are what make an unattended booking possible at all: RedEx
     * refuses a parcel with no weight and no declared value, and the system
     * holds neither — nothing on a Page asks a Customer how heavy their order
     * is. Which of the five a Courier actually sends is its driver's business
     * and arrives with booking; the Merchant answers all five once.
     *
     * This list is what the constructor walks, and each suffix in snake case
     * is its config key — so a sixth default is one entry here, one property
     * per Courier, and one config key each.
     *
     * @var list<string>
     */
    public const DEFAULTS = ['PickupStore', 'DeliveryType', 'ItemType', 'ParcelWeight', 'DeclaredValue'];

    /** Steadfast: off until the Merchant has typed both of its keys. */
    public bool $steadfastEnabled = false;

    /** The API key Steadfast issued. A credential, and so sealed and write-only. */
    public string $steadfastApiKey = '';

    /** The secret key Steadfast issued beside the API key. */
    public string $steadfastSecretKey = '';

    /** Which of the Merchant's warehouses a Steadfast parcel leaves from. */
    public string $steadfastPickupStore;

    /** What Steadfast is told this kind of delivery is. */
    public string $steadfastDeliveryType;

    /** What Steadfast is told is inside the parcel. */
    public string $steadfastItemType;

    /** What an ordinary parcel weighs, in kilograms. */
    public float $steadfastParcelWeight;

    /** What an ordinary parcel is declared to be worth, in major units. */
    public float $steadfastDeclaredValue;

    /** Pathao: off until the Merchant has typed all four of its credentials. */
    public bool $pathaoEnabled = false;

    /** The client id Pathao issued. A credential, and so sealed and write-only. */
    public string $pathaoClientId = '';

    /** The client secret Pathao issued beside the client id. */
    public string $pathaoClientSecret = '';

    /** The merchant account username Pathao's token grant takes. */
    public string $pathaoUsername = '';

    /** The merchant account password Pathao's token grant takes. */
    public string $pathaoPassword = '';

    /** Which of the Merchant's Pathao stores a parcel leaves from. */
    public string $pathaoPickupStore;

    /** What Pathao is told this kind of delivery is. */
    public string $pathaoDeliveryType;

    /** What Pathao is told is inside the parcel. */
    public string $pathaoItemType;

    /** What an ordinary parcel weighs, in kilograms. */
    public float $pathaoParcelWeight;

    /** What an ordinary parcel is declared to be worth, in major units. */
    public float $pathaoDeclaredValue;

    /** RedEx: off until the Merchant has typed its access token. */
    public bool $redexEnabled = false;

    /** The access token RedEx issued. A credential, and so sealed and write-only. */
    public string $redexAccessToken = '';

    /** Which of the Merchant's RedEx pickup stores a parcel leaves from. */
    public string $redexPickupStore;

    /** What RedEx is told this kind of delivery is. */
    public string $redexDeliveryType;

    /** What RedEx is told is inside the parcel. */
    public string $redexItemType;

    /** What an ordinary parcel weighs, in kilograms. RedEx refuses a parcel without one. */
    public float $redexParcelWeight;

    /** What an ordinary parcel is declared to be worth, in major units. RedEx refuses a parcel without one. */
    public float $redexDeclaredValue;

    /**
     * Every default comes from config, driven by `DEFAULTS` rather than
     * listed again here, so a sixth default is one entry there and one
     * property — and nothing outside this constructor reads
     * `giopages.couriers.*` (ADR-0012).
     *
     * The switches and the credentials have no config keys on purpose: an
     * installation is off with nothing typed until a Merchant says otherwise,
     * and a credential in an environment file is a credential this group
     * never got to seal.
     */
    public function __construct()
    {
        foreach (Courier::cases() as $courier) {
            $stored = config("giopages.couriers.{$courier->value}");

            foreach (self::DEFAULTS as $suffix) {
                $property = self::property($courier, $suffix);
                $value = data_get($stored, Str::snake($suffix));

                /** The property's own declaration says what the config value is, so the two cannot drift apart. */
                $this->{$property} = (new ReflectionProperty($this, $property))->getType()?->getName() === 'float'
                    ? (float) $value
                    : (string) $value;
            }
        }
    }

    public static function key(): string
    {
        return 'courier';
    }

    /**
     * Every credential, and nothing else: Steadfast signs each call with its
     * two keys, Pathao's four are what its token grant takes, and RedEx's one
     * token is the whole of its authentication. A default is not a credential.
     * What a driver's `credentialProperties()` names must be here.
     *
     * @return list<string>
     */
    public static function secrets(): array
    {
        return [
            'steadfastApiKey',
            'steadfastSecretKey',
            'pathaoClientId',
            'pathaoClientSecret',
            'pathaoUsername',
            'pathaoPassword',
            'redexAccessToken',
        ];
    }

    /**
     * The couriers the Merchant has switched on, in the enum's order —
     * including one that is on but not yet usable, so a screen listing them
     * can word why.
     *
     * @return list<Courier>
     */
    public function enabledCouriers(): array
    {
        return array_values(array_filter(Courier::cases(), $this->isEnabled(...)));
    }

    /**
     * The couriers a parcel can actually be handed to on this installation,
     * in the enum's order. What a chooser offers.
     *
     * @return list<Courier>
     */
    public function usableCouriers(): array
    {
        return array_values(array_filter(Courier::cases(), $this->isUsable(...)));
    }

    /**
     * Whether a Courier has its settings here yet: an `Enabled` property by
     * the naming rule. A Courier still to come has none, which is how one is
     * held off without a list anywhere; all three have them now.
     */
    public static function hasSettings(Courier $courier): bool
    {
        return property_exists(static::class, self::property($courier, 'Enabled'));
    }

    /**
     * Whether the Merchant has switched a Courier on. A Courier with no
     * settings here yet is off, whatever anything else lists.
     */
    public function isEnabled(Courier $courier): bool
    {
        return self::hasSettings($courier) && $this->{self::property($courier, 'Enabled')} === true;
    }

    public function isUsable(Courier $courier): bool
    {
        return $this->refusal($courier) === null;
    }

    /**
     * Why a Courier cannot be used, in the words every screen and the sweep
     * draw, or null where it can. The one wording of the rule: a Courier is
     * usable when it is on and every credential it needs is filled in.
     */
    public function refusal(Courier $courier): ?string
    {
        $label = $courier->getLabel();

        if (! self::hasSettings($courier)) {
            return "{$label} is not available yet.";
        }

        if (! $this->isEnabled($courier)) {
            return "{$label} is switched off in Settings.";
        }

        foreach ($this->credentialsFor($courier) as $credential) {
            if (blank($this->{$credential})) {
                return "{$label} has blank credentials in Settings.";
            }
        }

        return null;
    }

    /**
     * The credential properties a Courier needs: its secrets, by the naming
     * rule.
     *
     * @return list<string>
     */
    public function credentialsFor(Courier $courier): array
    {
        /** The prefix and then a capital, so a Courier whose name begins another's cannot claim its credentials. */
        $pattern = '/^'.preg_quote(self::prefix($courier), '/').'[A-Z]/';

        return array_values(array_filter(
            static::secrets(),
            fn (string $name): bool => preg_match($pattern, $name) === 1,
        ));
    }

    /**
     * A Courier's property of the given kind, by the naming rule.
     */
    public static function property(Courier $courier, string $suffix): string
    {
        return self::prefix($courier).$suffix;
    }

    private static function prefix(Courier $courier): string
    {
        return Str::camel($courier->value);
    }
}
