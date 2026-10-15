/**
 * A retail Cart's Contents (ADR-0034, ADR-0038): `{packId: count}` kept per
 * Cart in this browser's `localStorage` (`lib/contents.ts`) beside the bulk
 * Carts' and never merged with them. Every view of one Cart's Contents — its
 * page, the pack chooser, the header's bag, the cart bar — reads and writes
 * them through `useRetailCart(cartId)`.
 *
 * Only lines holding Packs are kept.
 */

import type { LimitBreach } from './cart';
import { contentsStore, type ContentsKind } from './contents';
import { gramsOfPackets } from './grams';
import type { PackPrices, RetailLine } from './retail-pricing';

export const MAX_PACKS = 99;

/** Pack id → how many, one to 99; a Pack held nought times is absent. */
export type RetailCart = Readonly<Record<number, number>>;

/**
 * A Pack as the shared `retailPricing` prop sends it: its prices, what it is
 * of, whether a buyer can order it now, and its tea's খুচরা Order Limit in
 * grams — measured on every Pack of the tea together, null at an end with no
 * limit — and its tea's Free to Sell in grams, shared by every Pack of it,
 * null where nobody has counted it (ADR-0037).
 */
export type CartPack = PackPrices & {
    productId: number;
    productName: string;
    href: string;
    imageUrl: string | null;
    grams: number;
    isOrderable: boolean;
    minGrams: number | null;
    maxGrams: number | null;
    freeGrams: number | null;
};

export const EMPTY_RETAIL_CART: RetailCart = Object.freeze({});

/** A whole count from nought to 99, whatever was asked for. */
export function clampCount(count: number): number {
    if (!Number.isFinite(count)) {
        return 0;
    }

    return Math.min(MAX_PACKS, Math.max(0, Math.round(count)));
}

/** The retail Cart as stored, keeping only Pack ids held a whole number of times. */
export function parseRetailCart(raw: string | null): RetailCart {
    try {
        return retailCartFrom(raw === null ? null : JSON.parse(raw));
    } catch {
        return EMPTY_RETAIL_CART;
    }
}

/** One retail Cart's Contents from what was stored, read as `parseRetailCart()` reads it. */
export function retailCartFrom(stored: unknown): RetailCart {
    if (stored === null || typeof stored !== 'object' || Array.isArray(stored)) {
        return EMPTY_RETAIL_CART;
    }

    const cart: Record<number, number> = {};

    for (const [key, value] of Object.entries(stored)) {
        if (/^[1-9]\d*$/.test(key) && typeof value === 'number' && Number.isInteger(value) && value > 0) {
            cart[Number(key)] = clampCount(value);
        }
    }

    return cart;
}

export function withCount(cart: RetailCart, packId: number, count: number): RetailCart {
    const { [packId]: _replaced, ...rest } = cart;
    const clamped = clampCount(count);

    return clamped === 0 ? rest : { ...rest, [packId]: clamped };
}

/** One line of the retail Cart, with the Pack it names. */
export type HeldPack = { pack: CartPack; count: number };

/**
 * The lines the retail Cart is priced from, in the order the store sends its
 * Packs. A Pack the store no longer sends, or one that cannot be ordered
 * now, is left out, so neither is ever counted or ordered.
 */
export function retailLines(cart: RetailCart, packs: CartPack[]): (RetailLine & HeldPack)[] {
    return packs.filter((pack) => pack.isOrderable && (cart[pack.id] ?? 0) > 0).map((pack) => ({ pack, count: cart[pack.id] }));
}

/**
 * The teas whose Packs together weigh less than their খুচরা minimum or more
 * than their maximum, by Product id: 100 g and 250 g of one tea are 350 g of
 * it, however split. A tea within its limit is absent.
 */
