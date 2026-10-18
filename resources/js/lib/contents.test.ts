import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { BULK_CONTENTS, cartLines, type Cart, type CartProduct } from './cart';
import { contentsStore } from './contents';
import { priceCart } from './pricing';
import { RETAIL_CONTENTS } from './retail-cart';

/** This browser's localStorage, as a map the test can read. */
let stored: Map<string, string>;

beforeEach(() => {
    stored = new Map();

    vi.stubGlobal('window', {
        localStorage: {
            getItem: (key: string) => stored.get(key) ?? null,
            setItem: (key: string, value: string) => stored.set(key, value),
            removeItem: (key: string) => stored.delete(key),
        },
    });
});

afterEach(() => {
    vi.unstubAllGlobals();
});

/** Assam, ৳600 a kilogram up to five and ৳550 from six, in 1 kg bags. */
const assam: CartProduct = {
    id: 3,
    isAvailable: true,
    mrp: 65000,
    tiers: [
        { min: 1, max: 5, price: 60000 },
        { min: 6, max: null, price: 55000 },
    ],
    packs: [{ id: 30, grams: 1000 }],
    defaultPackId: 30,
    minKilograms: null,
    maxKilograms: null,
    freeGrams: null,
};

const kilograms = (count: number): Cart => [{ productId: 3, packId: 30, kilograms: count }];

describe('Contents per Cart', () => {
    it('keeps each Cart\'s Contents apart, two Carts of one kind never meeting', () => {
        const store = contentsStore(BULK_CONTENTS);

        store.write(7, kilograms(8));
        store.write(9, kilograms(3));

        expect(store.read(null)).toStrictEqual({ 7: kilograms(8), 9: kilograms(3) });

        store.write(9, []);

        expect(store.read(null)).toStrictEqual({ 7: kilograms(8) });
        expect(JSON.parse(stored.get('bongotea.bulk-contents') ?? '')).toStrictEqual({ 7: kilograms(8) });
    });

    it('prices each Cart from its own Kilograms alone', () => {
        const store = contentsStore(BULK_CONTENTS);

        store.write(7, kilograms(8));
        store.write(9, kilograms(3));

        const tierOf = (cartId: number) => priceCart({ deliveryZones: [{ name: 'Everywhere', districts: [], everywhereElse: true, bands: [{ min: 1, max: null, fee: 12000 }] }] }, cartLines(store.read(null)[cartId], [assam])).lines[0].tierPrice;

        expect(tierOf(7)).toBe(55000);
        expect(tierOf(9)).toBe(60000);
    });

    it('keeps a Cart\'s Contents whatever becomes of the Cart, until they are cleared', () => {
        stored.set('bongotea.bulk-contents', JSON.stringify({ 7: kilograms(2), 404: kilograms(5) }));

        expect(contentsStore(BULK_CONTENTS).read(null)[404]).toStrictEqual(kilograms(5));
    });

    it('reads stored nonsense as nothing held', () => {
        stored.set('bongotea.bulk-contents', '{"abc":[1],"7":"x"}');

        expect(contentsStore(BULK_CONTENTS).read(null)).toStrictEqual({});
    });
});

describe('what the browser held before Carts were named', () => {
    it('moves into the starting Cart of each kind once, and the old keys go', () => {
        stored.set('bongotea.cart', '{"3":{"kilograms":8,"packId":30}}');
        stored.set('bongotea.retail-cart', '{"4":2}');

        expect(contentsStore(BULK_CONTENTS).read(7)).toStrictEqual({ 7: kilograms(8) });
        expect(contentsStore(RETAIL_CONTENTS).read(8)).toStrictEqual({ 8: { 4: 2 } });
        expect(stored.has('bongotea.cart')).toBe(false);
        expect(stored.has('bongotea.retail-cart')).toBe(false);

        const again = contentsStore(BULK_CONTENTS);

        again.write(7, kilograms(1));

        expect(contentsStore(BULK_CONTENTS).read(7)).toStrictEqual({ 7: kilograms(1) });
    });

    it('never overwrites what the starting Cart already holds', () => {
        stored.set('bongotea.bulk-contents', JSON.stringify({ 7: kilograms(2) }));
        stored.set('bongotea.cart', '{"3":8}');

        expect(contentsStore(BULK_CONTENTS).read(7)).toStrictEqual({ 7: kilograms(2) });
    });

    it('waits while the starting Cart is not known', () => {
        stored.set('bongotea.cart', '{"3":8}');

        expect(contentsStore(BULK_CONTENTS).read(null)).toStrictEqual({});
        expect(stored.has('bongotea.cart')).toBe(true);
    });
});
