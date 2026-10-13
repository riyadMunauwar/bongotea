import { describe, expect, it } from 'vitest';
import file from '../../../tests/Fixtures/Pricing/retail-examples.json';
import { type PackPrices, priceRetailCart, type RetailLine } from './retail-pricing';

type ExampleLine = { packId: number; count: number; overridePrice?: number };

/**
 * The shared retail worked examples, priced by the TypeScript mirror. Pest
 * runs the same file against the PHP module (`tests/Unit/RetailPricingExamplesTest.php`);
 * a new case belongs in the file, not here.
 */
describe('the shared retail pricing examples', () => {
    for (const example of file.examples) {
        const merged: Omit<typeof file.defaults, "freeDeliveryFrom"> & { freeDeliveryFrom: number | null; lines: ExampleLine[] } = { ...file.defaults, ...example };

        it(example.name, () => {
            const packs = new Map<number, PackPrices>(merged.packs.map((pack) => [pack.id, pack]));

            const lines: RetailLine[] = merged.lines.map((line) => ({
                pack: packs.get(line.packId)!,
                count: line.count,
                overridePrice: line.overridePrice ?? null,
            }));

            expect(priceRetailCart({ deliveryCharge: merged.deliveryCharge, freeDeliveryFrom: merged.freeDeliveryFrom }, lines)).toStrictEqual(example.expected);
        });
    }
});

describe('a Price Override on a Pack', () => {
    const pack: PackPrices = { id: 1, mrp: 300, price: 270 };

    it('is refused above the MRP, since nothing is sold above it', () => {
        expect(() => priceRetailCart({ deliveryCharge: 60, freeDeliveryFrom: null }, [{ pack, count: 1, overridePrice: 301 }])).toThrow();
    });
});
