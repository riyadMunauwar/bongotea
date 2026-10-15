/**
 * The Current Cart (ADR-0038): the one Cart of each kind this browser is
 * minding at the moment — set when a Cart's page is visited, when something
 * is added, and when the Customer chooses one from the header — kept under
 * `bongotea.current-carts` and resolved afresh every time, never trusted
 * blindly, so it never names a closed Cart:
 *
 * 1. the stored Cart, if it is open;
 * 2. else another open Cart of that kind holding something, in the
 *    Merchant's order;
 * 3. else the Cart the header's button for that kind opens;
 * 4. else the only open Cart of that kind.
 *
 * It belongs to the browser, like Contents, and is not the Merchant's switch.
 */

import type { CartKind, OpenCart } from '@/types';
import { storage } from './contents';

const STORAGE_KEY = 'bongotea.current-carts';

/** The Cart of each kind the browser last minded, by id, or null. */
export type CurrentCarts = Readonly<{ bulk: number | null; retail: number | null }>;

export const NO_CURRENT_CARTS: CurrentCarts = Object.freeze({ bulk: null, retail: null });

/** What resolving a kind's Current Cart reads. */
export type CurrentCartFacts = {
    stored: number | null;
    /** Every open Cart, in the Merchant's order. */
    open: OpenCart[];
    /** The Carts this browser's Contents hold something in, by id. */
    holding: number[];
    /** The Cart the header's button for the kind opens, or null where it draws none. */
    headerCartId: number | null;
};

/** A kind's Current Cart, through each fallback in order; null where none applies. */
export function resolveCurrentCart(kind: CartKind, { stored, open, holding, headerCartId }: CurrentCartFacts): OpenCart | null {
    const ofKind = open.filter((cart) => cart.kind === kind);

    return (
        ofKind.find((cart) => cart.id === stored) ??
        ofKind.find((cart) => holding.includes(cart.id)) ??
        ofKind.find((cart) => cart.id === headerCartId) ??
        (ofKind.length === 1 ? ofKind[0] : null)
    );
}

/** The stored choices, read forgivingly: anything that is not a Cart id reads as none. */
export function parseCurrentCarts(raw: string | null): CurrentCarts {
    let stored: unknown;

    try {
        stored = raw === null ? null : JSON.parse(raw);
    } catch {
        return NO_CURRENT_CARTS;
    }

    const idOf = (value: unknown) => (typeof value === 'number' && Number.isInteger(value) && value > 0 ? value : null);

    return stored !== null && typeof stored === 'object' ? { bulk: idOf((stored as CurrentCarts).bulk), retail: idOf((stored as CurrentCarts).retail) } : NO_CURRENT_CARTS;
}

/** A Cart the browser holds something in, with its kind. */
export type HeldCart = { id: number; kind: CartKind };

/**
 * The Cart bar's rows (ADR-0038): the Current Carts holding something first,
 * then every other Cart holding something — closed ones among them — behind
 * one "+N আরও কার্ট" row, so the bar stays slim and no Cart filled can hide.
 */
export function barCarts<T extends HeldCart>({ current, holding }: { current: (number | null)[]; holding: T[] }): { shown: T[]; more: T[] } {
    const shown = current.flatMap((id) => holding.filter((held) => held.id === id));

    return { shown, more: holding.filter((held) => !shown.includes(held)) };
}

const listeners = new Set<() => void>();

/** The stored choices as last read or written; null when they must be read again. */
let current: CurrentCarts | null = null;

export function readCurrentCarts(): CurrentCarts {
    if (current === null) {
        try {
            current = parseCurrentCarts(storage()?.getItem(STORAGE_KEY) ?? null);
        } catch {
            current = NO_CURRENT_CARTS;
        }
    }

    return current;
}

/** Makes a Cart the Current Cart of its kind. */
export function mindCart(kind: CartKind, cartId: number): void {
    const before = readCurrentCarts();

    if (before[kind] === cartId) {
        return;
    }

    current = { ...before, [kind]: cartId };

    try {
        storage()?.setItem(STORAGE_KEY, JSON.stringify(current));
    } catch {
        // Kept in memory for this visit.
    }

    listeners.forEach((listener) => listener());
}

/**
 * Lets go of a Cart a Capture has just emptied, so the Current Cart of its
 * kind moves to another Cart still holding something, or failing that to the
 * header's.
 */
export function forgetCart(kind: CartKind, cartId: number): void {
    if (readCurrentCarts()[kind] !== cartId) {
        return;
    }

    current = { ...readCurrentCarts(), [kind]: null };

    try {
        storage()?.setItem(STORAGE_KEY, JSON.stringify(current));
    } catch {
        // Kept in memory for this visit.
    }

    listeners.forEach((listener) => listener());
}

/** Calls the listener whenever a Current Cart is chosen, here or in another tab. */
export function subscribeToCurrentCarts(listener: () => void): () => void {
    const fromAnotherTab = (event: StorageEvent) => {
        if (event.key === STORAGE_KEY || event.key === null) {
            current = null;
            listener();
        }
    };

    listeners.add(listener);
    window.addEventListener('storage', fromAnotherTab);

    return () => {
        listeners.delete(listener);
        window.removeEventListener('storage', fromAnotherTab);
    };
}