export function outsideLimits(lines: HeldPack[]): Map<number, Exclude<LimitBreach, null>> {
    const weighed = new Map<number, { grams: number; pack: CartPack }>();

    for (const { pack, count } of lines) {
        weighed.set(pack.productId, { grams: (weighed.get(pack.productId)?.grams ?? 0) + gramsOfPackets(count, pack.grams), pack });
    }

    const outside = new Map<number, Exclude<LimitBreach, null>>();

    for (const [productId, { grams, pack }] of weighed) {
        if (pack.minGrams !== null && grams < pack.minGrams) {
            outside.set(productId, 'below');
        } else if (pack.maxGrams !== null && grams > pack.maxGrams) {
            outside.set(productId, 'above');
        }
    }

    return outside;
}

/**
 * The teas whose Packs together hold more than is free to sell, by Product
 * id, which Checkout would refuse. A tea nobody has counted never is.
 */
export function beyondFree(lines: HeldPack[]): Set<number> {
    const weighed = new Map<number, number>();

    for (const { pack, count } of lines) {
        weighed.set(pack.productId, (weighed.get(pack.productId) ?? 0) + gramsOfPackets(count, pack.grams));
    }

    return new Set(lines.filter(({ pack }) => pack.freeGrams !== null && (weighed.get(pack.productId) ?? 0) > pack.freeGrams).map(({ pack }) => pack.productId));
}

/**
 * Whether a Pack is স্টক শেষ: its tea counted, with less free to sell than
 * the Pack weighs. Worked out here and never stored, and never the same as
 * not orderable. A Pack of a tea nobody has counted never is.
 */
export function isPackSoldOut(pack: CartPack): boolean {
    return pack.freeGrams !== null && pack.freeGrams < pack.grams;
}

/**
 * The count a Pack's stepper may go to: stopped where its tea, with the other
 * Packs of it the Cart holds, would pass its খুচরা maximum or what is free to
 * sell, whichever is lower — but never stopped coming down, even from above it.
 */
export function cappedCount(cart: RetailCart, packs: CartPack[], packId: number, count: number): number {
    const pack = packs.find((candidate) => candidate.id === packId);
    const held = cart[packId] ?? 0;
    const ceilings = pack === undefined ? [] : [pack.maxGrams, pack.freeGrams].filter((grams) => grams !== null);

    if (pack === undefined || ceilings.length === 0 || count <= held) {
        return count;
    }

    const others = retailLines(cart, packs)
        .filter((line) => line.pack.productId === pack.productId && line.pack.id !== packId)
        .reduce((grams, line) => grams + gramsOfPackets(line.count, line.pack.grams), 0);

    return Math.min(count, Math.max(held, Math.floor((Math.min(...ceilings) - others) / pack.grams)));
}

/**
 * What the retail Cart holds that cannot be ordered now: a Pack switched off
 * or whose Product is unavailable, with what it was, and a Pack the store no
 * longer sends at all — removed, or its Product taken out of the catalogue —
 * known only by its id. Shown flagged, and left out of every figure.
 */
export function unorderableLines(cart: RetailCart, packs: CartPack[]): { packId: number; pack: CartPack | null; count: number }[] {
    const known = new Map(packs.map((pack) => [pack.id, pack]));

    return Object.entries(cart)
        .map(([id, count]) => ({ packId: Number(id), pack: known.get(Number(id)) ?? null, count }))
        .filter((line) => line.pack === null || !line.pack.isOrderable);
}

/**
 * The retail Cart without lines for Packs a buyer cannot order now. Written
 * back whenever the Cart changes, so a Pack that went unavailable does not
 * quietly return, and its price with it, when it comes back.
 */
export function onlyOrderablePacks(cart: RetailCart, packs: CartPack[]): RetailCart {
    return Object.fromEntries(retailLines(cart, packs).map((line) => [line.pack.id, line.count]));
}

/** How the retail Carts' Contents are kept: by Cart id under one key, the packet basket of before Carts were named moved into the starting one. */
export const RETAIL_CONTENTS: ContentsKind<RetailCart> = {
    key: 'bongotea.retail-contents',
    legacyKey: 'bongotea.retail-cart',
    from: retailCartFrom,
    isEmpty: (cart) => Object.keys(cart).length === 0,
};

/** Every retail Cart's Contents in this browser. */
export const retailContents = contentsStore(RETAIL_CONTENTS);
