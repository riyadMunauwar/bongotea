<?php

use App\Enums\Courier;
use App\Enums\PaymentMethod;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Shipments\ShipmentResource;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Settings\CourierSettings;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Fulfilment is one working area behind `work orders`, with four acts somebody
 * would regret each held back on top behind a Permission of their own (#157).
 * These assert what a person with, and without, each Permission can reach —
 * never how the gate is wired — through the admin panel over HTTP and Livewire,
 * the primary seam the spec names for a working-area gate.
 *
 * The everyday verbs a person sending parcels uses all day — send with a
 * courier, send a batch, check with the gateway — sit inside the `work orders`
 * floor and need nothing finer; `CourierBookingTest` and `CourierBatchBookingTest`
 * book a real parcel and a real batch under a `workingOrders()` User, which is
 * the fullest proof that neither is gated apart, and one is re-stated cheaply
 * here beside the acts that are.
 */
it('lets a User holding work orders open the queue, an Order and the Shipments list', function () {
    $this->actingAs(User::factory()->workingOrders()->create());

    $order = Order::factory()->create();

    expect(OrderResource::canAccess())->toBeTrue()
        ->and(ShipmentResource::canAccess())->toBeTrue();

    $this->get(OrderResource::getUrl('index'))->assertOk();
    $this->get(OrderResource::getUrl('view', ['record' => $order]))->assertOk();
    $this->get(OrderResource::getUrl('edit', ['record' => $order]))->assertOk();
    $this->get(ShipmentResource::getUrl('index'))->assertOk();
});

it('refuses the queue, an Order and the Shipments list to a User without work orders, and hides both from the sidebar', function () {
    $this->actingAs(User::factory()->create());

    $order = Order::factory()->create();

    expect(OrderResource::canAccess())->toBeFalse()
        ->and(ShipmentResource::canAccess())->toBeFalse();

    $this->get(OrderResource::getUrl('index'))->assertForbidden();
    $this->get(OrderResource::getUrl('view', ['record' => $order]))->assertForbidden();
    $this->get(OrderResource::getUrl('edit', ['record' => $order]))->assertForbidden();
    $this->get(ShipmentResource::getUrl('index'))->assertForbidden();

    $labels = collect(Filament::getCurrentOrDefaultPanel()->getNavigation())
        ->flatMap(fn ($group) => $group->getItems())
        ->map(fn ($item) => $item->getLabel());

    expect($labels)->not->toContain('Orders')
        ->and($labels)->not->toContain('Shipments');
});

/**
 * The everyday verbs ride on the `work orders` floor with nothing finer: a User
 * who holds it is offered sending with a courier, sending a batch and checking
 * with the gateway — batching stays here deliberately, since it is the job for
 * whoever sends parcels.
 */
it('offers the everyday sending verbs and checking with the gateway under work orders alone', function () {
    enableEveryCourier();

    $this->actingAs(User::factory()->workingOrders()->create());

    $unpaid = Order::factory()->create(['payment_method' => PaymentMethod::SslCommerz]);
    Payment::factory()->for($unpaid)->create();

    Livewire::test(ViewOrder::class, ['record' => $unpaid->getKey()])
        ->assertActionVisible('bookWithCourier')
        ->assertActionVisible('checkWithGateway');

    Livewire::test(ListOrders::class)
        ->assertTableBulkActionVisible('bookBatchWithCourier');
});

/**
 * Abandoning a parcel writes a Loss and an Unsold against stock, so it is held
 * back from whoever sends parcels all day: it needs `abandon parcels`. Without
 * it the action is not drawn on an Order that has a parcel out, rather than
 * drawn and refused on click.
 */
it('draws Abandon parcel only for a User holding abandon parcels', function () {
    $order = Order::factory()->create();
    Shipment::factory()->for($order)->create();

    $this->actingAs(User::factory()->workingOrders()->create());
    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertActionHidden('abandonShipment');

    $this->actingAs(User::factory()->abandoningParcels()->create());
    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertActionVisible('abandonShipment');
});

/**
 * Naming which units came back from a half-arrived parcel is the one answer the
 * stock ledger is waiting on, so it needs `answer partly delivered`. Not drawn
 * without it, even on a parcel that is asking the question.
 */
it('draws Say what came back only for a User holding answer partly delivered', function () {
    $order = Order::factory()->create();
    Shipment::factory()->partlyDelivered()->for($order)->create();

    $this->actingAs(User::factory()->workingOrders()->create());
    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertActionHidden('answerPartlyDelivered');

    $this->actingAs(User::factory()->answeringPartlyDelivered()->create());
    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertActionVisible('answerPartlyDelivered');
});

/**
 * Switching how money is collected on a live Order needs
 * `switch to cash on delivery`. Not drawn without it, even on the Unpaid Order
 * the action exists for.
 */
it('draws Switch to Cash on Delivery only for a User holding switch to cash on delivery', function () {
    $unpaid = Order::factory()->create(['payment_method' => PaymentMethod::SslCommerz]);

    $this->actingAs(User::factory()->workingOrders()->create());
    Livewire::test(ViewOrder::class, ['record' => $unpaid->getKey()])
        ->assertActionHidden('switchToCashOnDelivery');

    $this->actingAs(User::factory()->switchingToCashOnDelivery()->create());
    Livewire::test(ViewOrder::class, ['record' => $unpaid->getKey()])
        ->assertActionVisible('switchToCashOnDelivery');
});

/**
 * Deleting, force-deleting and restoring an Order are withheld from a User who
 * only works the queue: the verbs are not drawn on the edit screen or in the
 * table's bulk bar, so the queue cannot be tidied away by whoever is working it.
 * There is no per-row delete action on the Orders table, so the page actions and
 * the bulk bar are the two places the verbs live.
 */
it('draws no delete, force-delete or restore for a User holding only work orders', function () {
    $this->actingAs(User::factory()->workingOrders()->create());

    $live = Order::factory()->create();
    $binned = Order::factory()->create();
    $binned->delete();

    Livewire::test(EditOrder::class, ['record' => $live->getKey()])
        ->assertActionHidden('delete');

    Livewire::test(EditOrder::class, ['record' => $binned->getKey()])
        ->assertActionHidden('forceDelete')
        ->assertActionHidden('restore');

    Livewire::test(ListOrders::class)
        ->assertTableBulkActionHidden('delete');
});

/**
 * A User holding `delete orders` — which carries `work orders` with it, since
 * the verbs are offered only from a screen a User who works Orders can reach —
 * is given all three, in the same places.
 */
it('draws delete, force-delete and restore for a User holding delete orders', function () {
    $this->actingAs(User::factory()->deletingOrders()->create());

    $live = Order::factory()->create();
    $binned = Order::factory()->create();
    $binned->delete();

    Livewire::test(EditOrder::class, ['record' => $live->getKey()])
        ->assertActionVisible('delete');

    Livewire::test(EditOrder::class, ['record' => $binned->getKey()])
        ->assertActionVisible('forceDelete')
        ->assertActionVisible('restore');

    Livewire::test(ListOrders::class)
        ->assertTableBulkActionVisible('delete');
});

/**
 * The Permission check sits on top of each Action's record-state rule rather
 * than replacing it: a User who holds `delete orders` is still shown Delete only
 * on a live Order and Restore only on a binned one, and a User who holds
 * `abandon parcels` is still shown Abandon only where there is a parcel out.
 */
it('keeps the record-state rules on top of the new Permission checks', function () {
    $this->actingAs(User::factory()->deletingOrders()->create());

    $live = Order::factory()->create();
    $binned = Order::factory()->create();
    $binned->delete();

    /** Delete belongs to a live Order, restore to a binned one — the Permission does not blur the two. */
    Livewire::test(EditOrder::class, ['record' => $live->getKey()])
        ->assertActionVisible('delete')
        ->assertActionHidden('restore');

    Livewire::test(EditOrder::class, ['record' => $binned->getKey()])
        ->assertActionHidden('delete')
        ->assertActionVisible('restore');

    /** An Order with no parcel out is asking nobody to abandon anything, however held the Permission. */
    $this->actingAs(User::factory()->abandoningParcels()->create());
    Livewire::test(ViewOrder::class, ['record' => Order::factory()->create()->getKey()])
        ->assertActionHidden('abandonShipment');
});

/**
 * Every Courier on this installation switched on and supplied, so the send
 * dialog has one to offer and the batch action is drawn. Built from each
 * driver's own credential declaration rather than a list here, so a Courier
 * added later is covered the moment it exists.
 */
function enableEveryCourier(): void
{
    $supplied = [];

    foreach (Courier::cases() as $courier) {
        $supplied[CourierSettings::property($courier, 'Enabled')] = true;

        foreach ($courier->driver()->credentialProperties() as $credential) {
            $supplied[$credential] = 'supplied';
        }
    }

    app(CourierSettings::class)->with($supplied)->save();

    app()->forgetInstance(CourierSettings::class);
}
