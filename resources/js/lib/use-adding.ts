import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import type { CartKind } from '@/types';
import { addRule, type AddRule } from './add-rule';
import { withAddedKilograms } from './cart';
import { withAddedPacks } from './retail-cart';
import { changeBulkContents } from './use-cart';
import { useMoments } from './use-moments';
import { changeRetailContents } from './use-retail-cart';

export type UseAdding = {
    /** Where an add of a tea that way goes, from the Carts it is assigned to: straight into one, a choice among several, or nowhere. */
    ruleFor: (assignedCartIds: number[], kind: CartKind) => AddRule;
    /** Adds Kilograms of a tea to a bulk Cart's Contents, in a Pack or its default, on top of what that Pack holds there. */
    addKilograms: (cartId: number, productId: number, kilograms: number, packId?: number | null) => void;
    /** Adds Packs to a retail Cart's Contents on top of what that Pack holds there. */
    addPacks: (cartId: number, packId: number, count: number) => void;
};

/**
 * Adding from outside any Cart's page — a tea's own page, a card in a rail
 * (ADR-0038). It shows no Cart's quantity and only ever adds: what it adds
 * joins whatever that Pack already holds in the Cart it reaches, stopped at
 * the tea's limits and what is free to sell, and never leaves less there. A
 * tea a buyer cannot have now is not added.
 */
export function useAdding(): UseAdding {
    const { carts, pricing, retailPricing, startingCarts } = usePage().props;
    const moments = useMoments();

    return useMemo(
        () => ({
            ruleFor: (assignedCartIds: number[], kind: CartKind) => addRule(assignedCartIds, carts, kind),
            addKilograms: (cartId: number, productId: number, kilograms: number, packId: number | null = null) => {
                const product = pricing.products.find((candidate) => candidate.id === productId && candidate.isAvailable);

                if (product !== undefined) {
                    changeBulkContents(cartId, product, pricing.products, startingCarts.bulk, moments, (before) => withAddedKilograms(before, product, packId, kilograms));
                }
            },
            addPacks: (cartId: number, packId: number, count: number) => {
                if (retailPricing.packs.some((pack) => pack.id === packId && pack.isOrderable)) {
                    changeRetailContents(cartId, packId, retailPricing.packs, startingCarts.retail, moments, (before) => withAddedPacks(before, retailPricing.packs, packId, count));
                }
            },
        }),
        [carts, pricing, retailPricing, startingCarts, moments],
    );
}
