<?php

namespace App\Filament\Widgets\Concerns;

/**
 * A count's change against the comparison window, as a table cell says it:
 * "12% up", "5% down", "No change", or "Up from none" where the comparison
 * had nothing — there is no percentage of nothing. The wording is the Overview
 * tiles', shortened for a column.
 */
trait DescribesChange
{
    protected function change(int $current, int $previous): string
    {
        if ($current === $previous) {
            return 'No change';
        }

        if ($previous === 0) {
            return 'Up from none';
        }

        $change = ($current - $previous) / $previous * 100;

        return number_format(abs($change)).'% '.($change > 0 ? 'up' : 'down');
    }

    protected function percent(?float $rate): ?string
    {
        return $rate === null ? null : number_format($rate * 100, 1).'%';
    }
}
