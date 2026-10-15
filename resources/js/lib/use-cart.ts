import { usePage } from '@inertiajs/react';
import { useMemo, useSyncExternalStore } from 'react';
import {
    cappedKilograms,
    cartLines,
    EMPTY_CART,
    isBulkSoldOut,
    MAX_KILOGRAMS,
    mostKilograms,
    onlyOrderable,
    priceSteps,
    quoteLine,
    readCart,
    subscribeToCart,
    packFor,
    withKilograms,
    writeCart,
    type BulkCartLine,
    type CartProduct,
    type LineQuote,
    type PriceStep,
} from './cart';
import { priceCart, type PricedCart } from './pricing';
import { useMoments } from './use-moments';

export type UseCart = {
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
 * The one Cart, priced. Every view of the Cart goes through here, so a
 * stepper on a card, the header and the Bulk Order form all show the same
 * Kilograms, Packs and figures. A stepper that names no Pack — a card's, the
 * price calculator's — is the tea's default Pack's. The server draws an empty Cart — it cannot
 * see the browser's storage — and the stored one appears as the page comes
 * alive.
 */
export function useCart(): UseCart {
    const { pricing } = usePage().props;
    const cart = useSyncExternalStore(subscribeToCart, readCart, () => EMPTY_CART);
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

                if (chosen === undefined) {
                    return;
                }

                const chosenId = packIdOf(chosen, packId);
                const before = onlyOrderable(readCart(), pricing.products);
                const teaBefore = before.filter((entry) => entry.productId === productId).reduce((sum, entry) => sum + entry.kilograms, 0);
                const held = before.find((entry) => entry.productId === productId && entry.packId === chosenId)?.kilograms ?? 0;
                const after = withKilograms(before, productId, chosenId, cappedKilograms(chosen, kilograms, held, teaBefore - held));
                const teaAfter = after.filter((entry) => entry.productId === productId).reduce((sum, entry) => sum + entry.kilograms, 0);

                writeCart(after);

                if (teaBefore === 0 && teaAfter > 0) {
                    moments.addedToCart(chosen, teaAfter, before.length === 0);
                }
            },
            quote: (productId: number, kilograms: number, packId: number | null) => quoteLine(cart, pricing.products, book, productId, kilograms, packId),
            clear: () => writeCart(EMPTY_CART),
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
    }, [cart, pricing, moments]);
}
