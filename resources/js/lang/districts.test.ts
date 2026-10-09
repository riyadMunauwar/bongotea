import { describe, expect, it } from 'vitest';
import { districtFromTyped, districtName, districtNames } from './districts';

const districts = ['Dhaka', "Cox's Bazar", 'Sylhet'];

describe('districtFromTyped', () => {
    it('finds the stored district by its Bangla name', () => {
        expect(districtFromTyped('কক্সবাজার', districts)).toBe("Cox's Bazar");
    });

    it('finds the stored district by its English name in any case', () => {
        expect(districtFromTyped('  dhaka ', districts)).toBe('Dhaka');
    });

    it('names nothing for a partial or unknown name', () => {
        expect(districtFromTyped('ঢা', districts)).toBeNull();
        expect(districtFromTyped('Atlantis', districts)).toBeNull();
    });
});

describe('districtName', () => {
    it('names all 64 districts in Bangla, and falls back to the stored name', () => {
        expect(Object.keys(districtNames)).toHaveLength(64);
        expect(districtName('Sylhet')).toBe('সিলেট');
        expect(districtName('Elsewhere')).toBe('Elsewhere');
    });
});
