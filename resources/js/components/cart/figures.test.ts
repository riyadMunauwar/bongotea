import { describe, expect, it } from 'vitest';
import { formatTaka } from '@/lib/bangla';
import { discountLabel, discountPercent, packName, retailDeliveryNote } from './figures';

const taka = (minorUnits: number) => formatTaka(minorUnits / 100);

describe('discountLabel', () => {
    it('names what a Tier Price takes off the MRP, in taka and as a share of it', () => {
        expect(discountLabel(50000, 42000, taka)).toBe('৳৮০ ছাড় (১৬%)');
    });

    it('rounds the share down, so it never claims more than is taken off', () => {
        expect(discountLabel(48000, 40500, taka)).toBe('৳৭৫ ছাড় (১৫%)');
    });

    it('leaves the share out when it rounds down to nothing', () => {
        expect(discountLabel(100000, 99900, taka)).toBe('৳১ ছাড়');
    });

    it('says nothing for a price at the MRP', () => {
        expect(discountLabel(50000, 50000, taka)).toBeNull();
    });
});

describe('discountPercent', () => {
    it('is the share alone, rounded down, and nothing at the MRP', () => {
        expect(discountPercent(48000, 40500)).toBe('১৫');
        expect(discountPercent(50000, 50000)).toBeNull();
    });
});

describe('packName', () => {
    it('names a Pack by its weight, in grams below a kilogram and kilograms from one', () => {
        expect(packName(250)).toBe('২৫০ গ্রাম');
        expect(packName(1000)).toBe('১ কেজি');
        expect(packName(1500)).toBe('১.৫ কেজি');
    });
});

describe('retailDeliveryNote', () => {
    const priced = { lines: [], packCount: 2, subtotal: 54000, savings: 0, deliveryCharge: 6000, total: 60000, amountToFreeDelivery: 46000 };

    it('says how much more would make delivery free', () => {
        expect(retailDeliveryNote(priced, taka)).toBe('আর ৳৪৬০-এর প্যাকেট নিলে ফ্রি ডেলিভারি');
    });

    it('says delivery is free once it is', () => {
        expect(retailDeliveryNote({ ...priced, deliveryCharge: 0, amountToFreeDelivery: null }, taka)).toBe('আপনি ফ্রি ডেলিভারি পাচ্ছেন');
    });

    it('says nothing where delivery is never free', () => {
        expect(retailDeliveryNote({ ...priced, amountToFreeDelivery: null }, taka)).toBeNull();
    });
});
