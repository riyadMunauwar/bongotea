<?php

namespace App\Support\Settings;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
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
 * as secret. It is read from the container, which holds one instance per
 * request and builds it on first use, so a request that never asks for a
 * group never queries its row. There is no cache layer on purpose; adding one
 * with forget-on-save is a change to `load()` and `save()` alone (ADR-0012).
 *
 * Hydration is total, like a stored Checkout Config: a key the class no longer
 * has is ignored, a property the row never mentioned keeps its default, so a
 * property can be added to a group with no data migration and an old row
 * keeps working.
 */
abstract class SettingsGroup
{
    /**
     * The row this group is stored under, unique across groups.
     */
    abstract public static function key(): string;

    /**
     * The properties whose values must never sit in the database, or reach the
     * activity log, in plain text.
     *
     * @return list<string>
     */
    protected static function secrets(): array
    {
        return [];
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
     * Rebuild a group from its stored shape, over the defaults.
     *
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored): static
    {
        $group = new static;

        foreach (static::storedProperties() as $property) {
            $name = $property->getName();

            if (! array_key_exists($name, $stored)) {
                continue;
            }

            $value = $stored[$name];

            if (in_array($name, static::secrets(), true)) {
                $value = static::reveal($value);
            }

            $property->setValue($group, static::coerce($property, $value));
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

            $values[$name] = in_array($name, static::secrets(), true)
                ? static::conceal($value)
                : $value;
        }

        return $values;
    }

    /**
     * Write the group as its row, and make this instance the one the rest of
     * the request reads.
     */
    public function save(): static
    {
        Setting::query()->updateOrCreate(['key' => static::key()], ['value' => $this->toArray()]);

        app()->instance(static::class, $this);

        return $this;
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
     * by hand or by an older shape reads as what the property promises.
     */
    private static function coerce(ReflectionProperty $property, mixed $value): mixed
    {
        $type = $property->getType();

        if (! $type instanceof ReflectionNamedType || ! is_scalar($value)) {
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
     * A secret as it was saved, or blank for a value that was never set or
     * was sealed under a key this installation no longer has.
     */
    private static function reveal(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return '';
        }
    }
}
