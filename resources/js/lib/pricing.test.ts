import { describe, expect, it } from 'vitest';
import file from '../../../tests/Fixtures/Pricing/examples.json';
import { type CartLine, priceCart, type PriceBook, type ProductPrices } from './pricing';

type ExampleLine = { productId: number; kilograms: number; overridePrice?: number };

/**
 * The shared worked examples, priced by the TypeScript mirror. Pest runs the
 * same file against the PHP module (`tests/Unit/PricingExamplesTest.php`); a
 * new case belongs in the file, not here.
 */
describe('the shared pricing examples', () => {
    for (const example of file.examples) {
        const merged = { ...file.defaults, ...example };

        it(example.name, () => {
            const book: PriceBook = { deliveryBands: merged.deliveryBands };

            const products = new Map<number, ProductPrices>((merged.products as ProductPrices[]).map((product) => [product.id, product]));

            const lines: CartLine[] = (merged.lines as ExampleLine[]).map((line) => ({
                product: products.get(line.productId)!,
                kilograms: line.kilograms,
                overridePrice: line.overridePrice ?? null,
            }));

            expect(priceCart(book, lines)).toStrictEqual(example.expected);
        });
    }
});

describe('a Price Override', () => {
    const product: ProductPrices = { id: 1, mrp: 550, tiers: [{ min: 1, max: null, price: 500 }] };
    const book: PriceBook = { deliveryBands: [{ min: 1, max: null, fee: 0 }] };

    it('is refused above the MRP, since nothing is sold above it', () => {
        expect(() => priceCart(book, [{ product, kilograms: 1, overridePrice: 551 }])).toThrow();
    });

    it('is taken at the MRP, and saves nothing', () => {
        expect(priceCart(book, [{ product, kilograms: 2, overridePrice: 550 }]).savings).toBe(0);
    });
});
