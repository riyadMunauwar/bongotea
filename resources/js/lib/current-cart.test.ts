import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { OpenCart } from '@/types';
import { barCarts, forgetCart, mindCart, parseCurrentCarts, readCurrentCarts, resolveCurrentCart } from './current-cart';

const cart = (id: number, kind: 'bulk' | 'retail' = 'bulk'): OpenCart => ({ id, name: `Cart ${id}`, kind, href: `/cart-${id}`, sortOrder: id });

/** Open Carts in the Merchant's order: three bulk, one retail. */
const open = [cart(1), cart(2), cart(3), cart(4, 'retail')];

describe('the Current Cart', () => {
    it('is the Cart the browser last minded, while it is open', () => {
        expect(resolveCurrentCart('bulk', { stored: 3, open, holding: [2], headerCartId: 1 })).toBe(open[2]);
    });

    it('falls back to another open Cart of the kind holding something, in the Merchant\'s order', () => {
        expect(resolveCurrentCart('bulk', { stored: 9, open, holding: [9, 3, 2], headerCartId: 1 })).toBe(open[1]);
    });

    it('then to the Cart the header\'s button opens', () => {
        expect(resolveCurrentCart('bulk', { stored: null, open, holding: [4], headerCartId: 3 })).toBe(open[2]);
    });

    it('then to the only open Cart of the kind', () => {
        expect(resolveCurrentCart('retail', { stored: null, open, holding: [], headerCartId: null })).toBe(open[3]);
    });

    it('is none where several are open and nothing points at one', () => {
        expect(resolveCurrentCart('bulk', { stored: null, open, holding: [], headerCartId: null })).toBeNull();
    });

    it('is never a closed Cart, the header\'s included', () => {
        expect(resolveCurrentCart('bulk', { stored: 9, open: [cart(1)], holding: [9], headerCartId: 9 })).toStrictEqual(cart(1));
    });

    it('reads stored choices forgivingly', () => {
        expect(parseCurrentCarts('{"bulk":3,"retail":"x"}')).toStrictEqual({ bulk: 3, retail: null });
        expect(parseCurrentCarts('nonsense')).toStrictEqual({ bulk: null, retail: null });
    });
});

describe('the Cart bar', () => {
    it('shows the Current Carts holding something first, and counts every other Cart holding something', () => {
        const rows = barCarts({ current: [2, 4], holding: [{ id: 3, kind: 'bulk' }, { id: 2, kind: 'bulk' }, { id: 4, kind: 'retail' }, { id: 9, kind: 'retail' }] });

        expect(rows.shown.map((row) => row.id)).toStrictEqual([2, 4]);
        expect(rows.more.map((row) => row.id)).toStrictEqual([3, 9]);
    });

    it('leaves every Cart that is not Current to the +N row, a closed one among them', () => {
        const rows = barCarts({ current: [1, null], holding: [{ id: 9, kind: 'retail' }] });

        expect(rows.shown).toStrictEqual([]);
        expect(rows.more.map((row) => row.id)).toStrictEqual([9]);
    });
});

describe('choosing and letting go', () => {
    beforeEach(() => {
        const stored = new Map<string, string>();

        vi.stubGlobal('window', { localStorage: { getItem: (key: string) => stored.get(key) ?? null, setItem: (key: string, value: string) => stored.set(key, value) } });
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('minds the Cart last chosen of each kind, and lets go of one a Capture emptied so the fallbacks decide again', () => {
        mindCart('bulk', 3);
        mindCart('retail', 4);
        forgetCart('bulk', 2);

        expect(readCurrentCarts()).toStrictEqual({ bulk: 3, retail: 4 });

        forgetCart('bulk', 3);

        expect(readCurrentCarts()).toStrictEqual({ bulk: null, retail: 4 });
        expect(resolveCurrentCart('bulk', { stored: readCurrentCarts().bulk, open, holding: [2], headerCartId: 1 })).toBe(open[1]);
    });
});
