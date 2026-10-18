import { describe, expect, it } from 'vitest';
import { cappedKilograms, cartLines, clampKilograms, firstKilograms, isBulkSoldOut, mostKilograms, EMPTY_CART, onlyOrderable, packFor, parseCart, priceSteps, quoteLine, rowAnchor, rowFromAnchor, withAddedKilograms, withCappedKilograms, withKilograms, type CartProduct } from './cart';
import type { Tier } from './pricing';

/** A tea in 1 kg bags, its default, unless given other পাইকারি Packs. */
const product = (id: number, isAvailable = true, tiers: Tier[] = [{ min: 1, max: null, price: 50000 }], packs = [{ id: id * 10, grams: 1000 }]): CartProduct => ({
    id,
    isAvailable,
    mrp: tiers[0].price,
    tiers,
    packs,
    defaultPackId: packs[0]?.id ?? null,
    minKilograms: null,
    maxKilograms: null,
    freeGrams: null,
});

/** A line holding Kilograms of a Product, in its default Pack unless one is named. */
const kg = (productId: number, kilograms: number, packId: number | null = null) => ({ productId, packId, kilograms });

/** Assam in 1 kg bags, its default, and in 500 g ones. */
const assam = product(3, true, undefined, [
    { id: 30, grams: 1000 },
    { id: 31, grams: 500 },
]);

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
    it('reads what was stored, a tea in several Packs and all', () => {
        expect(parseCart('[{"productId":3,"packId":30,"kilograms":5},{"productId":3,"packId":31,"kilograms":2},{"productId":7,"packId":null,"kilograms":4}]')).toStrictEqual([
            kg(3, 5, 30),
            kg(3, 2, 31),
            kg(7, 4),
        ]);
    });

    it('reads a Cart kept from before a tea could be in several Packs as one line each', () => {
        expect(parseCart('{"3":{"kilograms":8,"packId":31},"7":{"kilograms":4,"packId":null}}')).toStrictEqual([kg(3, 8, 31), kg(7, 4)]);
        expect(parseCart('{"3":8,"7":4}')).toStrictEqual([kg(3, 8), kg(7, 4)]);
    });

    it('drops anything that is not a Product id and a whole number of Kilograms, and any Pack that is not an id', () => {
        expect(parseCart('{"3":8,"x":2,"5":"9","6":0,"8":-1,"9":2000,"4":{"kilograms":2,"packId":"x"}}')).toStrictEqual([kg(3, 8), kg(4, 2), kg(9, 999)]);
        expect(parseCart('[{"productId":"3","kilograms":2},{"productId":3,"kilograms":0},{"productId":3,"kilograms":2,"packId":-1},1,null]')).toStrictEqual([kg(3, 2)]);
    });

    it('keeps the first of a Product + Pack stored twice', () => {
        expect(parseCart('[{"productId":3,"packId":30,"kilograms":5},{"productId":3,"packId":30,"kilograms":2}]')).toStrictEqual([kg(3, 5, 30)]);
    });

    it('starts empty when nothing or rubbish was stored', () => {
        expect(parseCart(null)).toStrictEqual(EMPTY_CART);
        expect(parseCart('not json')).toStrictEqual(EMPTY_CART);
        expect(parseCart('[1,2]')).toStrictEqual(EMPTY_CART);
    });
});

describe('withKilograms', () => {
    it('sets a line and leaves the others', () => {
        expect(withKilograms([kg(3, 8)], 7, null, 4)).toStrictEqual([kg(3, 8), kg(7, 4)]);
    });

    it('sets one Pack of a tea and leaves its other Packs', () => {
        expect(withKilograms([kg(3, 5, 30)], 3, 31, 2)).toStrictEqual([kg(3, 5, 30), kg(3, 2, 31)]);
        expect(withKilograms([kg(3, 5, 30), kg(3, 2, 31)], 3, 31, 4)).toStrictEqual([kg(3, 5, 30), kg(3, 4, 31)]);
    });

    it('removes a line set to nothing', () => {
        expect(withKilograms([kg(3, 8, 30), kg(3, 2, 31), kg(7, 4)], 3, 30, 0)).toStrictEqual([kg(3, 2, 31), kg(7, 4)]);
    });

    it('clamps what it is given', () => {
        expect(withKilograms(EMPTY_CART, 3, null, 5000)).toStrictEqual([kg(3, 999)]);
    });
});

