import { usePage } from '@inertiajs/react';
import { useMemo, useSyncExternalStore } from 'react';
import {
    bulkContents,
    cartLines,
    EMPTY_CART,
    isBulkSoldOut,
    MAX_KILOGRAMS,
    mostKilograms,
    onlyOrderable,
    packFor,
    priceSteps,
    quoteLine,
    withCappedKilograms,
    type BulkCartLine,
    type Cart,
    type CartProduct,
    type LineQuote,
    type PriceStep,
} from './cart';
import type { ContentsByCart } from './contents';
import { cartFrom, type MomentCart } from './tracking';
import { priceCart, type PricedCart } from './pricing';
import { useMoments, type UseMoments } from './use-moments';

/** What the server draws: no Contents in any Cart. */
const NO_CONTENTS: ContentsByCart<Cart> = Object.freeze({});

export type UseCart = {
    /** The Cart these Contents are kept in; null for none. */
    cartId: number | null;
    /** The Kilograms of a Product in one of its Packs, its default where none is named; nought for one a buyer cannot have now. */
    kilogramsOf: (productId: number, packId?: number | null) => number;
    /** A Product's whole Kilograms across its Packs. */
    teaKilogramsOf: (productId: number) => number;
    /** A Product's line in one of its Packs, its default where none is named, with its packet count; null while it holds no Kilograms. */
    lineOf: (productId: number, packId?: number | null) => BulkCartLine | null;
    /** The most Kilograms a Pack's stepper may reach, its default's where none is named: the tea's পাইকারি maximum or what is free to sell, whichever is lower, else 999, less what its other Packs hold. */
    mostKilogramsOf: (productId: number, packId?: number | null) => number;
    /** Whether a counted Product has less than a Kilogram free to sell: স্টক শেষ পাইকারি, though still Available. */
    isSoldOut: (productId: number) => boolean;
    /** Sets a Product's Kilograms in one of its Packs, its default where none is named, clamped to 0–999 and stopped where the tea's whole weight reaches its পাইকারি maximum or what is free to sell, but never stopped coming down; refused for a Product a buyer cannot have now. The tea from none to some is an Added to Cart. */
    set: (productId: number, kilograms: number, packId?: number | null) => void;
    /** A Product's line were the Cart to hold these Kilograms of it in this Pack, priced with the rest of the Cart; null for one a buyer cannot have now. */
    quote: (productId: number, kilograms: number, packId: number | null) => LineQuote | null;
    clear: () => void;
    /** The lines, in catalogue order, a tea in several Packs as a line each. */
    lines: BulkCartLine[];
    /** Every figure the Cart shows, worked out afresh as it changes. */
    priced: PricedCart;
    isEmpty: boolean;
    /** Whether a line's Kilograms are not a whole number of its Pack, which Checkout would refuse. */
    hasUnpackable: boolean;
    /** Whether a line is outside its tea's পাইকারি Order Limit, which Checkout would refuse. */
    hasOutsideLimit: boolean;
    /** Whether a line holds more than is free to sell, which Checkout would refuse. */
    hasShort: boolean;
    /** The ladder of weights for the teas in the Cart, or for every tea a buyer can have while it is empty. */
    steps: PriceStep[];
    /** Every published Product the page was sent, in catalogue order. */
    products: CartProduct[];
};

/**
 * One bulk Cart's Contents, priced from them alone (ADR-0038). Every view of
 * a Cart's Contents goes through here, so its page, the header and a price
 * calculator set to it all show the same Kilograms, Packs and figures, and
 * never another Cart's. A stepper that names no Pack — a card's, the price
 * calculator's — is the tea's default Pack's. No Cart (null) holds nothing
 * and takes nothing. The server draws empty Contents — it cannot see the
 * browser's storage — and the stored ones appear as the page comes alive.
 */
