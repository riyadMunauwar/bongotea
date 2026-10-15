import { usePage } from '@inertiajs/react';
import { useMemo, useSyncExternalStore } from 'react';
import type { CartKind, OpenCart } from '@/types';
import { bulkContents } from './cart';
import type { ContentsByCart } from './contents';
import { mindCart, NO_CURRENT_CARTS, readCurrentCarts, resolveCurrentCart, subscribeToCurrentCarts, type HeldCart } from './current-cart';
import { retailContents } from './retail-cart';

/** A Cart this browser holds something in, named, and whether it is open — a closed one is drawn বন্ধ. */
export type HeldCartInfo = HeldCart & { name: string; href: string | null; isOpen: boolean };

export type UseCurrentCarts = {
    /** The Current bulk and retail Carts, resolved afresh; null where none applies. */
    bulk: OpenCart | null;
    retail: OpenCart | null;
    /** Every Cart holding something, open ones in the Merchant's order and then closed ones. */
    held: HeldCartInfo[];
    /** Every open Cart of a kind, to switch to. */
    ofKind: (kind: CartKind) => OpenCart[];
    /** Makes a Cart the Current Cart of its kind. */
    mind: (kind: CartKind, cartId: number) => void;
};

/** What the server draws: no Contents in any Cart, the same object every time, as `useSyncExternalStore` needs. */
const NOTHING: ContentsByCart<never> = Object.freeze({});

/**
 * The browser's Current Cart of each kind (ADR-0038), and every Cart it holds
 * something in. The header and the Cart bar read them here; a Cart's page,
 * an add and the header's list set them. The server draws none — it cannot
 * see the browser's storage — and they appear as the page comes alive.
 */
export function useCurrentCarts(): UseCurrentCarts {
    const { carts, closedCarts, headerCarts, startingCarts } = usePage().props;
    const stored = useSyncExternalStore(subscribeToCurrentCarts, readCurrentCarts, () => NO_CURRENT_CARTS);
    const bulk = useSyncExternalStore(bulkContents.subscribe, () => bulkContents.read(startingCarts.bulk), () => NOTHING);
    const retail = useSyncExternalStore(retailContents.subscribe, () => retailContents.read(startingCarts.retail), () => NOTHING);

    return useMemo(() => {
        const holding = [...Object.keys(bulk), ...Object.keys(retail)].map(Number);
        const kindOf = (id: number): CartKind => (id in bulk ? 'bulk' : 'retail');
        const resolve = (kind: CartKind) =>
            resolveCurrentCart(kind, { stored: stored[kind], open: carts, holding, headerCartId: headerCarts[kind]?.id ?? null });

        const open: HeldCartInfo[] = carts.filter((cart) => holding.includes(cart.id)).map((cart) => ({ id: cart.id, kind: cart.kind, name: cart.name, href: cart.href, isOpen: true }));
        const closed: HeldCartInfo[] = holding
            .filter((id) => !carts.some((cart) => cart.id === id))
            .map((id) => ({ id, kind: kindOf(id), name: closedCarts.find((cart) => cart.id === id)?.name ?? '', href: null, isOpen: false }));

        return {
            bulk: resolve('bulk'),
            retail: resolve('retail'),
            held: [...open, ...closed],
            ofKind: (kind: CartKind) => carts.filter((cart) => cart.kind === kind),
            mind: mindCart,
        };
    }, [carts, closedCarts, headerCarts, stored, bulk, retail]);
}
