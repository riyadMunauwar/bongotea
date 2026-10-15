/**
 * A bulk Cart's Contents (ADR-0029, ADR-0038): a list of lines,
 * `[{productId, packId, kilograms}]`, kept per Cart in this browser's
 * `localStorage` (`lib/contents.ts`) and nowhere else. Every view of one
 * Cart's Contents — its page, the header, a price calculator set to it —
 * reads and writes them through `useCart(cartId)`, so no two quantities can
 * disagree, and no two Carts' Contents meet.
 *
 * A line is one Product in one of its পাইকারি Packs (ADR-0036), so a tea may
 * be held in several, each Pack at most once: five kilograms in 1 kg Packs and
 * two in 500 g Packs are two lines. However a tea's Kilograms are split, they
 * are one tea's to its Tier, its Order Limit and its stock. The Pack sets the
 * packet count and never the price. Only lines holding Kilograms are kept.
 */

import { contentsStore, type ContentsKind } from './contents';
import { GRAMS_PER_KILOGRAM, gramsFromKilograms, packetsIn, wholeKilogramsIn, workableKilograms } from './grams';
import { priceCart, type PriceBook, type PricedLine, type ProductPrices } from './pricing';

export const MAX_KILOGRAMS = 999;

/** One line: a Product's whole Kilograms, one to 999, in one of its Packs, or null for the Product's default. */
export type CartEntry = Readonly<{ productId: number; packId: number | null; kilograms: number }>;

/** Every line, each Product + Pack at most once; a line holding nothing is absent. */
export type Cart = readonly CartEntry[];

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
 * and, for the tea's whole weight across its lines, whether it is outside the
 * tea's Order Limit and whether it is more than is free to sell, each of which
 * Checkout would refuse.
 */
export type BulkCartLine = { product: CartProduct; kilograms: number; pack: BulkPack | null; packets: number | null; limit: LimitBreach; isShort: boolean };

export const EMPTY_CART: Cart = Object.freeze([]);

/** Whole Kilograms from nought to 999, whatever was asked for. */
export function clampKilograms(kilograms: number): number {
    if (!Number.isFinite(kilograms)) {
        return 0;
    }

    return Math.min(MAX_KILOGRAMS, Math.max(0, Math.round(kilograms)));
}

/**
 * The Cart as stored, keeping only lines naming a Product and a whole number
 * of Kilograms, each Product + Pack once. A Cart stored from before a tea
 * could be held in several Packs — `{productId: {kilograms, packId}}`, or
 * bare Kilograms from before lines had Packs — reads as one line per Product.
 */
export function parseCart(raw: string | null): Cart {
    try {
        return cartFrom(raw === null ? null : JSON.parse(raw));
    } catch {
        return EMPTY_CART;
    }
}

/** One bulk Cart's Contents from what was stored, read as `parseCart()` reads it. */
export function cartFrom(stored: unknown): Cart {
    if (stored === null || typeof stored !== 'object') {
        return EMPTY_CART;
    }

    const candidates: unknown[] = Array.isArray(stored)
        ? stored
        : Object.entries(stored).map(([key, value]) => {
              const entry: unknown = typeof value === 'number' ? { kilograms: value } : value;

              return /^[1-9]\d*$/.test(key) && entry !== null && typeof entry === 'object' ? { ...entry, productId: Number(key) } : null;
          });

    const cart: CartEntry[] = [];

    for (const candidate of candidates) {
        if (candidate === null || typeof candidate !== 'object') {
            continue;
        }

        const { productId, packId, kilograms } = candidate as { productId?: unknown; packId?: unknown; kilograms?: unknown };

        if (!isWholeAboveNought(productId) || !isWholeAboveNought(kilograms)) {
            continue;
        }

        const entry = { productId, packId: isWholeAboveNought(packId) ? packId : null, kilograms: clampKilograms(kilograms) };

        if (!cart.some((held) => isSameLine(held, entry.productId, entry.packId))) {
            cart.push(entry);
        }
    }

    return cart;
}

function isWholeAboveNought(value: unknown): value is number {
    return typeof value === 'number' && Number.isInteger(value) && value > 0;
}

