<?php

use App\Models\Product;
use App\Models\User;
use App\Support\Inventory\DaysOfCover;
use App\Support\Inventory\StockLedger;
use App\Support\Inventory\ThinShelf;
use App\Support\Settings\InventorySettings;

/*
| Days of Cover is On Hand over the rate units are going out, and the rate is
| read over a fixed trailing span of its own. Nothing here writes a movement
| by hand: a shelf is put into the ledger by counting it, and units leave by
| a parcel being handed to a Courier, which is the only thing that ever moves
| stock out (ADR-0021). Every list is read at reportingNow(), a Wednesday
| morning in Dhaka, like the reporting suites.
*/

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());

    $this->cover = app(DaysOfCover::class);
});

function coverOf(Product $product): ?float
{
    return test()->cover->forProduct($product, reportingNow());
}

/**
 * @return list<array{string, float}>
 */
function runningOut(): array
{
    return array_map(
        fn (ThinShelf $shelf): array => [$shelf->product->name, round($shelf->daysOfCover, 2)],
        test()->cover->runningOut(reportingNow()),
    );
}

/*
|--------------------------------------------------------------------------
| The figure
|--------------------------------------------------------------------------
*/

it('divides what is held by what goes out in a day', function () {
    $tea = shelfOf('Sylhet tea', 84);

    /** Twenty-eight units over the twenty-eight-day span: one a day, so eighty-four last twelve weeks. */
    parcelShippedOn($tea, 28, reportingNow()->subDays(3));

    expect(coverOf($tea))->toBe(56.0);
})->note('84 counted, less the 28 that left, is 56 on the shelf at a unit a day.');

it('reads no cover for a Product nobody has counted, however much of it has gone out', function () {
    $mug = shelfOf('Clay mug');

    parcelShippedOn($mug, 5, reportingNow()->subDays(2));

    /** Nothing left the ledger, because the Product was never in it: an unknown shelf stays unknown (ADR-0021). */
    expect(coverOf($mug))->toBeNull()
        ->and(app(StockLedger::class)->onHand($mug))->toBeNull();
});

it('reads no cover for a Product nothing has gone out of, rather than for ever', function () {
    $dormant = shelfOf('Winter jacket', 40);

    expect(coverOf($dormant))->toBeNull();
});

it('reads no cover for a Product whose last parcel went out before the span', function () {
    $faded = shelfOf('Rain cape', 40);

    parcelShippedOn($faded, 20, reportingNow()->subDays(DaysOfCover::SPAN_DAYS + 1));

    expect(coverOf($faded))->toBeNull();
});

it('counts a parcel on the far edge of the span and not one a day past it', function () {
    $inside = shelfOf('Inside the span', 28);
    $outside = shelfOf('Outside the span', 28);

    parcelShippedOn($inside, 28, reportingNow()->subDays(DaysOfCover::SPAN_DAYS)->addHour());
    parcelShippedOn($outside, 28, reportingNow()->subDays(DaysOfCover::SPAN_DAYS)->subHour());

    expect(coverOf($inside))->toBe(0.0)
        ->and(coverOf($outside))->toBeNull();
});

it('reads a shelf already in deficit as cover below nothing rather than as nothing at all', function () {
    $short = shelfOf('Sylhet tea', 4);

    /** More went out than anybody had counted on: the shelf is below nothing and says so. */
    parcelShippedOn($short, 8, reportingNow()->subDays(1));

    expect(app(StockLedger::class)->onHand($short))->toBe(-4000)
        ->and(coverOf($short))->toBe(-14.0);
});

/*
|--------------------------------------------------------------------------
| What is running out
|--------------------------------------------------------------------------
*/

it('lists the Products under the threshold, thinnest first, and nothing above it', function () {
    $urgent = shelfOf('Sylhet tea', 30);
    parcelShippedOn($urgent, 28, reportingNow()->subDays(1));

    $thin = shelfOf('Clay mug', 60);
    parcelShippedOn($thin, 28, reportingNow()->subDays(1));

    $comfortable = shelfOf('Winter jacket', 280);
    parcelShippedOn($comfortable, 28, reportingNow()->subDays(1));

    /** Two on the shelf at a unit a day, thirty-two on the shelf at a unit a day, and two hundred and fifty-two. */
    expect(runningOut())->toBe([
        ['Sylhet tea', 2.0],
    ]);

    app(InventorySettings::class)->with(['thinCoverDays' => 40])->save();

    expect(runningOut())->toBe([
        ['Sylhet tea', 2.0],
        ['Clay mug', 32.0],
    ])
        ->and($thin->name)->toBe('Clay mug')
        ->and($comfortable->name)->toBe('Winter jacket');
});

it('leaves out a Product with no cover to be thin: nobody has counted it, or nothing has gone out of it', function () {
    $uncounted = shelfOf('Clay mug');
    parcelShippedOn($uncounted, 20, reportingNow()->subDays(1));

    $dormant = shelfOf('Winter jacket', 1);

    /** A shelf of one is as thin as it gets, and it is still not on the list: nothing is going out of it. */
    expect(runningOut())->toBe([])
        ->and($uncounted->name)->toBe('Clay mug')
        ->and($dormant->name)->toBe('Winter jacket');
});

it('puts an empty shelf that is still shipping at the top, and a shelf below nothing above that', function () {
    $empty = shelfOf('Clay mug', 28);
    parcelShippedOn($empty, 28, reportingNow()->subDays(1));

    $deficit = shelfOf('Sylhet tea', 14);
    parcelShippedOn($deficit, 28, reportingNow()->subDays(1));

    expect(runningOut())->toBe([
        ['Sylhet tea', -14.0],
        ['Clay mug', 0.0],
    ]);
});

it('leaves a withdrawn Product off the list, however thin its shelf is', function () {
    $retired = shelfOf('Sylhet tea', 2);
    parcelShippedOn($retired, 28, reportingNow()->subDays(1));

    expect(runningOut())->toHaveCount(1);

    $retired->delete();

    /** Nobody buys more of something they have stopped selling; the units are still capital, and the Stock report is where that is read. */
    expect(runningOut())->toBe([]);
});

it('obeys the Setting, and reads a damaged row as the threshold it already had', function () {
    $tea = shelfOf('Sylhet tea', 84);
    parcelShippedOn($tea, 28, reportingNow()->subDays(1));

    /** Fifty-six on the shelf at a unit a day: above seven, and comfortably. */
    expect(runningOut())->toBe([]);

    app(InventorySettings::class)->with(['thinCoverDays' => 60])->save();

    expect(runningOut())->toBe([['Sylhet tea', 56.0]]);

    /** A threshold of nothing is the warning switched off, which is the one thing it must never silently become. */
    expect(app(InventorySettings::class)->with(['thinCoverDays' => 0])->thinCoverDays)->toBe(60);
});
