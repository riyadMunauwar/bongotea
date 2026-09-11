<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What one Page says about one of a Provider's events: nothing, yes, or no.
 *
 * A Tracking Config stores the answer as a nullable boolean, where null is
 * "nothing said" — which is what lets a Provider gain a fifth event without a
 * data migration. This is that same answer as something a form can hold and a
 * Merchant can read, because a three-state control needs three values and a
 * checkbox has two.
 *
 * The mapping lives here and nowhere else, so the tab, the stored shape and
 * the reading a Moment takes cannot come to mean different things.
 */
enum TrackingChoice: string implements HasLabel
{
    /** Whatever the tracking settings say for this event. */
    case Inherit = 'inherit';

    case On = 'on';

    case Off = 'off';

    public function getLabel(): string
    {
        return match ($this) {
            self::Inherit => 'Use default',
            self::On => 'On',
            self::Off => 'Off',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = [];

        foreach (self::cases() as $choice) {
            $labels[$choice->value] = $choice->getLabel();
        }

        return $labels;
    }

    /**
     * The choice a stored switch holds. Absent, null and anything that is not
     * a decision all read as inherited, which is the same total reading the
     * Config itself takes.
     */
    public static function fromStored(mixed $stored): self
    {
        if (is_bool($stored)) {
            return $stored ? self::On : self::Off;
        }

        return (is_string($stored) ? self::tryFrom($stored) : null) ?? self::Inherit;
    }

    /**
     * This choice as a Config stores it: null where nothing was said, so a
     * switch nobody touched adds no key to the row.
     */
    public function stored(): ?bool
    {
        return match ($this) {
            self::Inherit => null,
            self::On => true,
            self::Off => false,
        };
    }
}
