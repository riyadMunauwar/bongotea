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

    /**
     * The key a registered store's own id is held under.
     *
     * The id is the Courier's and never ours: the same warehouse is a
     * different number at Pathao and at RedEx, which is why the stores are a
     * list per Courier rather than one list of warehouses with a column each.
     */
    public const STORE_ID = 'id';

    /** The key the Merchant's own name for a store is held under — what a chooser reads, never what a Courier is told. */
    public const STORE_NAME = 'name';

    /** Steadfast: off until the Merchant has typed both of its keys. */
    public bool $steadfastEnabled = false;

    /** The API key Steadfast issued. A credential, and so sealed and write-only. */
    public string $steadfastApiKey = '';

    /** The secret key Steadfast issued beside the API key. */
    public string $steadfastSecretKey = '';

    /** Which of the Merchant's warehouses a Steadfast parcel leaves from unless somebody says otherwise. */
    public string $steadfastPickupStore;

    /**
     * Every warehouse the Merchant has registered with Steadfast.
     *
     * @var list<array{id: string, name: string}>
     */
    public array $steadfastPickupStores = [];

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

    /** Which of the Merchant's Pathao stores a parcel leaves from unless somebody says otherwise. */
    public string $pathaoPickupStore;

    /**
     * Every store the Merchant has registered with Pathao, by the id Pathao
     * knows each by.
     *
     * @var list<array{id: string, name: string}>
     */
    public array $pathaoPickupStores = [];

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

    /** Which of the Merchant's RedEx pickup stores a parcel leaves from unless somebody says otherwise. */
    public string $redexPickupStore;

    /**
     * Every pickup store the Merchant has registered with RedEx, by the id
     * RedEx knows each by.
     *
     * @var list<array{id: string, name: string}>
     */
    public array $redexPickupStores = [];

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
     * Two guards the base cannot give this group, in the shape
     * `TrackingSettings::with()` established for the event switches.
     *
     * The first: a registered stores list is an array, and `coerce()` only
     * casts scalars against a declared type — so a row holding a string, a
     * null from a form, or rows a hand edit left half-written would reach
     * `setValue()` and throw, and a `TypeError` from `load()` 500s every
     * request including the page that could repair the row. It has to happen
     * *before* the assignment rather than after it.
     *
     * The second: the default store is one of the registered ones or the
     * Merchant has none at all. A Merchant who deletes the warehouse a parcel
     * went from last week has not thereby decided that the next parcel goes
     * from nowhere — and a default that is not on the list would open the send
     * dialog on a store it does not offer. An installation that has registered
     * nothing keeps whatever single store it has always had, which is what
     * lets one upgrade into this without retyping anything.
     *
     * @param  array<string, mixed>  $values
     */
    public function with(array $values): static
    {
        foreach (Courier::cases() as $courier) {
            $property = self::property($courier, 'PickupStores');

            if (property_exists($this, $property) && array_key_exists($property, $values)) {
                $values[$property] = self::registeredStores($values[$property]);
            }
        }

        $group = parent::with($values);

        foreach (Courier::cases() as $courier) {
            $group->settleDefaultPickupStore($courier);
        }

        return $group;
    }

    /**
     * The warehouses a Courier collects from on this installation, by the id
     * that Courier knows each by, in the order the Merchant registered them.
     *
     * The one reading of "which stores are there", and so the one thing that
     * decides whether anybody is ever asked which store a parcel leaves from
     * (`ParcelField::isWorthAsking()`). A Merchant who has registered nothing
     * has exactly the single store they have always had, named after its own
     * id — so an installation from before this list existed answers one store
     * rather than none, and is asked nothing, which is what it did before.
     *
     * @return array<string, string>
     */
    public function pickupStoresFor(Courier $courier): array
    {
        $property = self::property($courier, 'PickupStores');
        $registered = property_exists($this, $property) ? self::storeOptions($this->{$property}) : [];

        if ($registered !== []) {
            return $registered;
        }

        $only = $this->defaultPickupStore($courier);

        return $only === '' ? [] : [$only => $only];
    }

    /**
     * A registered stores list as a chooser reads it: the Courier's own id
     * against the Merchant's own name for the store.
     *
     * Public and static because the Settings page draws the same options from
     * the rows a User is still typing, which are not this group's values yet —
     * and two readings of the same shape would be two chances to disagree
     * about a half-written row.
     *
     * @return array<string, string>
     */
    public static function storeOptions(mixed $stored): array
    {
        $options = [];

        foreach (self::registeredStores($stored) as $store) {
            $options[$store[self::STORE_ID]] = $store[self::STORE_NAME];
        }

        return $options;
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

    /**
     * A stored stores list in the shape this group promises: a row with no id
     * is not a store, a second row claiming an id the list already holds is
     * the same store typed twice, and a store the Merchant never named is
     * called after the id the Courier knows it by.
     *
     * Everything that is not a row of that shape is dropped rather than
     * repaired. A row is the one input that must never throw, and a store is
     * only ever an id a Courier issued: there is nothing to salvage from
     * whatever an older shape or a hand edit left behind.
     *
     * @return list<array{id: string, name: string}>
     */
    private static function registeredStores(mixed $stored): array
    {
        $registered = [];

        foreach (is_array($stored) ? $stored : [] as $store) {
            if (! is_array($store)) {
                continue;
            }

            $id = self::text($store[self::STORE_ID] ?? null);
            $name = self::text($store[self::STORE_NAME] ?? null);

            if ($id === '' || array_key_exists($id, $registered)) {
                continue;
            }

            $registered[$id] = [self::STORE_ID => $id, self::STORE_NAME => $name === '' ? $id : $name];
        }

        return array_values($registered);
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** The store this Courier's parcels leave from when nobody has been asked. */
    private function defaultPickupStore(Courier $courier): string
    {
        $property = self::property($courier, 'PickupStore');

        return property_exists($this, $property) ? self::text($this->{$property}) : '';
    }

    /**
     * Point a Courier's default at a store it actually has, where the
     * Merchant's registered list no longer holds the one it named.
     */
    private function settleDefaultPickupStore(Courier $courier): void
    {
        $property = self::property($courier, 'PickupStore');
        $registered = $this->pickupStoresFor($courier);

        if (! property_exists($this, $property) || $registered === []) {
            return;
        }

        if (array_key_exists($this->defaultPickupStore($courier), $registered)) {
            return;
        }

        $this->{$property} = (string) array_key_first($registered);
    }
}
