import { usePage } from '@inertiajs/react';
import { useMemo, useSyncExternalStore } from 'react';
import { cartLines, EMPTY_CART, onlyOrderable, priceSteps, readCart, subscribeToCart, withKilograms, writeCart, type CartProduct, type PriceStep } from './cart';
import { priceCart, type PricedCart } from './pricing';

export type UseCart = {
    /** The Kilograms of a Product in the Cart; nought for one a buyer cannot have now. */
    kilogramsOf: (productId: number) => number;
    /** Sets a Product's Kilograms, clamped to 0–999; refused for a Product a buyer cannot have now. */
    set: (productId: number, kilograms: number) => void;
    clear: () => void;
    /** Every figure the Cart shows, worked out afresh as it changes. */
    priced: PricedCart;
    isEmpty: boolean;
    /** The ladder of weights for the teas in the Cart, or for every tea a buyer can have while it is empty. */
    steps: PriceStep[];
    /** Every published Product the page was sent, in catalogue order. */
    products: CartProduct[];
};

/**
 * The one Cart, priced. Every view of the Cart goes through here, so a
 * stepper on a card, the header and the Bulk Order form all show the same
 * Kilograms and the same figures. The server draws an empty Cart — it
 * cannot see the browser's storage — and the stored one appears as the page
 * comes alive.
 */
export function useCart(): UseCart {
    const { pricing } = usePage().props;
    const cart = useSyncExternalStore(subscribeToCart, readCart, () => EMPTY_CART);

    return useMemo(() => {
        const lines = cartLines(cart, pricing.products);
        const held = new Map(lines.map((line) => [line.product.id, line.kilograms]));
        const orderable = pricing.products.filter((product) => product.isAvailable);
        const available = new Set(orderable.map((product) => product.id));

        return {
            kilogramsOf: (productId: number) => held.get(productId) ?? 0,
            set: (productId: number, kilograms: number) => {
                if (available.has(productId)) {
                    writeCart(withKilograms(onlyOrderable(readCart(), pricing.products), productId, kilograms));
                }
            },
            clear: () => writeCart(EMPTY_CART),
            priced: priceCart({ deliveryBands: pricing.deliveryBands }, lines),
            isEmpty: lines.length === 0,
            steps: priceSteps(
                lines.length === 0 ? orderable : lines.map((line) => line.product),
                lines.reduce((sum, line) => sum + line.kilograms, 0),
            ),
            products: pricing.products,
        };
    }, [cart, pricing]);
}