export function useCart(cartId: number | null): UseCart {
    const { carts, pricing, startingCarts } = usePage().props;
    const contents = useSyncExternalStore(bulkContents.subscribe, () => bulkContents.read(startingCarts.bulk), () => NO_CONTENTS);
    const cart = (cartId === null ? undefined : contents[cartId]) ?? EMPTY_CART;
    const moments = useMoments();

    return useMemo(() => {
        const lines = cartLines(cart, pricing.products);
        const orderable = pricing.products.filter((product) => product.isAvailable);
        const book = { deliveryBands: pricing.deliveryBands };
        const productOf = (productId: number) => orderable.find((candidate) => candidate.id === productId);
        const packIdOf = (product: CartProduct, packId: number | null) => packFor(product, packId)?.id ?? null;
        const lineIn = (productId: number, packId: number | null) => {
            const product = productOf(productId);

            return product === undefined ? null : (lines.find((line) => line.product.id === productId && (line.pack?.id ?? null) === packIdOf(product, packId)) ?? null);
        };
        const teaKilogramsOf = (productId: number) => lines.reduce((sum, line) => sum + (line.product.id === productId ? line.kilograms : 0), 0);

        return {
            cartId,
            kilogramsOf: (productId: number, packId: number | null = null) => lineIn(productId, packId)?.kilograms ?? 0,
            teaKilogramsOf,
            lineOf: (productId: number, packId: number | null = null) => lineIn(productId, packId),
            mostKilogramsOf: (productId: number, packId: number | null = null) => {
                const product = productOf(productId);

                if (product === undefined) {
                    return MAX_KILOGRAMS;
                }

                return Math.max(0, mostKilograms(product) - teaKilogramsOf(productId) + (lineIn(productId, packId)?.kilograms ?? 0));
            },
            isSoldOut: (productId: number) => {
                const product = productOf(productId);

                return product !== undefined && isBulkSoldOut(product);
            },
            set: (productId: number, kilograms: number, packId: number | null = null) => {
                const chosen = productOf(productId);

                if (cartId !== null && chosen !== undefined) {
                    changeBulkContents(cartFrom(carts, cartId), chosen, pricing.products, startingCarts.bulk, moments, (before) => withCappedKilograms(before, chosen, packId, kilograms));
                }
            },
            quote: (productId: number, kilograms: number, packId: number | null) => quoteLine(cart, pricing.products, book, productId, kilograms, packId),
            clear: () => {
                if (cartId !== null) {
                    bulkContents.write(cartId, EMPTY_CART);
                }
            },
            lines,
            priced: priceCart(book, lines),
            isEmpty: lines.length === 0,
            hasUnpackable: lines.some((line) => line.packets === null),
            hasOutsideLimit: lines.some((line) => line.limit !== null),
            hasShort: lines.some((line) => line.isShort),
            steps: priceSteps(
                lines.length === 0 ? orderable : lines.map((line) => line.product),
                lines.reduce((sum, line) => sum + line.kilograms, 0),
            ),
            products: pricing.products,
        };
    }, [cart, cartId, carts, pricing, startingCarts, moments]);
}

/**
 * Changes one bulk Cart's Contents from what they hold now — lines for teas a
 * buyer can no longer have dropped first — and raises Added to Cart when the
 * tea goes from none to some there. Shared by a Cart's own steppers and an
 * add from anywhere else (`useAdding()`).
 */
export function changeBulkContents(from: MomentCart, product: CartProduct, products: CartProduct[], startingCartId: number | null, moments: UseMoments, change: (before: Cart) => Cart): void {
    const before = onlyOrderable(bulkContents.read(startingCartId)[from.id] ?? EMPTY_CART, products);
    const after = change(before);
    const teaOf = (contents: Cart) => contents.reduce((sum, entry) => sum + (entry.productId === product.id ? entry.kilograms : 0), 0);

    bulkContents.write(from.id, after);

    if (teaOf(before) === 0 && teaOf(after) > 0) {
        moments.addedToCart(product, teaOf(after), before.length === 0, from);
    }
}
