import { usePage } from '@inertiajs/react';
import { useMemo, useSyncExternalStore } from 'react';
import {
    EMPTY_RETAIL_CART,
    onlyOrderablePacks,
    readRetailCart,
    retailLines,
    subscribeToRetailCart,
    unorderableLines,
    withCount,
    writeRetailCart,
    type CartPack,
    type HeldPack,
} from './retail-cart';
import { priceRetailCart, type PricedRetailCart } from './retail-pricing';

export type UseRetailCart = {
    /** How many of a Pack the Cart holds; nought for one a buyer cannot order now. */
    countOf: (packId: number) => number;
    /** Sets a Pack's count, clamped to 0–99; refused for a Pack a buyer cannot order now. */
    set: (packId: number, count: number) => void;
    /** Takes a Pack out, the unorderable ones included. */
    remove: (packId: number) => void;
    clear: () => void;
    /** The lines that are priced, in the store's order. */
    lines: HeldPack[];
    /** What the Cart holds that cannot be ordered now; flagged, and in no figure. */
    unorderable: { packId: number; pack: CartPack | null; count: number }[];
    /** Every figure the packet basket shows, worked out afresh as it changes. */
    priced: PricedRetailCart;
    isEmpty: boolean;
    /** A Product's Packs, smallest first unless the Merchant ordered them; empty for a Product not open to retail. */
    packsOf: (productId: number) => CartPack[];
};

/**
 * The one retail Cart, priced (ADR-0034). Every view of it goes through
 * here, and it never touches the bulk Cart or its figures. The server draws
 * an empty basket — it cannot see the browser's storage — and the stored one
 * appears as the page comes alive.
 */
export function useRetailCart(): UseRetailCart {
    const { retailPricing } = usePage().props;
    const cart = useSyncExternalStore(subscribeToRetailCart, readRetailCart, () => EMPTY_RETAIL_CART);

    return useMemo(() => {
        const lines = retailLines(cart, retailPricing.packs);
        const held = new Map(lines.map((line) => [line.pack.id, line.count]));
        const orderable = new Set(retailPricing.packs.filter((pack) => pack.isOrderable).map((pack) => pack.id));

        return {
            countOf: (packId: number) => held.get(packId) ?? 0,
            set: (packId: number, count: number) => {
                if (orderable.has(packId)) {
                    writeRetailCart(withCount(onlyOrderablePacks(readRetailCart(), retailPricing.packs), packId, count));
                }
            },
            remove: (packId: number) => writeRetailCart(withCount(readRetailCart(), packId, 0)),
            clear: () => writeRetailCart(EMPTY_RETAIL_CART),
            lines,
            unorderable: unorderableLines(cart, retailPricing.packs),
            priced: priceRetailCart({ deliveryCharge: retailPricing.deliveryCharge, freeDeliveryFrom: retailPricing.freeDeliveryFrom }, lines),
            isEmpty: lines.length === 0,
            packsOf: (productId: number) => retailPricing.packs.filter((pack) => pack.productId === productId),
        };
    }, [cart, retailPricing]);
}
