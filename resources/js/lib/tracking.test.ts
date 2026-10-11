import { describe, expect, it } from 'vitest';
import { priceCart, type Tier } from './pricing';
import { beaconItems, cartMomentFor, momentSelection } from './tracking';

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
});
