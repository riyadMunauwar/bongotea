/**
 * The Cart (ADR-0029): `{productId: {kilograms, packId}}` kept in this
 * browser's `localStorage` and nowhere else. Every view of it — product-card
 * steppers, the header, the Bulk Order form, the price calculator — reads and
 * writes this one store through `useCart()`, so no two quantities can disagree.
 *
 * Each Product is held once, with its Kilograms and the one পাইকারি Pack they
 * are supplied in (ADR-0036): the buyer's choice, else the Product's default.
 * The Pack sets the packet count and never the price. Only lines holding
 * Kilograms are kept. Storage that refuses to be read or written (a private
 * window, blocked site data) leaves the Cart working for this visit, held in
 * memory.
 */

import { gramsFromKilograms, packetsIn } from './grams';
import type { ProductPrices } from './pricing';

export const MAX_KILOGRAMS = 999;

const STORAGE_KEY = 'bongotea.cart';

/** One Product's line: whole Kilograms, one to 999, and the Pack chosen for them, or null for the Product's default. */
export type CartEntry = Readonly<{ kilograms: number; packId: number | null }>;

/** Product id → its line; a Product holding none is absent. */
export type Cart = Readonly<Record<number, CartEntry>>;

/** A পাইকারি Pack a Product's Kilograms may be supplied in, known by its weight. */
export type BulkPack = { id: number; grams: number };

/** What the Cart needs to know of a Product: its Tiers, whether a buyer can have it now, and its পাইকারি Packs with the default. */
export type CartProduct = ProductPrices & { isAvailable: boolean; packs: BulkPack[]; defaultPackId: number | null };

/** A line the Cart is priced from, with the Pack it is supplied in and the packets that makes — null where the Pack cannot make the Kilograms exactly. */
export type BulkCartLine = { product: CartProduct; kilograms: number; pack: BulkPack | null; packets: number | null };

export const EMPTY_CART: Cart = Object.freeze({});

/** Whole Kilograms from nought to 999, whatever was asked for. */
export function clampKilograms(kilograms: number): number {
    if (!Number.isFinite(kilograms)) {
        return 0;
    }

    return Math.min(MAX_KILOGRAMS, Math.max(0, Math.round(kilograms)));
}

/**
 * The Cart as stored, keeping only Product ids holding a whole number of
 * Kilograms. A line stored as bare Kilograms, from before lines had Packs,
 * is read as the Product's default.
 */
export function parseCart(raw: string | null): Cart {
    let stored: unknown;

    try {
        stored = raw === null ? null : JSON.parse(raw);
    } catch {
        return EMPTY_CART;
    }

    if (stored === null || typeof stored !== 'object' || Array.isArray(stored)) {
        return EMPTY_CART;
    }

    const cart: Record<number, CartEntry> = {};

    for (const [key, value] of Object.entries(stored)) {
        if (!/^[1-9]\d*$/.test(key)) {
            continue;
        }

        const entry = (typeof value === 'number' ? { kilograms: value, packId: null } : value) as { kilograms?: unknown; packId?: unknown } | null;

        if (entry === null || typeof entry !== 'object' || !isWholeAboveNought(entry.kilograms)) {
            continue;
        }

        cart[Number(key)] = { kilograms: clampKilograms(entry.kilograms), packId: isWholeAboveNought(entry.packId) ? entry.packId : null };
    }

    return cart;
}

function isWholeAboveNought(value: unknown): value is number {
    return typeof value === 'number' && Number.isInteger(value) && value > 0;
}

/** Sets a Product's Kilograms, keeping the Pack chosen for it; nought removes the line. */
export function withKilograms(cart: Cart, productId: number, kilograms: number): Cart {
    const { [productId]: replaced, ...rest } = cart;
    const clamped = clampKilograms(kilograms);

    return clamped === 0 ? rest : { ...rest, [productId]: { kilograms: clamped, packId: replaced?.packId ?? null } };
}

