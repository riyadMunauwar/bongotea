import { describe, expect, it } from 'vitest';
import {
    cappedCount,
    beyondFree,
    clampCount,
    isPackSoldOut,
    EMPTY_RETAIL_CART,
    onlyOrderablePacks,
    outsideLimits,
    parseRetailCart,
    retailLines,
    unorderableLines,
    withCount,
    type CartPack,
} from './retail-cart';

const pack = (id: number, isOrderable = true): CartPack => ({
    id,
    productId: 100 + id,
    productName: `Tea ${id}`,
    href: `/tea/${id}`,
    imageUrl: null,
    grams: 250,
    mrp: 30000,
    price: 27000,
    isOrderable,
    minGrams: null,
    maxGrams: null,
    freeGrams: null,
});

describe('Order Limits', () => {
    /** Assam in 100 g and 250 g Packs, its খুচরা limit 500 g to 1 kg. */
    const hundred = { ...pack(1), productId: 7, grams: 100, minGrams: 500, maxGrams: 1000 };
    const quarter = { ...pack(2), productId: 7, grams: 250, minGrams: 500, maxGrams: 1000 };
    const other = pack(3);
    const packs = [hundred, quarter, other];

    it('measures a tea on all its Packs together, however split', () => {
        expect(outsideLimits(retailLines({ 1: 1, 2: 1, 3: 1 }, packs))).toStrictEqual(new Map([[7, 'below']]));
        expect(outsideLimits(retailLines({ 1: 1, 2: 2 }, packs))).toStrictEqual(new Map());
        expect(outsideLimits(retailLines({ 1: 1, 2: 4 }, packs))).toStrictEqual(new Map([[7, 'above']]));
    });

    it('stops a Pack\'s count where the tea would pass its maximum, counting its other Packs', () => {
        expect(cappedCount({ 1: 5 }, packs, 2, 3)).toBe(2);
        expect(cappedCount({ 1: 5 }, packs, 2, 1)).toBe(1);
        expect(cappedCount({}, packs, 3, 50)).toBe(50);
    });

    it('never stops a count coming down, even above the maximum', () => {
        expect(cappedCount({ 2: 6 }, packs, 2, 5)).toBe(5);
        expect(cappedCount({ 2: 6 }, packs, 2, 7)).toBe(6);
    });
});

describe('স্টক শেষ', () => {
    /** Assam in 100 g and 250 g Packs, 600 g of it free to sell. */
    const hundred = { ...pack(1), productId: 7, grams: 100, freeGrams: 600 };
    const quarter = { ...pack(2), productId: 7, grams: 250, freeGrams: 600 };
    const other = pack(3);
    const packs = [hundred, quarter, other];

    it("is a counted tea's Pack heavier than what is free, and never one nobody counted", () => {
        expect(isPackSoldOut({ ...quarter, freeGrams: 249 })).toBe(true);
        expect(isPackSoldOut({ ...quarter, freeGrams: 250 })).toBe(false);
        expect(isPackSoldOut({ ...hundred, freeGrams: 249 })).toBe(false);
        expect(isPackSoldOut(other)).toBe(false);
    });

    it("stops a Pack's count at what is free, counting the tea's other Packs", () => {
        expect(cappedCount({}, packs, 2, 5)).toBe(2);
        expect(cappedCount({ 2: 2 }, packs, 1, 5)).toBe(1);
        expect(cappedCount({}, packs, 3, 50)).toBe(50);
    });

    it('takes the lower of what is free and the খুচরা maximum', () => {
        const limited = packs.map((each) => ({ ...each, maxGrams: each.productId === 7 ? 300 : null }));

        expect(cappedCount({}, limited, 1, 9)).toBe(3);
    });

    it('never stops a count coming down from above what is free', () => {
        expect(cappedCount({ 2: 4 }, packs, 2, 3)).toBe(3);
        expect(cappedCount({ 2: 4 }, packs, 2, 5)).toBe(4);
    });

    it('flags a tea whose Packs together hold more than is free', () => {
        expect(beyondFree(retailLines({ 1: 2, 2: 2, 3: 9 }, packs))).toStrictEqual(new Set([7]));
        expect(beyondFree(retailLines({ 1: 1, 2: 2 }, packs))).toStrictEqual(new Set());
    });
});

describe('clampCount', () => {
    it('keeps whole counts from nought to 99', () => {
        expect(clampCount(3)).toBe(3);
        expect(clampCount(-2)).toBe(0);
        expect(clampCount(500)).toBe(99);
        expect(clampCount(2.6)).toBe(3);
        expect(clampCount(Number.NaN)).toBe(0);
    });
});

describe('parseRetailCart', () => {
    it('reads what was stored', () => {
        expect(parseRetailCart('{"4":2,"9":1}')).toStrictEqual({ 4: 2, 9: 1 });
    });

    it('drops anything that is not a Pack id and a whole count', () => {
        expect(parseRetailCart('{"4":2,"x":2,"5":"3","6":0,"8":-1,"9":500}')).toStrictEqual({ 4: 2, 9: 99 });
    });

    it('starts empty when nothing or rubbish was stored', () => {
        expect(parseRetailCart(null)).toStrictEqual(EMPTY_RETAIL_CART);
        expect(parseRetailCart('not json')).toStrictEqual(EMPTY_RETAIL_CART);
        expect(parseRetailCart('[1,2]')).toStrictEqual(EMPTY_RETAIL_CART);
    });
});

describe('withCount', () => {
    it('adds a Pack and leaves the others', () => {
        expect(withCount({ 4: 2 }, 9, 1)).toStrictEqual({ 4: 2, 9: 1 });
    });

    it('changes a count', () => {
        expect(withCount({ 4: 2 }, 4, 5)).toStrictEqual({ 4: 5 });
    });

    it('removes a Pack set to nothing', () => {
        expect(withCount({ 4: 2, 9: 1 }, 4, 0)).toStrictEqual({ 9: 1 });
    });
});

describe('the Packs a buyer cannot order now', () => {
    const assam = pack(4);
    const switchedOff = pack(9, false);
    const cart = { 9: 1, 4: 2, 11: 3 };

    it('are left out of the priced lines, which keep the store\'s order', () => {
        expect(retailLines(cart, [assam, switchedOff])).toStrictEqual([{ pack: assam, count: 2 }]);
    });

    it('are flagged, the removed one by its id alone', () => {
        expect(unorderableLines(cart, [assam, switchedOff])).toStrictEqual([
            { packId: 9, pack: switchedOff, count: 1 },
            { packId: 11, pack: null, count: 3 },
        ]);
    });

    it('are dropped when the Cart is written back', () => {
        expect(onlyOrderablePacks(cart, [assam, switchedOff])).toStrictEqual({ 4: 2 });
    });
});
