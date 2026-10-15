<?php

use App\Enums\OrderKind;
use App\Enums\TrackingMoment;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\Reporting\ReportingPreset;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\ReportingWindow;
use Livewire\Livewire;
use Tests\Fixtures\Tracking\ProviderScript;

/**
 * The Merchant can see what each Cart brought in (ADR-0038): the Orders list
 * shows and filters by the Cart an Order came from, Reports narrow to one
 * Cart, and every Moment names its Cart beside its kind.
 */
beforeEach(function () {
    $this->everyday = Cart::factory()->bulk()->create(['name' => 'পাইকারি অর্ডার করুন']);
    $this->eid = Cart::factory()->bulk()->create(['name' => 'Eid Gift Packs']);
});

function sweptEventOf(ProviderScript $script): array
{
    $script->fakeAccepted();
    test()->artisan('tracking:sweep')->assertSuccessful();

    return $script->sentEvents()[0];
}

describe('the Orders list', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->owner()->create());
    });

    it('shows the Cart each Order came from, under the name it bore at Capture', function () {
        $order = Order::factory()->create(['cart_id' => $this->eid->id, 'cart_name' => 'Eid Gift Packs']);
        $this->eid->update(['name' => 'Eid 2027']);

        Livewire::test(ListOrders::class)
            ->set('activeTab', 'all')
            ->assertTableColumnStateSet('cart_name', 'Eid Gift Packs', $order);
    });

    it('filters to one Cart\'s Orders, a deleted Cart\'s among them', function () {
        $fromEid = Order::factory()->create(['cart_id' => $this->eid->id, 'cart_name' => $this->eid->name]);
        $fromEveryday = Order::factory()->create(['cart_id' => $this->everyday->id, 'cart_name' => $this->everyday->name]);
        $this->eid->delete();

        Livewire::test(ListOrders::class)
            ->set('activeTab', 'all')
            ->filterTable('cart', $this->eid->id)
            ->assertCanSeeTableRecords([$fromEid])
            ->assertCanNotSeeTableRecords([$fromEveryday]);
    });
});

describe('Reports', function () {
    beforeEach(function () {
        $this->travelTo(reportingNow());
    });

    it('count only one Cart\'s Orders once narrowed to it, and every Cart\'s otherwise', function (?string $cart, int $orders, int $sales) {
        orderCapturedAt('2026-09-08 11:00', 50000, ['cart_id' => $this->everyday->id]);
        orderCapturedAt('2026-09-08 12:00', 80000, ['cart_id' => $this->eid->id]);

        $window = ReportingWindow::of(ReportingPreset::Last7Days, reportingNow())->forCart($cart === null ? null : $this->{$cart}->id);
        $figures = app(ReportingQuery::class)->overview($window)->figures;

        expect($figures->orders)->toBe($orders)
            ->and($figures->sales)->toBe($sales);
    })->with([
        'every Cart' => [null, 2, 50000 + 6000 + 80000 + 6000],
        'one Cart' => ['eid', 1, 80000 + 6000],
    ]);

    it('read the Cart from a screen\'s filters', function () {
        expect(ReportingWindow::fromFilters([ReportingWindow::CART_FILTER => (string) $this->eid->id])->cartId)->toBe($this->eid->id)
            ->and(ReportingWindow::fromFilters([ReportingWindow::CART_FILTER => 'nonsense'])->cartId)->toBeNull();
    });

    it('count Orders captured before Carts existed under the starting Cart of their kind', function () {
        $migration = require database_path('migrations/2026_10_15_102457_add_cart_to_orders_table.php');
        $migration->down();

        $before = orderCapturedAt('2026-09-08 11:00', 50000, ['kind' => OrderKind::Retail]);

        $migration->up();

        $starting = Cart::startingFor(OrderKind::Retail);
        $window = ReportingWindow::of(ReportingPreset::Last7Days, reportingNow())->forCart($starting->id);

        expect($before->refresh()->cart_id)->toBe($starting->id)
            ->and(app(ReportingQuery::class)->overview($window)->figures->orders)->toBe(1);
    });
});

describe('Moments', function () {
    it('name the Cart an Added to Cart came from, by the store\'s own name for it', function (ProviderScript $script) {
        $script->switchOn();
        $tea = Product::factory()->create();

        reportToBeacon(TrackingMoment::AddedToCart, 'contents-1-'.$tea->id, ['cart_id' => (string) $this->eid->id, 'cart_name' => 'Forged', 'items' => $tea->id.':2'])->assertNoContent();

        $sent = sweptEventOf($script);

        expect($script->cartOf($sent))->toBe('bulk')
            ->and($script->namedCartOf($sent))->toBe(['id' => $this->eid->id, 'name' => 'Eid Gift Packs']);
    })->with('tracking providers');

    it('name the Cart an Order was captured from, under the name it bore then', function (ProviderScript $script) {
        $script->switchOn();
        $order = orderAsCaptured();
        $order->update(['cart_id' => $this->eid->id, 'cart_name' => 'Eid Gift Packs']);
        $this->eid->update(['name' => 'Eid 2027']);

        recordCaptureOf($order->refresh());

        $sent = sweptEventOf($script);

        expect($script->namedCartOf($sent))->toBe(['id' => $this->eid->id, 'name' => 'Eid Gift Packs']);
    })->with('tracking providers');

    it('leave the Cart out where none is named, keeping the kind', function (ProviderScript $script) {
        $script->switchOn();
        $tea = Product::factory()->create();

        reportToBeacon(TrackingMoment::AddedToCart, 'contents-2-'.$tea->id, ['items' => $tea->id.':1'])->assertNoContent();

        $sent = sweptEventOf($script);

        expect($script->cartOf($sent))->toBe('bulk')
            ->and($script->namedCartOf($sent))->toBe(['id' => null, 'name' => null]);
    })->with('tracking providers');
});
