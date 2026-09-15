<?php

namespace App\Support\Settings;

/**
 * What the Merchant calls thin cover.
 *
 * One property, and deliberately one. Days of Cover keeps up on its own as a
 * campaign moves the rate a Product goes out at, so the only thing left for
 * anybody to decide is how much warning they want — and one number meaning
 * the same thing across the whole catalogue is what makes a threshold per
 * Product unnecessary, which is the point of measuring cover in days at all.
 *
 * The span the rate is read over is *not* here. It is a constant on
 * `DaysOfCover`, because it is not a preference: a Merchant moving it would
 * be changing what velocity means rather than what they want telling about,
 * and a Merchant with two velocities is a Merchant with two answers.
 */
class InventorySettings extends SettingsGroup
{
    /**
     * Days of Cover under which a Product goes on the to-do list.
     *
     * A floor and never a target: the figure itself is drawn for every
     * Product whatever this says, and this decides only which of them
     * somebody is asked to act on.
     */
    public int $thinCoverDays;

    public function __construct()
    {
        $this->thinCoverDays = (int) config('giopages.inventory.thin_cover_days');
    }

    public static function key(): string
    {
        return 'inventory';
    }

    /**
     * A threshold of nothing is the warning switched off, silently — the same
     * failure `TrackingSettings` guards its beacon limit against, and guarded
     * the same way.
     *
     * `coerce()` reads a stored null on an int property as zero, so a row
     * somebody hand-edited, or one written before this property existed, would
     * leave every Product's cover above the line for ever and a Merchant
     * reading an empty to-do list as an installation with nothing to buy. A
     * value below a day reads as the threshold this group already has, which
     * on a fresh group is the config default. The page's own field refuses one
     * up front; this is for the row that never went through it.
     *
     * @param  array<string, mixed>  $values
     */
    public function with(array $values): static
    {
        $group = parent::with($values);

        if ($group->thinCoverDays < 1) {
            $group->thinCoverDays = $this->thinCoverDays;
        }

        return $group;
    }
}
