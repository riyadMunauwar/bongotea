import { usePage } from '@inertiajs/react';
import { useMemo, useSyncExternalStore } from 'react';
import type { LimitBreach } from './cart';
import {
    beyondFree,
    cappedCount,
    EMPTY_RETAIL_CART,
    isPackSoldOut,
    MAX_PACKS,
    onlyOrderablePacks,
    outsideLimits,
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
import { useMoments } from './use-moments';

export type UseRetailCart = {
    /** How many of a Pack the Cart holds; nought for one a buyer cannot order now. */
    countOf: (packId: number) => number;
    /** The most of a Pack a stepper may reach: where its tea, with its other Packs held, would pass its খুচরা maximum or what is free to sell, else 99. */
    mostOf: (packId: number) => number;
    /** Whether a counted tea has less free to sell than the Pack weighs: স্টক শেষ, though still orderable. */
    isSoldOut: (packId: number) => boolean;
    /** Sets a Pack's count, clamped to 0–99 and stopped at its tea's খুচরা maximum and what is free to sell, but never stopped coming down; refused for a Pack a buyer cannot order now. From none to some is an Added to Cart. */
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
    /** Where a tea's Packs together fall outside its খুচরা Order Limit, or null within it. */
    limitOf: (productId: number) => LimitBreach;
    /** Whether any tea is outside its খুচরা Order Limit, which Checkout would refuse. */
    hasOutsideLimit: boolean;
    /** Whether a tea's Packs together hold more than is free to sell, which Checkout would refuse. */
    isShort: (productId: number) => boolean;
    /** Whether any tea holds more than is free to sell, which Checkout would refuse. */
    hasShort: boolean;
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
    const moments = useMoments();

    return useMemo(() => {
        const lines = retailLines(cart, retailPricing.packs);
        const held = new Map(lines.map((line) => [line.pack.id, line.count]));
        const orderable = new Set(retailPricing.packs.filter((pack) => pack.isOrderable).map((pack) => pack.id));
        const outside = outsideLimits(lines);
        const short = beyondFree(lines);

        return {
            countOf: (packId: number) => held.get(packId) ?? 0,
            mostOf: (packId: number) => Math.min(MAX_PACKS, cappedCount(cart, retailPricing.packs, packId, MAX_PACKS)),
            isSoldOut: (packId: number) => {
                const pack = retailPricing.packs.find((candidate) => candidate.id === packId);

                return pack !== undefined && isPackSoldOut(pack);
            },
            set: (packId: number, count: number) => {
                if (!orderable.has(packId)) {
                    return;
                }

                const before = onlyOrderablePacks(readRetailCart(), retailPricing.packs);
                const after = withCount(before, packId, cappedCount(before, retailPricing.packs, packId, count));

                writeRetailCart(after);

                if ((before[packId] ?? 0) === 0 && (after[packId] ?? 0) > 0) {
                    const pack = retailPricing.packs.find((candidate) => candidate.id === packId);

                    if (pack !== undefined) {
                        moments.packAdded(pack, after[packId], Object.keys(before).length === 0);
                    }
                }
            },
            remove: (packId: number) => writeRetailCart(withCount(readRetailCart(), packId, 0)),
            clear: () => writeRetailCart(EMPTY_RETAIL_CART),
            lines,
            unorderable: unorderableLines(cart, retailPricing.packs),
            priced: priceRetailCart({ deliveryCharge: retailPricing.deliveryCharge, freeDeliveryFrom: retailPricing.freeDeliveryFrom }, lines),
            isEmpty: lines.length === 0,
            limitOf: (productId: number) => outside.get(productId) ?? null,
            hasOutsideLimit: outside.size > 0,
            isShort: (productId: number) => short.has(productId),
            hasShort: short.size > 0,
            packsOf: (productId: number) => retailPricing.packs.filter((pack) => pack.productId === productId),
        };
    }, [cart, retailPricing, moments]);
}
