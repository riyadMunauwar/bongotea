/**
 * The Cart (ADR-0029): `{productId: kilograms}` kept in this browser's
 * `localStorage` and nowhere else. Every view of it — product-card steppers,
 * the header, the Bulk Order form, the price calculator — reads and writes
 * this one store through `useCart()`, so no two quantities can disagree.
 *
 * Only lines holding Kilograms are kept. Storage that refuses to be read or
 * written (a private window, blocked site data) leaves the Cart working for
 * this visit, held in memory.
 */

import type { CartLine, ProductPrices } from './pricing';

export const MAX_KILOGRAMS = 999;

const STORAGE_KEY = 'bongotea.cart';

/** Product id → whole Kilograms, one to 999; a Product holding none is absent. */
export type Cart = Readonly<Record<number, number>>;

/** What the Cart needs to know of a Product: its Tiers, and whether a buyer can have it now. */
export type CartProduct = ProductPrices & { isAvailable: boolean };

export const EMPTY_CART: Cart = Object.freeze({});

/** Whole Kilograms from nought to 999, whatever was asked for. */
export function clampKilograms(kilograms: number): number {
    if (!Number.isFinite(kilograms)) {
        return 0;
    }

    return Math.min(MAX_KILOGRAMS, Math.max(0, Math.round(kilograms)));
}

/** The Cart as stored, keeping only Product ids holding a whole number of Kilograms. */
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

    const cart: Record<number, number> = {};

    for (const [key, value] of Object.entries(stored)) {
        if (/^[1-9]\d*$/.test(key) && typeof value === 'number' && Number.isInteger(value) && value > 0) {
            cart[Number(key)] = clampKilograms(value);
        }
    }

    return cart;
}

export function withKilograms(cart: Cart, productId: number, kilograms: number): Cart {
    const { [productId]: _replaced, ...rest } = cart;
    const clamped = clampKilograms(kilograms);

    return clamped === 0 ? rest : { ...rest, [productId]: clamped };
}

/**
 * The lines the Cart is priced from, in catalogue order. A Product the store
 * no longer sends — a draft, or one taken out of the catalogue — and one not
 * Available now are left out, so neither is ever counted or ordered.
 */
export function cartLines(cart: Cart, products: CartProduct[]): CartLine[] {
    return products
        .filter((product) => product.isAvailable && (cart[product.id] ?? 0) > 0)
        .map((product) => ({ product, kilograms: cart[product.id] }));
}

/**
 * The Cart without lines for Products a buyer cannot have now. Written back
 * whenever the Cart changes, so a tea that went unavailable does not quietly
 * return to the Cart, and the total, when it comes back.
 */
export function onlyOrderable(cart: Cart, products: CartProduct[]): Cart {
    return Object.fromEntries(cartLines(cart, products).map((line) => [line.product.id, line.kilograms]));
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
