import { usePage } from '@inertiajs/react';
import { formatTaka } from '@/lib/bangla';

/**
 * Formats a price sent in minor units as taka in Bangla digits: 62000 → ৳৬২০.
 * The server says how many minor units make a taka, so no component assumes it.
 */
export function useTaka(): (minorUnits: number) => string {
    const { minorUnitsPerTaka } = usePage().props;

    return (minorUnits: number) => formatTaka(minorUnits / minorUnitsPerTaka);
}