describe('packFor', () => {
    const packed = product(3, true, undefined, [
        { id: 31, grams: 250 },
        { id: 32, grams: 400 },
    ]);

    it('takes the Pack named while the tea still offers it, else its default', () => {
        expect(packFor(packed, 32)).toStrictEqual({ id: 32, grams: 400 });
        expect(packFor(packed, 99)).toStrictEqual({ id: 31, grams: 250 });
        expect(packFor(packed, null)).toStrictEqual({ id: 31, grams: 250 });
        expect(packFor({ ...packed, defaultPackId: null }, null)).toStrictEqual({ id: 31, grams: 250 });
        expect(packFor({ ...packed, packs: [] }, null)).toBeNull();
    });
});

describe('cartLines', () => {
    it('prices only Products the store still sells and a buyer can have, in catalogue order', () => {
        const premium = product(3);
        const strong = product(7);
        const unavailable = product(9, false);

        const lines = cartLines([kg(7, 4), kg(3, 8), kg(9, 2), kg(11, 5)], [premium, strong, unavailable]);

        expect(lines).toStrictEqual([
            { product: premium, kilograms: 8, pack: { id: 30, grams: 1000 }, packets: 8, limit: null, isShort: false },
            { product: strong, kilograms: 4, pack: { id: 70, grams: 1000 }, packets: 4, limit: null, isShort: false },
        ]);
    });

    it('holds a tea in several Packs as a line each, in the order of its Packs', () => {
        const lines = cartLines([kg(3, 2, 31), kg(3, 5, 30)], [assam]);

        expect(lines.map((line) => [line.pack?.id, line.kilograms, line.packets])).toStrictEqual([
            [30, 5, 5],
            [31, 2, 4],
        ]);
    });

    it('supplies a line naming no Pack, or one no longer offered, in the default, added to what that holds', () => {
        expect(cartLines([kg(3, 2)], [assam])[0].pack?.id).toBe(30);
        expect(cartLines([kg(3, 5, 30), kg(3, 2), kg(3, 1, 99)], [assam]).map((line) => [line.pack?.id, line.kilograms])).toStrictEqual([[30, 8]]);
    });

    it('counts the packets a line makes in its Pack as it changes, and none where they do not divide', () => {
        const packed = product(3, true, undefined, [
            { id: 31, grams: 250 },
            { id: 32, grams: 400 },
        ]);

        expect(cartLines([kg(3, 2)], [packed])[0].packets).toBe(8);
        expect(cartLines([kg(3, 2, 32)], [packed])[0].packets).toBe(5);
        expect(cartLines([kg(3, 1, 32)], [packed])[0].packets).toBeNull();
    });
});

describe('Order Limits', () => {
    const limited = { ...product(3), minKilograms: 2, maxKilograms: 5 };

    it('flags a line below its পাইকারি minimum or above its maximum', () => {
        expect(cartLines([kg(3, 1)], [limited])[0].limit).toBe('below');
        expect(cartLines([kg(3, 2)], [limited])[0].limit).toBeNull();
        expect(cartLines([kg(3, 6)], [limited])[0].limit).toBe('above');
    });

    it('measures the tea\'s Kilograms across all its Packs', () => {
        const limitedAssam = { ...assam, minKilograms: 3, maxKilograms: 6 };

        expect(cartLines([kg(3, 2, 30), kg(3, 1, 31)], [limitedAssam]).map((line) => line.limit)).toStrictEqual([null, null]);
        expect(cartLines([kg(3, 5, 30), kg(3, 2, 31)], [limitedAssam]).map((line) => line.limit)).toStrictEqual(['above', 'above']);
        expect(cartLines([kg(3, 1, 30), kg(3, 1, 31)], [limitedAssam]).map((line) => line.limit)).toStrictEqual(['below', 'below']);
    });

    it('takes a missing end as no limit there', () => {
        expect(cartLines([kg(3, 999)], [{ ...product(3), minKilograms: 2 }])[0].limit).toBeNull();
        expect(cartLines([kg(3, 1)], [{ ...product(3), maxKilograms: 5 }])[0].limit).toBeNull();
    });

    it('stops a stepper at the maximum, but never stops it coming down', () => {
        expect(cappedKilograms(limited, 4)).toBe(4);
        expect(cappedKilograms(limited, 9)).toBe(5);
        expect(cappedKilograms(product(3), 9)).toBe(9);
        expect(cappedKilograms(limited, 1)).toBe(1);
    });

    it('stops a Pack\'s stepper where the tea\'s whole weight reaches the maximum', () => {
        expect(cappedKilograms(limited, 4, 0, 3)).toBe(2);
        expect(cappedKilograms(limited, 4, 1, 5)).toBe(1);
        expect(cappedKilograms(limited, 0, 1, 5)).toBe(0);
    });
});

