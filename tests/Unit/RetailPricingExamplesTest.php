<?php

use App\Support\Pricing\PackPrices;
use App\Support\Pricing\RetailLine;
use App\Support\Pricing\RetailPriceBook;

/**
 * The shared retail worked examples, priced by the PHP module.
 *
 * `tests/Fixtures/Pricing/retail-examples.json` is the contract between the
 * two implementations of retail pricing (ADR-0034): Vitest runs the same file
 * against the storefront's TypeScript mirror (`resources/js/lib/retail-pricing.test.ts`).
 * A new case belongs in the file, not in this test.
 */
function retailPricingExamples(): array
{
    $file = json_decode(file_get_contents(__DIR__.'/../Fixtures/Pricing/retail-examples.json'), true, flags: JSON_THROW_ON_ERROR);

    $examples = [];

    foreach ($file['examples'] as $example) {
        $examples[$example['name']] = [[...$file['defaults'], ...$example]];
    }

    return $examples;
}

/**
 * The same array with every map in key order, so a strict comparison does not
 * depend on the order the file happens to write its keys in.
 */
function retailSortedByKey(array $value): array
{
    if (! array_is_list($value)) {
        ksort($value);
    }

    return array_map(fn (mixed $item): mixed => is_array($item) ? retailSortedByKey($item) : $item, $value);
}

it('prices every retail worked example as the shared file expects', function (array $example) {
    $book = new RetailPriceBook($example['deliveryCharge'], $example['freeDeliveryFrom']);

    $packs = [];

    foreach ($example['packs'] as $pack) {
        $packs[$pack['id']] = new PackPrices($pack['id'], $pack['mrp'], $pack['price']);
    }

    $lines = array_map(fn (array $line): RetailLine => new RetailLine(
        pack: $packs[$line['packId']],
        count: $line['count'],
        overridePrice: $line['overridePrice'] ?? null,
    ), $example['lines']);

    /** Strictly: a loose comparison would read a null distance to free delivery as a nought. */
    expect(retailSortedByKey($book->price($lines)->toArray()))->toBe(retailSortedByKey($example['expected']));
})->with(retailPricingExamples());

it('refuses a negative count rather than pricing it', function () {
    (new RetailPriceBook(6000, null))->price([new RetailLine(new PackPrices(1, 300, 270), -1)]);
})->throws(InvalidArgumentException::class);

it('refuses a Price Override above the Pack\'s MRP, since nothing is sold above it', function () {
    (new RetailPriceBook(6000, null))->price([new RetailLine(new PackPrices(1, 300, 270), 1, 301)]);
})->throws(InvalidArgumentException::class);

it('refuses a Price Override below nothing', function () {
    (new RetailPriceBook(6000, null))->price([new RetailLine(new PackPrices(1, 300, 270), 1, -1)]);
})->throws(InvalidArgumentException::class);
