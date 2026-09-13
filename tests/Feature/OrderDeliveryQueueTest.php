<?php

use App\Enums\Courier;
use App\Enums\ShipmentStatus;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use Livewire\Livewire;

/**
 * The Orders queue, no longer blind to delivery: where each Order's parcel
 * stands without anybody opening it, and which Orders nobody has sent at all.
 *
 * The delivery axis on the queue, as `OrderPaymentsAdminTest` is the payment
 * axis on it: a third badge beside the Order's own status and the payment one,
 * and none of the three ever writes another (ADR-0018). Booking from the row
 * is `CourierBookingTest`'s, which proves it once per Courier alongside the
 * Order's own screens; the timeline an Order's attempts draw is
 * `ShipmentScreensTest`'s.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/*
|--------------------------------------------------------------------------
| Where each parcel stands
|--------------------------------------------------------------------------
*/

it('draws where each order\'s parcel is on the queue, and nothing for an order nobody has sent', function () {
    $sent = Order::factory()->create();

    Shipment::factory()->for($sent)->create([
        'courier' => Courier::Pathao,
        'status' => ShipmentStatus::InTransit,
    ]);

    $unsent = Order::factory()->create();

    Livewire::test(ListOrders::class)
        ->assertCanSeeTableRecords([$sent, $unsent])
        /** The Active Shipment's state, and a blank where there is no Active Shipment to read one off. */
        ->assertTableColumnStateSet('activeShipment.status', ShipmentStatus::InTransit, $sent)
        ->assertTableColumnStateSet('activeShipment.status', null, $unsent)
        /** The Courier under its own word: "on hold" is a different phone call depending on who is holding it. */
        ->assertTableColumnHasDescription('activeShipment.status', Courier::Pathao->getLabel(), $sent)
        ->assertTableColumnHasDescription('activeShipment.status', null, $unsent);
});

it('stops drawing a parcel the courier has finished with', function () {
    $order = Order::factory()->create();

    Shipment::factory()->for($order)->returned()->create();

    /** The column reads the Active Shipment, and an attempt that is over is not one (ADR-0018). */
    Livewire::test(ListOrders::class)
        ->assertTableColumnStateSet('activeShipment.status', null, $order);
});

/*
|--------------------------------------------------------------------------
| The orders nobody has sent
|--------------------------------------------------------------------------
*/

it('asks any tab for the orders nobody has sent to a courier', function () {
    $new = Order::factory()->create();
    $confirmed = Order::factory()->confirmed()->create();
    $cancelled = Order::factory()->cancelled()->create();

    $shipping = Order::factory()->create();
    Shipment::factory()->for($shipping)->create();

    /**
     * The gap parcels fall through silently: an Order captured three days ago
     * that nobody ever sent reads exactly like one booked this morning. The
     * question is the same one from every tab, which is what makes it a
     * cross-status filter rather than a fourth tab.
     */
    Livewire::test(ListOrders::class)
        ->filterTable('not_yet_sent')
        ->assertCanSeeTableRecords([$new])
        ->assertCanNotSeeTableRecords([$shipping])
        ->set('activeTab', 'confirmed')
        ->assertCanSeeTableRecords([$confirmed])
        ->assertCanNotSeeTableRecords([$new, $cancelled, $shipping])
        ->set('activeTab', 'cancelled')
        ->assertCanSeeTableRecords([$cancelled])
        ->assertCanNotSeeTableRecords([$new, $confirmed, $shipping])
        ->set('activeTab', 'all')
        ->assertCanSeeTableRecords([$new, $confirmed, $cancelled])
        ->assertCanNotSeeTableRecords([$shipping]);
});

it('names the tab that swallowed the unsent orders, and offers the whole list instead', function () {
    $confirmed = Order::factory()->confirmed()->create();

    /**
     * Set from the New queue the screen opens on, an empty table reads as
     * "everything has gone out" when what it means is "nothing New is
     * waiting". The cross-status seam is what makes it say so.
     */
    Livewire::test(ListOrders::class)
        ->filterTable('not_yet_sent')
        ->assertCanNotSeeTableRecords([$confirmed])
        ->assertSeeText('No New orders match the filters you have set')
        ->callTableAction('showInAllOrders')
        ->assertSet('activeTab', 'all')
        ->assertCanSeeTableRecords([$confirmed]);
});

it('leaves a delivered order off the list of orders to send', function () {
    $delivered = Order::factory()->create();
    Shipment::factory()->for($delivered)->delivered()->create();

    $partly = Order::factory()->create();
    Shipment::factory()->for($partly)->create(['status' => ShipmentStatus::PartlyDelivered]);

    $unsent = Order::factory()->create();

    /**
     * Neither has an Active Shipment, and neither is an Order to send: a
     * parcel that reached a door finished the job. Read as "no Active
     * Shipment" this list would answer with a whole installation's archive
     * and bury the Orders it exists to surface.
     */
    Livewire::test(ListOrders::class)
        ->filterTable('not_yet_sent')
        ->assertCanSeeTableRecords([$unsent])
        ->assertCanNotSeeTableRecords([$delivered, $partly]);
});

it('offers an order whose only parcel came back as one nobody has sent', function () {
    $order = Order::factory()->create();

    $returned = Shipment::factory()->for($order)->returned()->create();

    /**
     * A parcel that came back is a question — ring the Customer, correct the
     * address, send it again — so the Order rejoins the list of Orders to
     * send rather than counting as sent for ever on the strength of an
     * attempt that failed.
     */
    expect($order->isShipping())->toBeFalse()
        ->and($returned->isActive())->toBeFalse();

    Livewire::test(ListOrders::class)
        ->filterTable('not_yet_sent')
        ->assertCanSeeTableRecords([$order]);
});
