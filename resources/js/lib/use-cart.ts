import { usePage } from '@inertiajs/react';
import { useMemo, useSyncExternalStore } from 'react';
import {
    cartLines,
    EMPTY_CART,
    onlyOrderable,
    priceSteps,
    readCart,
    subscribeToCart,
    withKilograms,
    withPack,
    writeCart,
    type BulkCartLine,
    type CartProduct,
    type PriceStep,
} from './cart';
import { priceCart, type PricedCart } from './pricing';
import { useMoments } from './use-moments';

export type UseCart = {
    /** The Kilograms of a Product in the Cart; nought for one a buyer cannot have now. */
    kilogramsOf: (productId: number) => number;
    /** A Product's line with its Pack and packet count, or null while it holds no Kilograms. */
    lineOf: (productId: number) => BulkCartLine | null;
    /** Sets a Product's Kilograms, clamped to 0–999; refused for a Product a buyer cannot have now. From none to some is an Added to Cart. */
    set: (productId: number, kilograms: number) => void;
    /** Chooses which of a Product's পাইকারি Packs its Kilograms are supplied in. */
    setPack: (productId: number, packId: number) => void;
    clear: () => void;
    /** The lines, in catalogue order, each with its Pack. */
    lines: BulkCartLine[];
    /** Every figure the Cart shows, worked out afresh as it changes. */
    priced: PricedCart;
    isEmpty: boolean;
    /** Whether a line's Kilograms are not a whole number of its Pack, which Checkout would refuse. */
    hasUnpackable: boolean;
    /** The ladder of weights for the teas in the Cart, or for every tea a buyer can have while it is empty. */
    steps: PriceStep[];
    /** Every published Product the page was sent, in catalogue order. */
    products: CartProduct[];
};

/**
 * The one Cart, priced. Every view of the Cart goes through here, so a
 * stepper on a card, the header and the Bulk Order form all show the same
 * Kilograms, Packs and figures. The server draws an empty Cart — it cannot
 * see the browser's storage — and the stored one appears as the page comes
 * alive.
 */
export function useCart(): UseCart {
    const { pricing } = usePage().props;
    const cart = useSyncExternalStore(subscribeToCart, readCart, () => EMPTY_CART);
    const moments = useMoments();

    return useMemo(() => {
        const lines = cartLines(cart, pricing.products);
        const held = new Map(lines.map((line) => [line.product.id, line]));
        const orderable = pricing.products.filter((product) => product.isAvailable);
        const available = new Set(orderable.map((product) => product.id));

        return {
            kilogramsOf: (productId: number) => held.get(productId)?.kilograms ?? 0,
            lineOf: (productId: number) => held.get(productId) ?? null,
            set: (productId: number, kilograms: number) => {
                if (!available.has(productId)) {
                    return;
                }

                const before = onlyOrderable(readCart(), pricing.products);
                const after = withKilograms(before, productId, kilograms);

                writeCart(after);

                if ((before[productId]?.kilograms ?? 0) === 0 && (after[productId]?.kilograms ?? 0) > 0) {
                    const product = orderable.find((candidate) => candidate.id === productId);

                    if (product !== undefined) {
                        moments.addedToCart(product, after[productId].kilograms, Object.keys(before).length === 0);
                    }
                }
            },
            setPack: (productId: number, packId: number) => {
                if (held.get(productId)?.product.packs.some((pack) => pack.id === packId)) {
                    writeCart(withPack(onlyOrderable(readCart(), pricing.products), productId, packId));
                }
            },
            clear: () => writeCart(EMPTY_CART),
            lines,
            priced: priceCart({ deliveryBands: pricing.deliveryBands }, lines),
            isEmpty: lines.length === 0,
            hasUnpackable: lines.some((line) => line.packets === null),
            steps: priceSteps(
                lines.length === 0 ? orderable : lines.map((line) => line.product),
                lines.reduce((sum, line) => sum + line.kilograms, 0),
            ),
            products: pricing.products,
        };
    }, [cart, pricing, moments]);
}
