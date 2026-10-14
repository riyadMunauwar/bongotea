import { describe, expect, it } from 'vitest';
import { cartLines, clampKilograms, EMPTY_CART, onlyOrderable, packFor, parseCart, priceSteps, rowAnchor, rowFromAnchor, withKilograms, withPack, type CartProduct } from './cart';
import type { Tier } from './pricing';

/** A tea in 1 kg bags, its default, unless given other পাইকারি Packs. */
const product = (id: number, isAvailable = true, tiers: Tier[] = [{ min: 1, max: null, price: 50000 }], packs = [{ id: id * 10, grams: 1000 }]): CartProduct => ({
    id,
    isAvailable,
    mrp: tiers[0].price,
    tiers,
    packs,
    defaultPackId: packs[0]?.id ?? null,
});

/** A line holding Kilograms in the Product's default Pack. */
const kg = (kilograms: number) => ({ kilograms, packId: null });

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
    it('reads what was stored, Packs and all', () => {
        expect(parseCart('{"3":{"kilograms":8,"packId":31},"7":{"kilograms":4,"packId":null}}')).toStrictEqual({ 3: { kilograms: 8, packId: 31 }, 7: kg(4) });
    });

    it('reads a line stored as bare Kilograms, from before lines had Packs, as the default Pack', () => {
        expect(parseCart('{"3":8,"7":4}')).toStrictEqual({ 3: kg(8), 7: kg(4) });
    });

    it('drops anything that is not a Product id and a whole number of Kilograms, and any Pack that is not an id', () => {
        expect(parseCart('{"3":8,"x":2,"5":"9","6":0,"8":-1,"9":2000,"4":{"kilograms":2,"packId":"x"}}')).toStrictEqual({ 3: kg(8), 4: kg(2), 9: kg(999) });
    });

    it('starts empty when nothing or rubbish was stored', () => {
        expect(parseCart(null)).toStrictEqual(EMPTY_CART);
        expect(parseCart('not json')).toStrictEqual(EMPTY_CART);
        expect(parseCart('[1,2]')).toStrictEqual(EMPTY_CART);
    });
});

describe('withKilograms', () => {
    it('sets a line and leaves the others', () => {
        expect(withKilograms({ 3: kg(8) }, 7, 4)).toStrictEqual({ 3: kg(8), 7: kg(4) });
    });

    it('keeps the Pack chosen for the line', () => {
        expect(withKilograms({ 3: { kilograms: 8, packId: 31 } }, 3, 2)).toStrictEqual({ 3: { kilograms: 2, packId: 31 } });
    });

    it('removes a line set to nothing', () => {
        expect(withKilograms({ 3: kg(8), 7: kg(4) }, 3, 0)).toStrictEqual({ 7: kg(4) });
    });

    it('clamps what it is given', () => {
        expect(withKilograms({}, 3, 5000)).toStrictEqual({ 3: kg(999) });
    });
});

describe('withPack', () => {
    it('chooses the Pack for a line holding Kilograms, and for nothing else', () => {
        expect(withPack({ 3: kg(8) }, 3, 31)).toStrictEqual({ 3: { kilograms: 8, packId: 31 } });
        expect(withPack({ 3: kg(8) }, 7, 71)).toStrictEqual({ 3: kg(8) });
    });
});

describe('packFor', () => {
    const assam = product(3, true, undefined, [
        { id: 31, grams: 250 },
        { id: 32, grams: 400 },
    ]);

    it('takes the Pack chosen while the tea still offers it, else its default', () => {
        expect(packFor(assam, 32)).toStrictEqual({ id: 32, grams: 400 });
        expect(packFor(assam, 99)).toStrictEqual({ id: 31, grams: 250 });
        expect(packFor(assam, null)).toStrictEqual({ id: 31, grams: 250 });
        expect(packFor({ ...assam, defaultPackId: null }, null)).toStrictEqual({ id: 31, grams: 250 });
        expect(packFor({ ...assam, packs: [] }, null)).toBeNull();
    });
});

describe('cartLines', () => {
    it('prices only Products the store still sells and a buyer can have, in catalogue order', () => {
        const premium = product(3);
        const strong = product(7);
        const unavailable = product(9, false);

        const lines = cartLines({ 7: kg(4), 3: kg(8), 9: kg(2), 11: kg(5) }, [premium, strong, unavailable]);

        expect(lines).toStrictEqual([
            { product: premium, kilograms: 8, pack: { id: 30, grams: 1000 }, packets: 8 },
            { product: strong, kilograms: 4, pack: { id: 70, grams: 1000 }, packets: 4 },
        ]);
    });

    it('counts the packets a line makes in its Pack as it changes, and none where they do not divide', () => {
        const assam = product(3, true, undefined, [
            { id: 31, grams: 250 },
            { id: 32, grams: 400 },
        ]);

        expect(cartLines({ 3: kg(2) }, [assam])[0].packets).toBe(8);
        expect(cartLines({ 3: { kilograms: 2, packId: 32 } }, [assam])[0].packets).toBe(5);
        expect(cartLines({ 3: { kilograms: 1, packId: 32 } }, [assam])[0].packets).toBeNull();
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
        expect(onlyOrderable({ 3: { kilograms: 8, packId: 30 }, 9: kg(2), 11: kg(5) }, [product(3), product(9, false)])).toStrictEqual({ 3: { kilograms: 8, packId: 30 } });
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
