import { afterEach, describe, expect, it, vi } from 'vitest';
import { priceCart, type Tier } from './pricing';
import type { CartPack } from './retail-cart';
import { priceRetailCart } from './retail-pricing';
import { addedToRetailCartId, beaconItems, cartMomentFor, momentSelection, packVariant, retailBeaconItems, retailMomentSelection } from './tracking';

const gold = { id: 12, mrp: 55000, tiers: [{ min: 1, max: 5, price: 55000 }, { min: 6, max: null, price: 43000 }] as Tier[] };
const green = { id: 14, mrp: 40000, tiers: [{ min: 1, max: null, price: 40000 }] as Tier[] };
const book = { deliveryBands: [{ min: 1, max: null, fee: 12000 }] };

describe('cartMomentFor', () => {
    it('starts a Cart when the first Product goes in, and reports it', () => {
        expect(cartMomentFor(null, true, 12, 'c1')).toStrictEqual({ record: { cart: 'c1', reported: [12] }, reports: true });
    });

    it('reports each Product once per Cart, however often it goes from none to some', () => {
        const first = cartMomentFor(null, true, 12, 'c1').record;
        const second = cartMomentFor(first, false, 14, 'c2');

        expect(second).toStrictEqual({ record: { cart: 'c1', reported: [12, 14] }, reports: true });
        expect(cartMomentFor(second.record, false, 12, 'c3')).toStrictEqual({ record: second.record, reports: false });
    });

    it('starts a new Cart once the old one was emptied, so the same Product is reported again', () => {
        const emptied = { cart: 'c1', reported: [12] };

        expect(cartMomentFor(emptied, true, 12, 'c2')).toStrictEqual({ record: { cart: 'c2', reported: [12] }, reports: true });
    });
});

describe('momentSelection', () => {
    it('is the priced lines in taka, worth their subtotal and no Delivery Charge', () => {
        const priced = priceCart(book, [
            { product: gold, kilograms: 4 },
            { product: green, kilograms: 2 },
        ]);

        expect(momentSelection(priced, 100)).toStrictEqual({
            value: 2520,
            count: 6,
            cart: 'bulk',
            lines: [
                { id: '12', quantity: 4, unitPrice: 430 },
                { id: '14', quantity: 2, unitPrice: 400 },
            ],
        });
    });
});

describe('beaconItems', () => {
    it('names each Product and its Kilograms, and nothing about price', () => {
        const priced = priceCart(book, [
            { product: gold, kilograms: 4 },
            { product: green, kilograms: 2 },
        ]);

        expect(beaconItems(priced)).toBe('12:4,14:2');
    });

    it('names a tea in several Packs once, its Kilograms added up', () => {
        const priced = priceCart(book, [
            { product: gold, kilograms: 4 },
            { product: green, kilograms: 2 },
            { product: gold, kilograms: 3 },
        ]);

        expect(beaconItems(priced)).toBe('12:7,14:2');
        expect(momentSelection(priced, 100).lines).toStrictEqual([
            { id: '12', quantity: 7, unitPrice: 430 },
            { id: '14', quantity: 2, unitPrice: 400 },
        ]);
    });
});

describe('a Pack', () => {
    const quarter: CartPack = { id: 7, productId: 12, productName: 'Gold', href: '/tea/gold', imageUrl: null, grams: 250, mrp: 30000, price: 27000, isOrderable: true, minGrams: null, maxGrams: null, freeGrams: null };
    const retail = { deliveryCharge: 6000, freeDeliveryFrom: null };

    it('is reported as its Product, its count the quantity, its Pack Price the price and its weight the variant, from the retail Cart', () => {
        const priced = priceRetailCart(retail, [{ pack: quarter, count: 3 }]);

        expect(retailMomentSelection({ cart: 'retail', priced, packs: [quarter] }, 100)).toStrictEqual({
            value: 810,
            count: 3,
            cart: 'retail',
            lines: [{ id: '12', quantity: 3, unitPrice: 270, variant: '250 g' }],
        });
    });

    it('names itself and its count to the beacon, and nothing about price', () => {
        expect(retailBeaconItems(priceRetailCart(retail, [{ pack: quarter, count: 3 }]))).toBe('7:3');
    });

    it('is named by its weight the way the server names it', () => {
        expect([packVariant(250), packVariant(1000), packVariant(1500)]).toStrictEqual(['250 g', '1 kg', '1.5 kg']);
    });
});

describe('addedToRetailCartId', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('reports a Pack once per basket, under an id of its own, leaving the bulk record alone', () => {
        const stored = new Map<string, string>([['bongotea.cart-moments', '{"cart":"b1","reported":[7]}']]);

        vi.stubGlobal('window', { localStorage: { getItem: (key: string) => stored.get(key) ?? null, setItem: (key: string, value: string) => stored.set(key, value) } });

        const first = addedToRetailCartId(true, 7);

        expect(first).toMatch(/-pack-7$/);
        expect(addedToRetailCartId(false, 7)).toBeNull();
        expect(stored.get('bongotea.cart-moments')).toBe('{"cart":"b1","reported":[7]}');
    });
});
