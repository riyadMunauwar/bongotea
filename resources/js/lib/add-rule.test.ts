import { describe, expect, it } from 'vitest';
import type { OpenCart } from '@/types';
import { addRule } from './add-rule';

const cart = (id: number, kind: 'bulk' | 'retail', sortOrder = id): OpenCart => ({ id, name: `Cart ${id}`, kind, href: `/cart-${id}`, sortOrder });

/** Open Carts in the Merchant's order: two bulk, two retail. */
const open = [cart(1, 'retail'), cart(2, 'bulk'), cart(3, 'bulk'), cart(4, 'retail')];

describe('addRule', () => {
    it('goes straight into the one open Cart of the kind a tea is assigned to', () => {
        expect(addRule([3], open, 'bulk')).toStrictEqual({ type: 'straight', cart: open[2] });
    });

    it('asks which, of only the assigned Carts, when a tea is assigned to several', () => {
        expect(addRule([4, 1, 3], open, 'retail')).toStrictEqual({ type: 'choose', carts: [open[0], open[3]] });
    });

    it('asks which of every open Cart of the kind when a tea is assigned to none', () => {
        expect(addRule([], open, 'bulk')).toStrictEqual({ type: 'choose', carts: [open[1], open[2]] });
    });

    it('never offers a closed Cart, going straight in when one assigned Cart is left open', () => {
        expect(addRule([3, 9], open, 'bulk')).toStrictEqual({ type: 'straight', cart: open[2] });
    });

    it('treats a tea whose assigned Carts are all closed as assigned to none', () => {
        expect(addRule([9], open, 'retail')).toStrictEqual({ type: 'choose', carts: [open[0], open[3]] });
    });

    it('asks nothing when there is only one Cart of the kind to choose', () => {
        expect(addRule([], [cart(2, 'bulk'), cart(1, 'retail')], 'bulk')).toStrictEqual({ type: 'straight', cart: cart(2, 'bulk') });
    });

    it('has nowhere to go when no Cart of the kind is open', () => {
        expect(addRule([1], [cart(1, 'retail')], 'bulk')).toStrictEqual({ type: 'none' });
    });
});
