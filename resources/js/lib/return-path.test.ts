import { describe, expect, it } from 'vitest';
import { retailCheckoutUrl, returnPath } from './return-path';

/** What the Checkout's address bar would hold after the pack chooser sent the buyer there. */
function searchOf(url: string): string {
    return new URL(url, 'https://bongotea.test').search;
}

describe('going back for another tea', () => {
    it('returns to the page the Pack was bought from', () => {
        expect(returnPath(searchOf(retailCheckoutUrl('/checkout', '/products/assam-gold')))).toBe('/products/assam-gold');
        expect(returnPath(searchOf(retailCheckoutUrl('/checkout', '/category/black-tea?page=2')))).toBe('/category/black-tea?page=2');
    });

    it('opens the Checkout for the retail Cart', () => {
        expect(new URLSearchParams(searchOf(retailCheckoutUrl('/checkout', '/'))).get('cart')).toBe('retail');
    });

    it('falls back to the home page when nobody said where they came from', () => {
        expect(returnPath('?cart=retail')).toBe('/');
        expect(returnPath('')).toBe('/');
    });

    it('never leaves the store', () => {
        expect(returnPath('?cart=retail&from=https%3A%2F%2Fevil.example')).toBe('/');
        expect(returnPath('?cart=retail&from=%2F%2Fevil.example')).toBe('/');
        expect(returnPath('?cart=retail&from=%2F%5Cevil.example')).toBe('/');
        expect(returnPath('?cart=retail&from=javascript%3Aalert(1)')).toBe('/');
    });
});
