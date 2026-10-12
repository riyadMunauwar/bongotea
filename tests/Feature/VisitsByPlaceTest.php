<?php

use App\Enums\PlaceKind;
use App\Enums\SystemPage;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Page;
use App\Models\PlaceVisitCount;
use App\Models\Product;
use App\Models\VisitCount;
use App\Support\Reporting\ReportingPreset;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\ReportingWindow;
use App\Support\Reporting\VisitsByPlaceRow;
use App\Support\Settings\TrackingSettings;

/**
 * Where the Visits went: the same loads the store counts, broken down by the
 * Page, Category or Product that was loaded, with Search and the Checkout as
 * one place each. Driven through the real beacon and read back through the
 * reporting seam.
 */
beforeEach(function () {
    app(TrackingSettings::class)->with(['countsVisits' => true])->save();
});

/**
 * A Visit reported from one address of the store.
 */
function visitFrom(string $path, string $identifier = 'load-3f9a2c'): void
{
    reportToBeacon(identifier: $identifier, headers: ['Referer' => url($path)])->assertNoContent();
}

/**
 * Today's Visits, place by place, as the Merchant reads them.
 *
 * @return list<VisitsByPlaceRow>
 */
function placesVisitedToday(): array
{
    return app(ReportingQuery::class)->visitsByPlace(ReportingWindow::of(ReportingPreset::Today))->rows;
}

function orderHolding(Product $product, Order $order): Order
{
    OrderLine::factory()->for($order)->create(['product_id' => $product->getKey(), 'product_name' => $product->name]);

    return $order;
}

it('counts a visit against the product whose page was loaded', function () {
    $product = Product::factory()->create(['slug' => 'gold-blend']);

    visitFrom('/tea/gold-blend', 'load-one');
    visitFrom('/tea/gold-blend', 'load-two');

    $rows = placesVisitedToday();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->kind)->toBe(PlaceKind::Product)
        ->and($rows[0]->subject?->is($product))->toBeTrue()
        ->and($rows[0]->visits)->toBe(2);
});

it('counts a visit against the page, the category, search and the checkout', function () {
    $home = Page::factory()->published()->system(SystemPage::Home)->create(['title' => 'Home']);
    $about = Page::factory()->published()->create(['slug' => 'about-us', 'title' => 'About us']);
    $gold = Category::factory()->create(['slug' => 'gold', 'name' => 'Gold']);

    visitFrom('/');
    visitFrom('/about-us');
    visitFrom('/category/gold');
    visitFrom('/search?q=premium');
    visitFrom('/search?q=gold');
    visitFrom('/checkout');

    $places = collect(placesVisitedToday())->mapWithKeys(fn (VisitsByPlaceRow $row): array => [$row->name() => $row->visits]);

    expect($places->all())->toEqualCanonicalizing([
        'Home' => 1,
        'About us' => 1,
        'Gold' => 1,
        'Search' => 2,
        'Checkout' => 1,
    ]);
});

/**
 * The place is the record and not its address, so a Category given a new
 * Slug is one row before and after.
 */
it('keeps a place\'s history whole when its slug changes', function () {
    $category = Category::factory()->create(['slug' => 'gold']);

    visitFrom('/category/gold', 'before');

    $category->update(['slug' => 'gold-grade']);

    visitFrom('/category/gold-grade', 'after');

    $rows = placesVisitedToday();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->visits)->toBe(2);
});

it('counts a load of no place towards the store and no place', function (string $path) {
    Page::factory()->create(['slug' => 'draft-page']);

    visitFrom($path);

    $places = app(ReportingQuery::class)->visitsByPlace(ReportingWindow::of(ReportingPreset::Today));

    expect($places->visits)->toBe(1)
        ->and($places->rows)->toBe([])
        ->and($places->unmatched())->toBe(1);
})->with([
    'an address nothing holds' => ['/tea/no-such-tea'],
    'a draft page' => ['/draft-page'],
    'an address on no storefront route' => ['/sitemap.xml'],
    'the order complete page' => ['/order/BT-1001'],
]);

describe('what each place comes to', function () {
    it('ranks the places by visits, each beside its share of the store and its visits the day before', function () {
        $gold = Product::factory()->create(['name' => 'Gold Blend']);
        $silver = Product::factory()->create(['name' => 'Silver Leaf']);

        VisitCount::factory()->on(0, 100)->create();
        PlaceVisitCount::factory()->of($silver)->on(0, 20)->create();
        PlaceVisitCount::factory()->of($gold)->on(0, 50)->create();
        PlaceVisitCount::factory()->of($gold)->on(1, 30)->create();

        $places = app(ReportingQuery::class)->visitsByPlace(ReportingWindow::of(ReportingPreset::Today));

        expect(array_map(fn (VisitsByPlaceRow $row): string => $row->name(), $places->rows))->toBe(['Gold Blend', 'Silver Leaf'])
            ->and($places->rows[0]->shareOf($places->visits))->toBe(0.5)
            ->and($places->rows[0]->previousVisits)->toBe(30)
            ->and($places->rows[1]->previousVisits)->toBe(0)
            ->and($places->unmatched())->toBe(30);
    });

    /**
     * Two counts side by side: Orders holding the Product, Cancelled ones
     * included, over that Product's Visits. Nobody is followed from one to
     * the other, so a phone Order counts and the rate may pass one.
     */
    it('reads a product\'s buy rate as the orders holding it over its visits', function () {
        $gold = Product::factory()->create();
        $silver = Product::factory()->create();

        PlaceVisitCount::factory()->of($gold)->on(0, 4)->create();
        PlaceVisitCount::factory()->of($silver)->on(0, 1)->create();

        orderHolding($gold, Order::factory()->create(['created_at' => now()]));
        orderHolding($gold, Order::factory()->cancelled()->create(['created_at' => now()]));
        orderHolding($silver, Order::factory()->create(['created_at' => now()]));
        orderHolding($silver, Order::factory()->create(['created_at' => now()]));

        /** An Order from last week holds Gold too, and is outside the window. */
        orderHolding($gold, Order::factory()->create(['created_at' => now()->subWeek()]));

        $rates = collect(placesVisitedToday())->mapWithKeys(fn (VisitsByPlaceRow $row): array => [$row->subject->getKey() => $row->buyRate()]);

        expect($rates->get($gold->getKey()))->toBe(0.5)
            ->and($rates->get($silver->getKey()))->toBe(2.0);
    });

    it('gives no place but a product a buy rate', function () {
        PlaceVisitCount::factory()->on(0, 9)->create();

        expect(placesVisitedToday()[0]->kind)->toBe(PlaceKind::Search)
            ->and(placesVisitedToday()[0]->buyRate())->toBeNull();
    });
});

it('counts nothing at any place while visit counting is off', function () {
    app(TrackingSettings::class)->with(['countsVisits' => false])->save();
    Product::factory()->create(['slug' => 'gold-blend']);

    visitFrom('/tea/gold-blend');

    $places = app(ReportingQuery::class)->visitsByPlace(ReportingWindow::of(ReportingPreset::Today));

    expect(PlaceVisitCount::count())->toBe(0)
        ->and($places->isCounted())->toBeFalse()
        ->and($places->unmatched())->toBeNull();
});
