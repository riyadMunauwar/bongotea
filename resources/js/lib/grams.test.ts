import { describe, expect, it } from 'vitest';
import file from '../../../tests/Fixtures/Inventory/packing-examples.json';
import { gramsFromKilograms, gramsOfPackets, kilogramsFromGrams, packetsIn, workableKilograms } from './grams';

/**
 * The shared packing worked examples, converted by the TypeScript mirror. Pest
 * runs the same file against the PHP module (`tests/Unit/PackingExamplesTest.php`);
 * a new case belongs in the file, not here.
 */
describe('the shared packing examples', () => {
    for (const example of file.kilograms) {
        const expected: { grams: number; packets: number | null; workableKilograms?: number[] } = example.expected;

        it(example.name, () => {
            const grams = gramsFromKilograms(example.kilograms);

            expect(grams).toBe(expected.grams);
            expect(packetsIn(grams, example.packGrams)).toBe(expected.packets);

            if (expected.workableKilograms !== undefined) {
                expect(workableKilograms(example.kilograms, example.packGrams)).toStrictEqual(expected.workableKilograms);
            }
        });
    }

    for (const example of file.packets) {
        it(example.name, () => {
            const grams = gramsOfPackets(example.packets, example.packGrams);

            expect(grams).toBe(example.expected.grams);
            expect(kilogramsFromGrams(grams)).toBe(example.expected.kilograms);
        });
    }
});

describe('a Pack that weighs nothing', () => {
    it('is refused rather than divided by', () => {
        expect(() => packetsIn(1000, 0)).toThrow();
    });
});

describe('the weights a Pack can make', () => {
    it('are asked for in whole kilograms only, as the server types them', () => {
        expect(() => workableKilograms(2.5, 400)).toThrow();
    });
});
