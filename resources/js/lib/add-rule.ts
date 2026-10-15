/**
 * Where a tea added from outside any Cart's page — its own page, a card in a
 * rail — goes (ADR-0038). Assignment decides: the one open Cart of its kind it
 * is assigned to, straight in; several, the Customer chooses among them;
 * none (or none of them open), the Customer chooses among every open Cart of
 * its kind. A closed Cart is never offered, and nobody is asked a question
 * with one answer.
 */

import type { CartKind, OpenCart } from '@/types';

export type AddRule = { type: 'straight'; cart: OpenCart } | { type: 'choose'; carts: OpenCart[] } | { type: 'none' };

/** Where an add goes, given the Carts a tea is assigned to and the open Carts in the Merchant's order. */
export function addRule(assignedCartIds: number[], openCarts: OpenCart[], kind: CartKind): AddRule {
    const ofKind = openCarts.filter((cart) => cart.kind === kind);
    const assigned = ofKind.filter((cart) => assignedCartIds.includes(cart.id));
    const offered = assigned.length > 0 ? assigned : ofKind;

    if (offered.length === 0) {
        return { type: 'none' };
    }

    return offered.length === 1 ? { type: 'straight', cart: offered[0] } : { type: 'choose', carts: offered };
}
