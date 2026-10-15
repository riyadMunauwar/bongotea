/**
 * Contents (ADR-0038): what this browser has gathered in each Cart, kept by
 * Cart id under one `localStorage` key per kind, and never anywhere else. Two
 * Carts' Contents never meet: each is read, written and checked out alone.
 *
 * A Cart that has closed keeps its Contents here, untouched, until the
 * Customer clears them or it reopens. Before Carts were named the browser
 * held one bulk Cart and one retail Cart under keys of their own; the first
 * read that knows the starting Cart of the kind moves that into it, once,
 * and removes the old key. Storage that refuses to be read or written leaves
 * the Contents working for this visit, held in memory.
 */

/** Every Cart's Contents of one kind, by Cart id; a Cart holding nothing is absent. */
export type ContentsByCart<T> = Readonly<Record<number, T>>;

/** How one kind's Contents are stored, read and told empty. */
export type ContentsKind<T> = {
    key: string;
    /** Where the single Cart of this kind was kept before Carts were named. */
    legacyKey: string;
    /** One Cart's Contents from what was stored, keeping only what makes sense. */
    from: (stored: unknown) => T;
    isEmpty: (contents: T) => boolean;
};

/** Every Cart's Contents as stored, keeping only Cart ids holding something. */
export function parseContents<T>(raw: string | null, kind: ContentsKind<T>): ContentsByCart<T> {
    let stored: unknown;

    try {
        stored = raw === null ? null : JSON.parse(raw);
    } catch {
        return {};
    }

    if (stored === null || typeof stored !== 'object' || Array.isArray(stored)) {
        return {};
    }

    const contents: Record<number, T> = {};

    for (const [key, value] of Object.entries(stored)) {
        const held = kind.from(value);

        if (/^[1-9]\d*$/.test(key) && !kind.isEmpty(held)) {
            contents[Number(key)] = held;
        }
    }

    return contents;
}

/**
 * The Contents with what the browser held before Carts were named moved into
 * the starting Cart of the kind — unless that Cart already holds something,
 * which is never overwritten.
 */
export function withLegacy<T>(contents: ContentsByCart<T>, legacyRaw: string | null, startingCartId: number, kind: ContentsKind<T>): ContentsByCart<T> {
    let stored: unknown;

    try {
        stored = legacyRaw === null ? null : JSON.parse(legacyRaw);
    } catch {
        return contents;
    }

    const legacy = kind.from(stored);

    return kind.isEmpty(legacy) || contents[startingCartId] !== undefined ? contents : { ...contents, [startingCartId]: legacy };
}

/** Sets one Cart's Contents, leaving every other Cart's as it is; empty Contents leave the Cart absent. */
export function withContents<T>(contents: ContentsByCart<T>, cartId: number, held: T, kind: ContentsKind<T>): ContentsByCart<T> {
    const { [cartId]: _replaced, ...rest } = contents;

    return kind.isEmpty(held) ? rest : { ...rest, [cartId]: held };
}

/** Where one Cart's Contents are checked out: the Checkout, named for that Cart. */
export function checkoutFor(checkout: string, cartId: number): string {
    return `${checkout}?cart=${cartId}`;
}

/** This browser's localStorage, or null where it cannot be had. */
export function storage(): Storage | null {
    try {
        return typeof window === 'undefined' ? null : window.localStorage;
    } catch {
        return null;
    }
}

export type ContentsStore<T> = {
    /** Every Cart's Contents now, the old single Cart moved into the starting one first. The same object until it changes, as `useSyncExternalStore` needs. */
    read: (startingCartId: number | null) => ContentsByCart<T>;
    /** Replaces one Cart's Contents. */
    write: (cartId: number, held: T) => void;
    /** Calls the listener whenever any Cart's Contents change, here or in another tab. */
    subscribe: (listener: () => void) => () => void;
};

export function contentsStore<T>(kind: ContentsKind<T>): ContentsStore<T> {
    const listeners = new Set<() => void>();

    /** The Contents as last read or written; null when they must be read from storage again. */
    let current: ContentsByCart<T> | null = null;

    const persist = (contents: ContentsByCart<T>) => {
        try {
            storage()?.setItem(kind.key, JSON.stringify(contents));
        } catch {
            // Kept in memory for this visit; see the note at the top.
        }
    };

    const read = (startingCartId: number | null): ContentsByCart<T> => {
        if (current !== null) {
            return current;
        }

        try {
            current = parseContents(storage()?.getItem(kind.key) ?? null, kind);

            const legacy = storage()?.getItem(kind.legacyKey) ?? null;

            if (legacy !== null && startingCartId !== null) {
                current = withLegacy(current, legacy, startingCartId, kind);
                persist(current);
                storage()?.removeItem(kind.legacyKey);
            }
        } catch {
            current ??= {};
        }

        return current;
    };

    return {
        read,
        write: (cartId, held) => {
            current = withContents(read(null), cartId, held, kind);
            persist(current);
            listeners.forEach((listener) => listener());
        },
        subscribe: (listener) => {
            const fromAnotherTab = (event: StorageEvent) => {
                if (event.key === kind.key || event.key === null) {
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
        },
    };
}
