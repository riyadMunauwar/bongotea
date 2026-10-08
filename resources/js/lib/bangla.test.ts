import { describe, expect, it } from 'vitest';
import { formatNumber, formatPhone, formatTaka, parseWholeNumber, toBanglaDigits, toLatinDigits } from './bangla';

describe('display numbers', () => {
    it('draws every digit in Bangla', () => {
        expect(toBanglaDigits(1234567890)).toBe('১২৩৪৫৬৭৮৯০');
        expect(toBanglaDigits('৫১+ কেজি')).toBe('৫১+ কেজি');
        expect(toBanglaDigits('51+ kg')).toBe('৫১+ kg');
    });

    it('groups the Indian way', () => {
        expect(formatNumber(6344)).toBe('৬,৩৪৪');
        expect(formatNumber(1234567)).toBe('১২,৩৪,৫৬৭');
        expect(formatNumber(0)).toBe('০');
    });

    it('draws taka as a whole number after the sign', () => {
        expect(formatTaka(6344)).toBe('৳৬,৩৪৪');
        expect(formatTaka(557.5)).toBe('৳৫৫৮');
        expect(formatTaka(0)).toBe('৳০');
    });

    it('draws an eleven-digit mobile number as five and six', () => {
        expect(formatPhone('01700000000')).toBe('০১৭০০-০০০০০০');
        expect(formatPhone('+8801712345678')).toBe('০১৭১২-৩৪৫৬৭৮');
        expect(formatPhone('09612 345')).toBe('০৯৬১২ ৩৪৫');
    });
});

describe('inputs', () => {
    it('reads Bangla digits as English ones and leaves the rest', () => {
        expect(toLatinDigits('০১৭১২-৩৪৫৬৭৮')).toBe('01712-345678');
        expect(toLatinDigits('01712')).toBe('01712');
    });

    it('reads a whole number typed in either script', () => {
        expect(parseWholeNumber('১২')).toBe(12);
        expect(parseWholeNumber(' 12 ')).toBe(12);
        expect(parseWholeNumber('১2')).toBe(12);
        expect(parseWholeNumber('')).toBeNull();
        expect(parseWholeNumber('১২.৫')).toBeNull();
        expect(parseWholeNumber('বারো')).toBeNull();
    });
});