/** Chooses the Pack a Product's Kilograms are supplied in; only a line holding Kilograms has one to choose. */
export function withPack(cart: Cart, productId: number, packId: number): Cart {
    const entry = cart[productId];

    return entry === undefined ? cart : { ...cart, [productId]: { ...entry, packId } };
}

/** The Pack a Product's line is supplied in: the one chosen while the Product still offers it, else its default, else its first. */
export function packFor(product: CartProduct, packId: number | null): BulkPack | null {
    return (
        product.packs.find((pack) => pack.id === packId) ??
        product.packs.find((pack) => pack.id === product.defaultPackId) ??
        product.packs[0] ??
        null
    );
}

/**
 * The lines the Cart is priced from, in catalogue order, each with its Pack
 * and the packets its Kilograms make. A Product the store no longer sends —
 * a draft, or one taken out of the catalogue — and one not Available now are
 * left out, so neither is ever counted or ordered.
 */
export function cartLines(cart: Cart, products: CartProduct[]): BulkCartLine[] {
    return products
        .filter((product) => product.isAvailable && (cart[product.id]?.kilograms ?? 0) > 0)
        .map((product) => {
            const { kilograms, packId } = cart[product.id];
            const pack = packFor(product, packId);

            return { product, kilograms, pack, packets: pack === null ? null : packetsIn(gramsFromKilograms(kilograms), pack.grams) };
        });
}

/**
 * The Cart without lines for Products a buyer cannot have now. Written back
 * whenever the Cart changes, so a tea that went unavailable does not quietly
 * return to the Cart, and the total, when it comes back.
 */
export function onlyOrderable(cart: Cart, products: CartProduct[]): Cart {
    return Object.fromEntries(cartLines(cart, products).map((line) => [line.product.id, cart[line.product.id]]));
}

/** The address fragment naming a Bulk Order row, which highlights it: `tea-12`. */
export function rowAnchor(productId: number): string {
    return `tea-${productId}`;
}

/** The Product a fragment such as `#tea-12` names, or null. */
export function rowFromAnchor(hash: string): number | null {
    const match = /^#tea-([1-9]\d*)$/.exec(hash);

    return match === null ? null : Number(match[1]);
}

/** One stretch of Cart weights within which no Product's price changes. */
export type PriceStep = { min: number; max: number | null; current: boolean };

/**
 * The Cart weights at which any of the Products gets cheaper, as stretches
 * of weight in order, with the one the Cart's total is in marked. Each
 * Product owns its Tiers (ADR-0031), so this is the one ladder every Product
 * given shares.
 */
export function priceSteps(products: ProductPrices[], totalKilograms: number): PriceStep[] {
    const starts = [...new Set([1, ...products.flatMap((product) => product.tiers.map((tier) => tier.min))])].sort((a, b) => a - b);

    return starts.map((min, index) => {
        const max = index + 1 < starts.length ? starts[index + 1] - 1 : null;

        return { min, max, current: totalKilograms >= min && (max === null || totalKilograms <= max) };
    });
}

const listeners = new Set<() => void>();

/** The Cart as last read or written; null when it must be read from storage again. */
let current: Cart | null = null;

/** This browser's localStorage, or null where it cannot be had. */
export function storage(): Storage | null {
    try {
        return typeof window === 'undefined' ? null : window.localStorage;
    } catch {
        return null;
    }
}

/** The Cart now. The same object until it changes, as `useSyncExternalStore` needs. */
export function readCart(): Cart {
    if (current === null) {
        try {
            current = parseCart(storage()?.getItem(STORAGE_KEY) ?? null);
        } catch {
            current = EMPTY_CART;
        }
    }

    return current;
}

export function writeCart(cart: Cart): void {
    current = cart;

    try {
        storage()?.setItem(STORAGE_KEY, JSON.stringify(cart));
    } catch {
        // Kept in memory for this visit; see the note at the top.
    }

    listeners.forEach((listener) => listener());
}

/** Calls the listener whenever the Cart changes, here or in another tab. */
export function subscribeToCart(listener: () => void): () => void {
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
