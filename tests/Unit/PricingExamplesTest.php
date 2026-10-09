<?php

use App\Support\Pricing\CartLine;
use App\Support\Pricing\DeliveryBand;
use App\Support\Pricing\PriceBook;
use App\Support\Pricing\ProductPrices;
use App\Support\Pricing\Tier;

/**
 * The shared worked examples, priced by the PHP module.
 *
 * `tests/Fixtures/Pricing/examples.json` is the contract between the two
 * implementations of the Tier rules (ADR-0031): Vitest runs the same file
 * against the storefront's TypeScript mirror (`resources/js/lib/pricing.test.ts`),
 * so a rule changed in one and not the other fails here or there. A new case
 * belongs in the file, not in this test.
 */
function pricingExamples(): array
{
    $file = json_decode(file_get_contents(__DIR__.'/../Fixtures/Pricing/examples.json'), true, flags: JSON_THROW_ON_ERROR);

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
function sortedByKey(array $value): array
{
    if (! array_is_list($value)) {
        ksort($value);
    }

    return array_map(fn (mixed $item): mixed => is_array($item) ? sortedByKey($item) : $item, $value);
}

function flatPrices(int $price): ProductPrices
{
    return new ProductPrices(1, [new Tier(1, null, $price)]);
}

it('prices every worked example as the shared file expects', function (array $example) {
    $book = new PriceBook(
        deliveryBands: array_map(fn (array $band): DeliveryBand => new DeliveryBand($band['min'], $band['max'], $band['fee']), $example['deliveryBands']),
    );

    $products = [];

    foreach ($example['products'] as $product) {
        $products[$product['id']] = new ProductPrices(
            id: $product['id'],
            tiers: array_map(fn (array $tier): Tier => new Tier($tier['min'], $tier['max'], $tier['price']), $product['tiers']),
        );
    }

    $lines = array_map(fn (array $line): CartLine => new CartLine(
        product: $products[$line['productId']],
        kilograms: $line['kilograms'],
        overridePrice: $line['overridePrice'] ?? null,
    ), $example['lines']);

    /** Strictly: a loose comparison would read a null average or band as a nought. */
    expect(sortedByKey($book->price($lines)->toArray()))->toBe(sortedByKey($example['expected']));
})->with(pricingExamples());

it('refuses a negative quantity rather than pricing it', function () {
    (new PriceBook([new DeliveryBand(1, null, 0)]))->price([new CartLine(flatPrices(500), -1)]);
})->throws(InvalidArgumentException::class);

it('refuses a Price Override below nothing', function () {
    (new PriceBook([new DeliveryBand(1, null, 0)]))->price([new CartLine(flatPrices(500), 1, -1)]);
})->throws(InvalidArgumentException::class);
