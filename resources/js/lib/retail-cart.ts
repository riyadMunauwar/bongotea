/**
 * The retail Cart, the খুচরা কার্ট (ADR-0034): `{packId: count}` kept in this
 * browser's `localStorage` beside the bulk Cart (`lib/cart.ts`) and never
 * merged with it, under a key of its own. Every view of it — the pack
 * chooser, the header's bag, the packet basket page, the cart bar — reads and
 * writes this one store through `useRetailCart()`.
 *
 * Only lines holding Packs are kept. Storage that refuses to be read or
 * written leaves the Cart working for this visit, held in memory.
 */

import { storage } from './cart';
import type { PackPrices, RetailLine } from './retail-pricing';

export const MAX_PACKS = 99;

const STORAGE_KEY = 'bongotea.retail-cart';

/** Pack id → how many, one to 99; a Pack held nought times is absent. */
export type RetailCart = Readonly<Record<number, number>>;

/** A Pack as the shared `retailPricing` prop sends it: its prices, what it is of, and whether a buyer can order it now. */
export type CartPack = PackPrices & {
    productId: number;
    productName: string;
    href: string;
    imageUrl: string | null;
    grams: number;
    isOrderable: boolean;
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
    let stored: unknown;

    try {
        stored = raw === null ? null : JSON.parse(raw);
    } catch {
        return EMPTY_RETAIL_CART;
    }

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

const listeners = new Set<() => void>();

/** The retail Cart as last read or written; null when it must be read from storage again. */
let current: RetailCart | null = null;

/** The retail Cart now. The same object until it changes, as `useSyncExternalStore` needs. */
export function readRetailCart(): RetailCart {
    if (current === null) {
        try {
            current = parseRetailCart(storage()?.getItem(STORAGE_KEY) ?? null);
        } catch {
            current = EMPTY_RETAIL_CART;
        }
    }

    return current;
}

export function writeRetailCart(cart: RetailCart): void {
    current = cart;

    try {
        storage()?.setItem(STORAGE_KEY, JSON.stringify(cart));
    } catch {
        // Kept in memory for this visit; see the note at the top.
    }

    listeners.forEach((listener) => listener());
}

/** Calls the listener whenever the retail Cart changes, here or in another tab. */
export function subscribeToRetailCart(listener: () => void): () => void {
    const fromAnotherTab = (event: StorageEvent) => {
        if (event.key === STORAGE_KEY || event.key === null) {
            current = null;
            listener();
        }
    };

    listeners.add(listener);
    window.addEventListener('storage', fromAnotherTab);

    return () => {
        listeners.delete(listener);
        window.removeEventListener('storage', fromAnotherTab);
    };
}
