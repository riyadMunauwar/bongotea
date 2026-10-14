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

import { GRAMS_PER_KILOGRAM, gramsFromKilograms, packetsIn, wholeKilogramsIn, workableKilograms } from './grams';
import { priceCart, type PriceBook, type PricedLine, type ProductPrices } from './pricing';

export const MAX_KILOGRAMS = 999;

const STORAGE_KEY = 'bongotea.cart';

/** One Product's line: whole Kilograms, one to 999, and the Pack chosen for them, or null for the Product's default. */
export type CartEntry = Readonly<{ kilograms: number; packId: number | null }>;

/** Product id → its line; a Product holding none is absent. */
export type Cart = Readonly<Record<number, CartEntry>>;

/** A পাইকারি Pack a Product's Kilograms may be supplied in, known by its weight. */
export type BulkPack = { id: number; grams: number };

/**
 * What the Cart needs to know of a Product: its Tiers, whether a buyer can
 * have it now, its পাইকারি Packs with the default, its পাইকারি Order Limit
 * in whole Kilograms, null at an end with no limit, and its Free to Sell in
 * grams, null where nobody has counted it (ADR-0037).
 */
export type CartProduct = ProductPrices & {
    isAvailable: boolean;
    packs: BulkPack[];
    defaultPackId: number | null;
    minKilograms: number | null;
    maxKilograms: number | null;
    freeGrams: number | null;
};

/** Where a tea's whole weight in the Cart falls against its Order Limit: short of it, past it, or within it. */
export type LimitBreach = 'below' | 'above' | null;

/**
 * A line the Cart is priced from, with the Pack it is supplied in and the
 * packets that makes — null where the Pack cannot make the Kilograms exactly —
 * whether it is outside the tea's Order Limit, and whether it holds more than
 * is free to sell, each of which Checkout would refuse.
 */
export type BulkCartLine = { product: CartProduct; kilograms: number; pack: BulkPack | null; packets: number | null; limit: LimitBreach; isShort: boolean };

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

/**
 * Whether a tea is স্টক শেষ পাইকারি: counted, with less free to sell than one
 * Kilogram, the least anybody could order of it. Worked out here and never
 * stored, and never the same as not Available. A tea nobody has counted
 * never is.
 */
export function isBulkSoldOut(product: CartProduct): boolean {
    return product.freeGrams !== null && product.freeGrams < GRAMS_PER_KILOGRAM;
}

/** The most Kilograms of a tea the Cart may hold: its পাইকারি maximum or the whole Kilograms free to sell, whichever is lower, and never past 999. */
export function mostKilograms(product: CartProduct): number {
    const free = product.freeGrams === null ? MAX_KILOGRAMS : wholeKilogramsIn(product.freeGrams);

    return Math.min(MAX_KILOGRAMS, free, product.maxKilograms ?? MAX_KILOGRAMS);
}

/**
 * The Kilograms a stepper may go to from what the Cart holds now: stopped at
 * `mostKilograms()`, but never stopped coming down, even from above it. A
 * Product holds one line, so the line is its whole weight in the Cart.
 */
export function cappedKilograms(product: CartProduct, kilograms: number, held = 0): number {
    return kilograms <= held ? kilograms : Math.min(kilograms, Math.max(held, mostKilograms(product)));
}

/** Where a tea's Kilograms fall against its পাইকারি Order Limit. */
function limitBreachOf(product: CartProduct, kilograms: number): LimitBreach {
    if (product.minKilograms !== null && kilograms < product.minKilograms) {
        return 'below';
    }

    return product.maxKilograms !== null && kilograms > product.maxKilograms ? 'above' : null;
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

            return {
                product,
                kilograms,
                pack,
                packets: pack === null ? null : packetsIn(gramsFromKilograms(kilograms), pack.grams),
                limit: limitBreachOf(product, kilograms),
                isShort: product.freeGrams !== null && gramsFromKilograms(kilograms) > product.freeGrams,
            };
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

/** A tea's line as the Cart would hold it, priced with the rest of the Cart, and the Cart's whole weight then. */
export type LineQuote = { line: BulkCartLine; priced: PricedLine; totalKilograms: number };

/**
 * What a tea's line would be were the Cart to hold these Kilograms of it in
 * this Pack in place of what it holds now: its packets, its Tier Price for
 * the whole Cart's weight with it, and its line total. Null for a tea a buyer
 * cannot have now, and for no Kilograms.
 */
export function quoteLine(cart: Cart, products: CartProduct[], book: PriceBook, productId: number, kilograms: number, packId: number | null): LineQuote | null {
    const quoted = withKilograms(cart, productId, kilograms);
    const lines = cartLines(packId === null ? quoted : withPack(quoted, productId, packId), products);
    const index = lines.findIndex((line) => line.product.id === productId);

    if (index === -1) {
        return null;
    }

    const priced = priceCart(book, lines);

    return { line: lines[index], priced: priced.lines[index], totalKilograms: priced.totalKilograms };
}

/** The Kilograms a buyer starts from in a Pack: the least the tea may be ordered in, at least one, that the Pack makes exactly. */
export function firstKilograms(product: CartProduct, packId: number | null): number {
    const least = Math.max(1, product.minKilograms ?? 1);
    const pack = packFor(product, packId);

    if (pack === null) {
        return least;
    }

    return workableKilograms(least, pack.grams).find((kilograms) => kilograms >= least) ?? least;
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