describe('স্টক শেষ', () => {
    /** A tea with 2.5 kg free to sell. */
    const counted = { ...product(3), freeGrams: 2500 };

    it('is a counted tea with less than a Kilogram free, and never one nobody counted', () => {
        expect(isBulkSoldOut({ ...product(3), freeGrams: 999 })).toBe(true);
        expect(isBulkSoldOut({ ...product(3), freeGrams: 0 })).toBe(true);
        expect(isBulkSoldOut({ ...product(3), freeGrams: -400 })).toBe(true);
        expect(isBulkSoldOut({ ...product(3), freeGrams: 1000 })).toBe(false);
        expect(isBulkSoldOut(product(3))).toBe(false);
    });

    it('stops a stepper at the whole Kilograms free or the maximum, whichever is lower', () => {
        expect(mostKilograms(counted)).toBe(2);
        expect(mostKilograms({ ...counted, maxKilograms: 1 })).toBe(1);
        expect(mostKilograms({ ...counted, maxKilograms: 5 })).toBe(2);
        expect(mostKilograms({ ...product(3), freeGrams: -400 })).toBe(0);
        expect(mostKilograms(product(3))).toBe(999);
        expect(cappedKilograms(counted, 9)).toBe(2);
    });

    it('never stops a stepper coming down from above what is free', () => {
        expect(cappedKilograms(counted, 4, 5)).toBe(4);
        expect(cappedKilograms(counted, 6, 5)).toBe(5);
        expect(cappedKilograms({ ...product(3), freeGrams: 0 }, 1, 3)).toBe(1);
    });

    it('flags a line holding more than is free, the tea weighed across its Packs', () => {
        expect(cartLines([kg(3, 3)], [counted])[0].isShort).toBe(true);
        expect(cartLines([kg(3, 2)], [counted])[0].isShort).toBe(false);
        expect(cartLines([kg(3, 999)], [product(3)])[0].isShort).toBe(false);
        expect(cartLines([kg(3, 2, 30), kg(3, 1, 31)], [{ ...assam, freeGrams: 2500 }]).map((line) => line.isShort)).toStrictEqual([true, true]);
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
        expect(onlyOrderable([kg(3, 8, 30), kg(9, 2), kg(11, 5)], [product(3), product(9, false)])).toStrictEqual([kg(3, 8, 30)]);
    });

    it('names the Pack each line is supplied in', () => {
        expect(onlyOrderable([kg(3, 2), kg(3, 1, 31)], [assam])).toStrictEqual([kg(3, 2, 30), kg(3, 1, 31)]);
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

describe('quoteLine', () => {
    const bands = [{ min: 1, max: null, fee: 0 }];
    const premium = product(3, true, [
        { min: 1, max: 5, price: 62000 },
        { min: 6, max: null, price: 58900 },
    ], [{ id: 30, grams: 1000 }, { id: 31, grams: 250 }, { id: 32, grams: 1500 }]);
    const strong = product(7);

    it('prices the line as the Cart would hold it, from the Tier for the whole Cart with it', () => {
        const quote = quoteLine([kg(7, 4)], [premium, strong], { deliveryZones: [{ name: 'Everywhere', districts: [], everywhereElse: true, bands }] }, 3, 2, 31);

        expect(quote?.line.pack).toStrictEqual({ id: 31, grams: 250 });
        expect(quote?.line.packets).toBe(8);
        expect(quote?.priced.tierPrice).toBe(58900);
        expect(quote?.priced.lineTotal).toBe(117800);
        expect(quote?.totalKilograms).toBe(6);
    });

    it('stands in for what that Pack already holds, the tea\'s other Packs counted beside it', () => {
        const quote = quoteLine([kg(3, 9, 30), kg(3, 1, 31)], [premium], { deliveryZones: [{ name: 'Everywhere', districts: [], everywhereElse: true, bands }] }, 3, 2, 31);

        expect(quote?.totalKilograms).toBe(11);
        expect(quote?.line.pack?.id).toBe(31);
        expect(quote?.line.kilograms).toBe(2);
        expect(quote?.priced.tierPrice).toBe(58900);
    });

    it('gives no packets where the Pack cannot make the Kilograms exactly', () => {
        expect(quoteLine(EMPTY_CART, [premium], { deliveryZones: [{ name: 'Everywhere', districts: [], everywhereElse: true, bands }] }, 3, 2, 32)?.line.packets).toBeNull();
    });

    it('quotes nothing for a tea a buyer cannot have now, or for no Kilograms', () => {
        expect(quoteLine(EMPTY_CART, [product(3, false)], { deliveryZones: [{ name: 'Everywhere', districts: [], everywhereElse: true, bands }] }, 3, 2, 30)).toBeNull();
        expect(quoteLine(EMPTY_CART, [premium], { deliveryZones: [{ name: 'Everywhere', districts: [], everywhereElse: true, bands }] }, 3, 0, 30)).toBeNull();
    });
});

describe('firstKilograms', () => {
    it('starts at the least the tea may be ordered in that the Pack makes exactly', () => {
        expect(firstKilograms(product(3), 30)).toBe(1);
        expect(firstKilograms(product(3, true, undefined, [{ id: 32, grams: 1500 }]), 32)).toBe(3);
        expect(firstKilograms({ ...product(3, true, undefined, [{ id: 32, grams: 1500 }]), minKilograms: 4 }, 32)).toBe(6);
        expect(firstKilograms({ ...product(3), minKilograms: 5 }, 30)).toBe(5);
    });

    it('starts at one Kilogram where the tea has no Pack to make', () => {
        expect(firstKilograms(product(3, true, undefined, []), null)).toBe(1);
    });
});

describe('adding from outside a Cart\'s page', () => {
    it('adds on top of what the Pack already holds', () => {
        expect(withAddedKilograms([kg(3, 5, 30)], assam, 30, 3)).toStrictEqual([kg(3, 8, 30)]);
    });

    it('adds to the default Pack when none is named, leaving the tea\'s other Packs as they are', () => {
        expect(withAddedKilograms([kg(3, 2, 31)], assam, null, 3)).toStrictEqual([kg(3, 2, 31), kg(3, 3, 30)]);
    });

    it('stops where the tea\'s whole weight reaches its maximum, counting its other Packs', () => {
        const limited = { ...assam, maxKilograms: 10 };

        expect(withAddedKilograms([kg(3, 4, 30), kg(3, 4, 31)], limited, 30, 5)).toStrictEqual([kg(3, 6, 30), kg(3, 4, 31)]);
    });

    it('stops at what is free to sell', () => {
        expect(withAddedKilograms([kg(3, 5, 30)], { ...assam, freeGrams: 6500 }, 30, 3)).toStrictEqual([kg(3, 6, 30)]);
    });
});

describe('setting a Pack on a Cart\'s page', () => {
    it('replaces what the Pack held', () => {
        expect(withCappedKilograms([kg(3, 5, 30)], assam, 30, 3)).toStrictEqual([kg(3, 3, 30)]);
    });
});
