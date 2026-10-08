import { describe, expect, it } from 'vitest';
import file from '../../../tests/Fixtures/Pricing/examples.json';
import { type CartLine, type DiscountType, priceCart, type PriceBook, type ProductPrices } from './pricing';

type ExampleProduct = { id: number; regularPrice: number; discountType: string; tierDiscounts: Record<string, number> };

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
            const book: PriceBook = {
                tiers: merged.tiers,
                deliveryBands: merged.deliveryBands,
                minorUnitsPerTaka: merged.minorUnitsPerTaka,
            };

            const products = new Map<number, ProductPrices>(
                (merged.products as ExampleProduct[]).map((product) => [
                    product.id,
                    { ...product, discountType: product.discountType as DiscountType },
                ]),
            );

            const lines: CartLine[] = (merged.lines as ExampleLine[]).map((line) => ({
                product: products.get(line.productId)!,
                kilograms: line.kilograms,
                overridePrice: line.overridePrice ?? null,
            }));

            expect(priceCart(book, lines)).toStrictEqual(example.expected);
        });
    }
});
