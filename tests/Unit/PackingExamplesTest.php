<?php

use App\Support\Inventory\Grams;
use App\Support\Kilograms;

/**
 * The shared packing worked examples, converted by the PHP module.
 *
 * `tests/Fixtures/Inventory/packing-examples.json` is the contract between the
 * two implementations of packing conversions: Vitest runs the same file
 * against the storefront's TypeScript mirror (`resources/js/lib/grams.test.ts`).
 * A new case belongs in the file, not in this test.
 */
function packingExamples(string $section): array
{
    $file = json_decode(file_get_contents(__DIR__.'/../Fixtures/Inventory/packing-examples.json'), true, flags: JSON_THROW_ON_ERROR);

    $examples = [];

    foreach ($file[$section] as $example) {
        $examples[$example['name']] = [$example];
    }

    return $examples;
}

it('packs every kilogram worked example as the shared file expects', function (array $example) {
    $grams = Grams::fromKilograms($example['kilograms']);

    expect($grams)->toBe($example['expected']['grams'])
        ->and(Grams::packetsIn($grams, $example['packGrams']))->toBe($example['expected']['packets']);

    if (array_key_exists('workableKilograms', $example['expected'])) {
        expect(Grams::workableKilograms($example['kilograms'], $example['packGrams']))->toBe($example['expected']['workableKilograms']);
    }
})->with(packingExamples('kilograms'));

it('weighs every packet worked example as the shared file expects', function (array $example) {
    $grams = Grams::ofPackets($example['packets'], $example['packGrams']);

    expect($grams)->toBe($example['expected']['grams'])
        ->and(Kilograms::fromGrams($grams))->toBe($example['expected']['kilograms']);
})->with(packingExamples('packets'));

it('refuses a Pack that weighs nothing rather than dividing by it', function () {
    Grams::packetsIn(1000, 0);
})->throws(InvalidArgumentException::class);
