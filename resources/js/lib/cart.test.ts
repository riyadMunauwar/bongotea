import { describe, expect, it } from 'vitest';
import { cartLines, clampKilograms, EMPTY_CART, onlyOrderable, parseCart, priceSteps, rowAnchor, rowFromAnchor, withKilograms, type CartProduct } from './cart';
import type { Tier } from './pricing';

const product = (id: number, isAvailable = true, tiers: Tier[] = [{ min: 1, max: null, price: 50000 }]): CartProduct => ({ id, isAvailable, tiers });

describe('clampKilograms', () => {
    it('keeps whole Kilograms from nought to 999', () => {
        expect(clampKilograms(12)).toBe(12);
        expect(clampKilograms(-3)).toBe(0);
        expect(clampKilograms(1200)).toBe(999);
        expect(clampKilograms(4.6)).toBe(5);
        expect(clampKilograms(Number.NaN)).toBe(0);
    });
});

describe('parseCart', () => {
    it('reads what was stored', () => {
        expect(parseCart('{"3":8,"7":4}')).toStrictEqual({ 3: 8, 7: 4 });
    });

    it('drops anything that is not a Product id and a whole number of Kilograms', () => {
        expect(parseCart('{"3":8,"x":2,"5":"9","6":0,"8":-1,"9":2000}')).toStrictEqual({ 3: 8, 9: 999 });
    });

    it('starts empty when nothing or rubbish was stored', () => {
        expect(parseCart(null)).toStrictEqual(EMPTY_CART);
        expect(parseCart('not json')).toStrictEqual(EMPTY_CART);
        expect(parseCart('[1,2]')).toStrictEqual(EMPTY_CART);
    });
});

describe('withKilograms', () => {
    it('sets a line and leaves the others', () => {
        expect(withKilograms({ 3: 8 }, 7, 4)).toStrictEqual({ 3: 8, 7: 4 });
    });

    it('removes a line set to nothing', () => {
        expect(withKilograms({ 3: 8, 7: 4 }, 3, 0)).toStrictEqual({ 7: 4 });
    });

    it('clamps what it is given', () => {
        expect(withKilograms({}, 3, 5000)).toStrictEqual({ 3: 999 });
    });
});

describe('cartLines', () => {
    it('prices only Products the store still sells and a buyer can have, in catalogue order', () => {
        const premium = product(3);
        const strong = product(7);
        const unavailable = product(9, false);

        const lines = cartLines({ 7: 4, 3: 8, 9: 2, 11: 5 }, [premium, strong, unavailable]);

        expect(lines).toStrictEqual([
            { product: premium, kilograms: 8 },
            { product: strong, kilograms: 4 },
        ]);
    });
});

describe('priceSteps', () => {
    const premium = product(3, true, [
        { min: 1, max: 5, price: 62000 },
        { min: 6, max: 10, price: 58900 },
        { min: 11, max: null, price: 55800 },
    ]);
    const strong = product(7, true, [
        { min: 1, max: 3, price: 46000 },
        { min: 4, max: null, price: 44000 },
    ]);

    it('breaks the weights wherever any of the Products gets cheaper, marking the one the Cart is in', () => {
        expect(priceSteps([premium, strong], 7)).toStrictEqual([
            { min: 1, max: 3, current: false },
            { min: 4, max: 5, current: false },
            { min: 6, max: 10, current: true },
            { min: 11, max: null, current: false },
        ]);
    });

    it('marks nothing for an empty Cart', () => {
        expect(priceSteps([strong], 0)).toStrictEqual([
            { min: 1, max: 3, current: false },
            { min: 4, max: null, current: false },
        ]);
    });
});

describe('onlyOrderable', () => {
    it('forgets lines for Products no longer sent or not Available, so they never come back unasked', () => {
        expect(onlyOrderable({ 3: 8, 9: 2, 11: 5 }, [product(3), product(9, false)])).toStrictEqual({ 3: 8 });
    });
});

describe('rowAnchor', () => {
    it('names a Bulk Order row in the address, and reads it back', () => {
        expect(rowAnchor(12)).toBe('tea-12');
        expect(rowFromAnchor(`#${rowAnchor(12)}`)).toBe(12);
        expect(rowFromAnchor('#products')).toBeNull();
        expect(rowFromAnchor('')).toBeNull();
    });
});