function isSameLine(entry: CartEntry, productId: number, packId: number | null): boolean {
    return entry.productId === productId && entry.packId === packId;
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

/** The most Kilograms of a tea the Cart may hold across its Packs: its পাইকারি maximum or the whole Kilograms free to sell, whichever is lower, and never past 999. */
export function mostKilograms(product: CartProduct): number {
    const free = product.freeGrams === null ? MAX_KILOGRAMS : wholeKilogramsIn(product.freeGrams);

    return Math.min(MAX_KILOGRAMS, free, product.maxKilograms ?? MAX_KILOGRAMS);
}

/**
 * The Kilograms a line's stepper may go to from what it holds now, given what
 * the tea's other Packs hold: stopped where the tea's whole weight reaches
 * `mostKilograms()`, but never stopped coming down, even from above it.
 */
export function cappedKilograms(product: CartProduct, kilograms: number, held = 0, elsewhere = 0): number {
    return kilograms <= held ? kilograms : Math.min(kilograms, Math.max(held, mostKilograms(product) - elsewhere));
}

/** Where a tea's Kilograms fall against its পাইকারি Order Limit. */
function limitBreachOf(product: CartProduct, kilograms: number): LimitBreach {
    if (product.minKilograms !== null && kilograms < product.minKilograms) {
        return 'below';
    }

    return product.maxKilograms !== null && kilograms > product.maxKilograms ? 'above' : null;
}

/** Sets a Product's Kilograms in one Pack, leaving its other Packs as they are; nought removes the line. */
export function withKilograms(cart: Cart, productId: number, packId: number | null, kilograms: number): Cart {
    const clamped = clampKilograms(kilograms);

    if (clamped === 0) {
        return cart.filter((entry) => !isSameLine(entry, productId, packId));
    }

    return cart.some((entry) => isSameLine(entry, productId, packId))
        ? cart.map((entry) => (isSameLine(entry, productId, packId) ? { ...entry, kilograms: clamped } : entry))
        : [...cart, { productId, packId, kilograms: clamped }];
}

/**
 * Sets a tea's Kilograms in one Pack — its default where none is named — as a
 * stepper on its Cart's page does: stopped where the tea's whole weight
 * reaches `mostKilograms()`, never stopped coming down.
 */
export function withCappedKilograms(cart: Cart, product: CartProduct, packId: number | null, kilograms: number): Cart {
    const chosenId = packFor(product, packId)?.id ?? null;
    const held = cart.find((entry) => isSameLine(entry, product.id, chosenId))?.kilograms ?? 0;
    const tea = cart.reduce((sum, entry) => sum + (entry.productId === product.id ? entry.kilograms : 0), 0);

    return withKilograms(cart, product.id, chosenId, cappedKilograms(product, kilograms, held, tea - held));
}

/**
 * Adds Kilograms of a tea to one Pack — its default where none is named — on
 * top of what that Pack holds, as an add from outside a Cart's page does
 * (ADR-0038): it never leaves less than was there, and stops at the same
 * limits as a stepper.
 */
export function withAddedKilograms(cart: Cart, product: CartProduct, packId: number | null, kilograms: number): Cart {
    const chosenId = packFor(product, packId)?.id ?? null;
    const held = cart.find((entry) => isSameLine(entry, product.id, chosenId))?.kilograms ?? 0;

    return withCappedKilograms(cart, product, chosenId, held + Math.max(0, kilograms));
}

/** The Pack a Product's line is supplied in: the one named while the Product still offers it, else its default, else its first. */
export function packFor(product: CartProduct, packId: number | null): BulkPack | null {
    return (
        product.packs.find((pack) => pack.id === packId) ??
        product.packs.find((pack) => pack.id === product.defaultPackId) ??
        product.packs[0] ??
        null
    );
}

/**
 * The lines the Cart is priced from, in catalogue order and each tea's in the
 * order of its Packs, each with its Pack and the packets its Kilograms make.
 * A line naming no Pack, or one its tea no longer offers, is supplied in the
 * default, joining whatever that already holds. A Product the store no longer
 * sends — a draft, or one taken out of the catalogue — and one not Available
 * now are left out, so neither is ever counted or ordered.
 */
export function cartLines(cart: Cart, products: CartProduct[]): BulkCartLine[] {
    return products
        .filter((product) => product.isAvailable)
        .flatMap((product) => {
            const held = new Map<number | null, number>();

            for (const entry of cart) {
                if (entry.productId === product.id) {
                    const packId = packFor(product, entry.packId)?.id ?? null;

                    held.set(packId, clampKilograms((held.get(packId) ?? 0) + entry.kilograms));
                }
            }

            const total = [...held.values()].reduce((sum, kilograms) => sum + kilograms, 0);
            const limit = limitBreachOf(product, total);
            const isShort = product.freeGrams !== null && gramsFromKilograms(total) > product.freeGrams;
            const packs: (BulkPack | null)[] = product.packs.length === 0 ? [null] : product.packs;

            return packs.flatMap((pack) => {
                const kilograms = held.get(pack?.id ?? null) ?? 0;

                return kilograms === 0
                    ? []
                    : [{ product, kilograms, pack, packets: pack === null ? null : packetsIn(gramsFromKilograms(kilograms), pack.grams), limit, isShort }];
            });
        });
}

/**
 * The Cart without lines for Products a buyer cannot have now, each line
 * naming the Pack it is supplied in. Written back whenever the Cart changes,
 * so a tea that went unavailable does not quietly return to the Cart, and the
 * total, when it comes back.
 */
export function onlyOrderable(cart: Cart, products: CartProduct[]): Cart {
    return cartLines(cart, products).map((line) => ({ productId: line.product.id, packId: line.pack?.id ?? null, kilograms: line.kilograms }));
}

/** A tea's line as the Cart would hold it, priced with the rest of the Cart, and the Cart's whole weight then. */
export type LineQuote = { line: BulkCartLine; priced: PricedLine; totalKilograms: number };

/**
 * What a tea's line in a Pack would be were the Cart to hold these Kilograms
 * there in place of what that Pack holds now, its other Packs left as they
 * are: its packets, its Tier Price for the whole Cart's weight with it, and
 * its line total. Null for a tea a buyer cannot have now, and for no
 * Kilograms.
 */
export function quoteLine(cart: Cart, products: CartProduct[], book: PriceBook, productId: number, kilograms: number, packId: number | null): LineQuote | null {
    const product = products.find((candidate) => candidate.id === productId);

    if (product === undefined) {
        return null;
    }

    const chosenId = packFor(product, packId)?.id ?? null;
    const lines = cartLines(withKilograms(onlyOrderable(cart, products), productId, chosenId, kilograms), products);
    const index = lines.findIndex((line) => line.product.id === productId && (line.pack?.id ?? null) === chosenId);

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

/** How the bulk Carts' Contents are kept: by Cart id under one key, the single Cart of before Carts were named moved into the starting one. */
export const BULK_CONTENTS: ContentsKind<Cart> = {
    key: 'bongotea.bulk-contents',
    legacyKey: 'bongotea.cart',
    from: cartFrom,
    isEmpty: (cart) => cart.length === 0,
};

/** Every bulk Cart's Contents in this browser. */
export const bulkContents = contentsStore(BULK_CONTENTS);
