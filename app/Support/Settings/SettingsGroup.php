<?php

namespace App\Support\Settings;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * One group of Settings: values a Merchant decides once for the whole
 * installation, held as a typed object and stored as one row.
 *
 * A concrete group declares public typed properties, each with a default,
 * and names its row with `key()`. This base does the rest: reads the row into
 * those properties, writes them back, and encrypts the ones the group marks
 * as secret. It is read from the container, which binds every subclass as a
 * singleton the first time one is resolved (`AppServiceProvider`), so a group
 * needs no registration of its own and a request that never asks for a group
 * never queries its row. There is no cache layer on purpose; adding one with
 * forget-on-save is a change to `load()` and `save()` alone (ADR-0012).
 *
 * Hydration is total, like every stored shape here: a key the class no longer
 * has is ignored, a property the row never mentioned keeps its default, so a
 * property can be added to a group with no data migration and an old row
 * keeps working.
 */
abstract class SettingsGroup
{
    /**
     * Secrets the row holds sealed under a key this installation no longer
     * has, by property name, as they sit in the row. The property reads as
     * blank, but the ciphertext is written back on save so that editing an
     * unrelated value does not destroy what a re-encrypt command could still
     * recover (ADR-0012). Private, so it is not a stored property itself.
     *
     * @var array<string, string>
     */
    private array $unreadableSecrets = [];

    /**
     * The row this group is stored under, unique across groups.
     */
    abstract public static function key(): string;

    /**
     * The properties whose values must never sit in the database, reach the
     * activity log, or travel to the browser in plain text. This one list
     * drives all three: `toArray()` seals them, `changesSince()` redacts them
     * and `SettingsPage` makes their fields write-only (ADR-0012).
     *
     * @return list<string>
     */
    public static function secrets(): array
    {
        return [];
    }

    public static function isSecret(string $name): bool
    {
        return in_array($name, static::secrets(), true);
    }

    /**
     * Whether a secret holds a value, without saying what it is: what the
     * form's "a value is stored" hint asks.
     */
    public function hasSecret(string $name): bool
    {
        return static::isSecret($name) && (filled($this->{$name}) || isset($this->unreadableSecrets[$name]));
    }

    /**
     * The group as the installation has it: the stored row over the defaults,
     * or the defaults alone when nothing has been saved.
     */
    public static function load(): static
    {
        $stored = Setting::query()->find(static::key());

        return static::fromArray($stored?->value ?? []);
    }

    /**
     * Rebuild a group from its stored shape, over the defaults: secrets are
     * unsealed on the way in, then it is `with()` on a fresh instance. A
     * secret that cannot be unsealed reads as blank, is logged, and is kept
     * as it sits in the row so a later save does not destroy it.
     *
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored): static
    {
        $group = new static;

        foreach (static::secrets() as $name) {
            if (! array_key_exists($name, $stored)) {
                continue;
            }

            $sealed = $stored[$name];
            $revealed = static::reveal($name, $sealed);

            if ($revealed === null && is_string($sealed)) {
                $group->unreadableSecrets[$name] = $sealed;
            }

            $stored[$name] = $revealed ?? '';
        }

        return $group->with($stored);
    }

    /**
     * A copy of this group with the named properties replaced by plain
     * values, as a form hands them over. A property the values do not name
     * keeps what this group has, which is how a secret left blank in the
     * form keeps its stored value: the page leaves it out.
     *
     * @param  array<string, mixed>  $values
     */
    public function with(array $values): static
    {
        $group = clone $this;

        foreach (static::storedProperties() as $property) {
            $name = $property->getName();

            if (! array_key_exists($name, $values)) {
                continue;
            }

            $property->setValue($group, static::coerce($property, $values[$name]));
        }

        return $group;
    }

    /**
     * The group as a row: every stored property, secrets encrypted on their own.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $values = [];

        foreach (static::storedProperties() as $property) {
            $name = $property->getName();
            $value = $property->getValue($this);

            if (! static::isSecret($name)) {
                $values[$name] = $value;

                continue;
            }

            /** Blank means "keep what is stored", and what is stored may be sealed under a key we no longer have. */
            $values[$name] = blank($value) && isset($this->unreadableSecrets[$name])
                ? $this->unreadableSecrets[$name]
                : static::conceal($value);
        }

        return $values;
    }

    /**
     * Write the group as its row and make this instance the one the rest of
     * the request reads.
     */
    public function save(): static
    {
        Setting::query()->updateOrCreate(['key' => static::key()], ['value' => $this->toArray()]);

        app()->instance(static::class, $this);

        return $this;
    }

    /**
     * What this group changes against another of the same class, property by
     * property, as the activity log records it: `old` and `attributes` each
     * hold only the properties whose value differs, keyed the same way. Both
     * groups are compared as typed values, never as rows, since a secret's
     * sealed form differs on every write and would always read as changed.
     * A secret that changed is named on both sides with a null in place of
     * each value, so the log says it was rotated and nothing more.
     *
     * @return array{old: array<string, mixed>, attributes: array<string, mixed>}
     */
    public function changesSince(self $previous): array
    {
        $old = [];
        $attributes = [];

        foreach (static::storedProperties() as $property) {
            $name = $property->getName();
            $before = $property->getValue($previous);
            $after = $property->getValue($this);

            if ($before === $after) {
                continue;
            }

            $redacted = static::isSecret($name);

            $old[$name] = $redacted ? null : $before;
            $attributes[$name] = $redacted ? null : $after;
        }

        return ['old' => $old, 'attributes' => $attributes];
    }

    /**
     * The properties a row holds: the public, non-static ones on the concrete
     * class, which is also what makes the class the schema of record.
     *
     * @return list<ReflectionProperty>
     */
    private static function storedProperties(): array
    {
        $properties = (new ReflectionClass(static::class))->getProperties(ReflectionProperty::IS_PUBLIC);

        return array_values(array_filter(
            $properties,
            fn (ReflectionProperty $property): bool => ! $property->isStatic(),
        ));
    }

    /**
     * A stored value in the property's declared scalar type, so a row written
     * by hand or by an older shape reads as what the property promises. A
     * null against a property that allows none reads as that type's empty
     * value, because a row is the one input that must never throw: a
     * `TypeError` from `load()` takes down every request, including the
     * Settings page that could repair the row.
     */
    private static function coerce(ReflectionProperty $property, mixed $value): mixed
    {
        $type = $property->getType();

        if (! $type instanceof ReflectionNamedType) {
            return $value;
        }

        if ($value === null) {
            return $type->allowsNull() ? null : match ($type->getName()) {
                'int' => 0,
                'float' => 0.0,
                'string' => '',
                'bool' => false,
                default => null,
            };
        }

        if (! is_scalar($value)) {
            return $value;
        }

        return match ($type->getName()) {
            'int' => (int) $value,
            'float' => (float) $value,
            'string' => (string) $value,
            'bool' => (bool) $value,
            default => $value,
        };
    }

    private static function conceal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Crypt::encryptString((string) $value);
    }

    /**
     * A secret as it was saved, blank for a value that was never set, or null
     * for one sealed under a key this installation no longer has. That last
     * case is logged rather than swallowed: it is the sign `APP_KEY` was
     * rotated without re-encrypting, and nothing else would say so.
     */
    private static function reveal(string $name, mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException $exception) {
            Log::error('A settings secret could not be decrypted and is kept sealed as stored.', [
                'group' => static::key(),
                'property' => $name,
                'exception' => $exception,
            ]);

            return null;
        }
    }
}
