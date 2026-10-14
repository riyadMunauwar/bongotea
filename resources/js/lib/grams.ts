/**
 * The storefront's mirror of packing conversions (`App\Support\Inventory\Grams`),
 * so a packet count or a refused weight is shown as it changes.
 *
 * The server's figures are the only ones that count: this exists to display,
 * and Capture checks what it showed. The two are held to the same arithmetic
 * by the shared worked examples in `tests/Fixtures/Inventory/packing-examples.json`.
 * Grams are whole numbers everywhere; bulk kilograms are whole numbers.
 */

export const GRAMS_PER_KILOGRAM = 1000;

/** Kilograms as grams: 2 -> 2000. Rounded, so a typed 2.5 never loses a gram to floating point. */
export function gramsFromKilograms(kilograms: number): number {
    return Math.round(kilograms * GRAMS_PER_KILOGRAM);
}

/** Grams as kilograms, to the gram: 2500 -> 2.5. */
export function kilogramsFromGrams(grams: number): number {
    return grams / GRAMS_PER_KILOGRAM;
}

/** How many packets of a Pack these grams make, or null where they leave a remainder. Never rounded. */
export function packetsIn(grams: number, packGrams: number): number | null {
    ensurePackWeighsSomething(packGrams);

    return grams % packGrams === 0 ? grams / packGrams : null;
}

/** What so many packets of a Pack weigh: 10 × 250 g -> 2500. */
export function gramsOfPackets(packets: number, packGrams: number): number {
    ensurePackWeighsSomething(packGrams);

    return packets * packGrams;
}

/**
 * The whole kilograms nearest to these that a Pack makes exactly, for a
 * refusal to name: the one below and the one above, or the first two where
 * none lies below. Just these kilograms where the Pack already makes them.
 */
export function workableKilograms(kilograms: number, packGrams: number): number[] {
    ensurePackWeighsSomething(packGrams);

    if (!Number.isInteger(kilograms)) {
        throw new Error(`Bulk kilograms are whole numbers, not ${kilograms}.`);
    }

    const step = packGrams / greatestCommonDivisor(GRAMS_PER_KILOGRAM, packGrams);
    const below = Math.floor(kilograms / step) * step;

    if (below === kilograms) {
        return [kilograms];
    }

    return below <= 0 ? [step, 2 * step] : [below, below + step];
}

function ensurePackWeighsSomething(packGrams: number): void {
    if (packGrams < 1) {
        throw new Error(`A Pack must weigh at least a gram, not ${packGrams}.`);
    }
}

function greatestCommonDivisor(a: number, b: number): number {
    return b === 0 ? a : greatestCommonDivisor(b, a % b);
}
