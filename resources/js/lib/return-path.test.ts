import { describe, expect, it } from 'vitest';
import { returnPath, withReturnPath } from './return-path';

/** What the খুচরা কার্ট page's address bar would hold after the pack chooser sent the buyer there. */
function searchOf(url: string): string {
    return new URL(url, 'https://bongotea.test').search;
}

describe('going back for another tea', () => {
    it('returns to the page the Pack was bought from', () => {
        expect(returnPath(searchOf(withReturnPath('/packet-basket', '/products/assam-gold')))).toBe('/products/assam-gold');
        expect(returnPath(searchOf(withReturnPath('/packet-basket', '/category/black-tea?page=2')))).toBe('/category/black-tea?page=2');
    });

    it('goes to the page it was asked for', () => {
        expect(new URL(withReturnPath('/packet-basket', '/'), 'https://bongotea.test').pathname).toBe('/packet-basket');
    });

    it('falls back to the home page when nobody said where they came from', () => {
        expect(returnPath('?page=2')).toBe('/');
        expect(returnPath('')).toBe('/');
    });

    it('never leaves the store', () => {
        expect(returnPath('?from=https%3A%2F%2Fevil.example')).toBe('/');
        expect(returnPath('?from=%2F%2Fevil.example')).toBe('/');
        expect(returnPath('?from=%2F%5Cevil.example')).toBe('/');
        expect(returnPath('?from=javascript%3Aalert(1)')).toBe('/');
    });
});
