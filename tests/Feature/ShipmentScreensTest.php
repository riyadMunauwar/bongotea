<?php

use App\Enums\Courier;
use App\Enums\ShipmentStatus;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Shipments\Pages\ListShipments;
use App\Filament\Resources\Shipments\Pages\ViewShipment;
use App\Filament\Resources\Shipments\Schemas\ShipmentTimeline;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Support\Money;
use Livewire\Livewire;
use Tests\Fixtures\Couriers\CourierScript;

/**
 * The two screens a parcel is read on: the Shipments list a User finds one
 * among thousands from, and the attempt itself — what was sent, what the
 * Courier answered, and its own timeline.
 *
 * The Order's screens draw the same timeline across every attempt it took,
 * grouped and labelled, so a parcel that came back under one Courier and was
 * re-sent under another reads as the two attempts it was (ADR-0018).
 *
 * The per-Courier cases run over `tests/Datasets/CourierScripts.php`, so a
 * Courier added later is a row rather than a file, and each screen is proved
 * against that Courier's own vocabulary rather than against one invented here.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/*
|--------------------------------------------------------------------------
| Finding one parcel among thousands
|--------------------------------------------------------------------------
*/

it('lists every parcel and finds one by the name its courier knows it under', function () {
    $wanted = Shipment::factory()->create(['consignment_reference' => '7788991']);
    $other = Shipment::factory()->create(['consignment_reference' => '1122334']);

    Livewire::test(ListShipments::class)
        ->assertCanSeeTableRecords([$wanted, $other])
        ->searchTable('7788991')
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$other]);
});

it('finds every attempt made on an order by the order\'s own number', function () {
    $order = Order::factory()->create();

    $first = Shipment::factory()->for($order)->returned()->create();
    $second = Shipment::factory()->for($order)->create();
    $elsewhere = Shipment::factory()->create();

    /** Our reference carries the order number inside it, so the queue's number finds the parcels without a join. */
    Livewire::test(ListShipments::class)
        ->searchTable($order->order_number)
        ->assertCanSeeTableRecords([$first, $second])
        ->assertCanNotSeeTableRecords([$elsewhere]);
});

it('narrows the list to one courier and to one canonical status', function () {
    $held = Shipment::factory()->create(['courier' => Courier::Pathao, 'status' => ShipmentStatus::OnHold]);
    $delivered = Shipment::factory()->delivered()->create(['courier' => Courier::Pathao]);
    $elsewhere = Shipment::factory()->create(['courier' => Courier::RedEx, 'status' => ShipmentStatus::OnHold]);

    Livewire::test(ListShipments::class)
        ->filterTable('courier', Courier::Pathao->value)
        ->assertCanSeeTableRecords([$held, $delivered])
        ->assertCanNotSeeTableRecords([$elsewhere])
        ->filterTable('status', ShipmentStatus::OnHold->value)
        ->assertCanSeeTableRecords([$held])
        ->assertCanNotSeeTableRecords([$delivered, $elsewhere]);
});

it('sorts the list by when each parcel was handed over', function () {
    $older = Shipment::factory()->create(['booked_at' => now()->subDays(3)]);
    $newer = Shipment::factory()->create(['booked_at' => now()]);

    /** Newest first without being asked: a parcel list is a work queue, like the Orders one. */
    Livewire::test(ListShipments::class)
        ->assertCanSeeTableRecords([$newer, $older], inOrder: true)
        ->sortTable('booked_at')
        ->assertCanSeeTableRecords([$older, $newer], inOrder: true);
});

/*
|--------------------------------------------------------------------------
| One attempt, studied on its own
|--------------------------------------------------------------------------
*/

it('opens a parcel screen for every courier, in that courier\'s own words', function (CourierScript $script) {
    /** With what this Courier alone was told about the parcel written on the row as sent, which is what the screen draws it from. */
    $shipment = parcelInFlight($script, [
        'courier_charge' => 6000,
        'parcel_details' => $script->parcelRowFor($script->parcelDefaults()),
    ]);

    $shipment->recordEvent(ShipmentStatus::Booked, $shipment->raw_status);

    $screen = Livewire::test(ViewShipment::class, ['record' => $shipment->getKey()])
        ->assertSeeText($shipment->invoice_reference)
        ->assertSeeText($script->consignmentReference())
        ->assertSeeText($script->trackingCode())
        ->assertSeeText($script->courier()->getLabel())
        ->assertSeeText($shipment->recipient_name)
        ->assertSeeText($shipment->recipient_address)
        /** What it was to collect, and what the Courier charged to carry it. */
        ->assertSeeText(Money::format($shipment->collect_amount))
        ->assertSeeText(Money::format(6000))
        /** Its own timeline, in the Courier's own word for where the parcel stands. */
        ->assertSeeText($script->rawStatusesFor(ShipmentStatus::Booked)[0]);

    /** And what this Courier was told about the parcel that no other Courier asks. */
    foreach ($script->parcelRowFor($script->parcelDefaults()) as $answer) {
        $screen->assertSeeText($answer);
    }
})->with('courier scripts');

it('says so where nobody has been able to account for a parcel in a week', function () {
    $stuck = Shipment::factory()->create(['stuck_at' => now()->subDays(2)]);

    Livewire::test(ViewShipment::class, ['record' => $stuck->getKey()])
        ->assertSeeText('Stuck');

    /** Neither a status nor an outcome, so a parcel that is merely moving must not read as one. */
    Livewire::test(ViewShipment::class, ['record' => Shipment::factory()->create()->getKey()])
        ->assertDontSeeText('Stuck');
});

it('says who wrote a parcel off and when, beside what the courier last said about it', function () {
    $user = User::factory()->create(['name' => 'Nusrat Jahan']);

    $abandoned = Shipment::factory()->abandoned()->create([
        'abandoned_by' => $user->getKey(),
        'abandoned_at' => now()->subDay(),
        'stuck_at' => now()->subDays(9),
    ]);

    Livewire::test(ViewShipment::class, ['record' => $abandoned->getKey()])
        ->assertSeeText('Abandoned')
        ->assertSeeText('Nusrat Jahan')

        /** The Courier's own last word is the whole reason abandoning is a time and a name (ADR-0018). */
        ->assertSeeText(ShipmentStatus::OnHold->getLabel())
        ->assertSeeText('hold')

        /** And the parcel is nobody's to chase any more, so the older fact gives way to the decision that answered it. */
        ->assertDontSeeText('Stuck');
});

it('says a half-arrived parcel is waiting on an answer, and who gave it once somebody has', function () {
    $user = User::factory()->create(['name' => 'Nusrat Jahan']);

    $halfArrived = Shipment::factory()->partlyDelivered()->create();

    /** The State badge says half of it arrived; nothing but this says the stock on it is still a question (ADR-0021). */
    Livewire::test(ViewShipment::class, ['record' => $halfArrived->getKey()])
        ->assertSeeText('nobody has said which items came back')
        ->assertSeeText('Open the order and say what came back');

    $halfArrived->forceFill(['answered_at' => now(), 'answered_by' => $user->getKey()])->save();

    Livewire::test(ViewShipment::class, ['record' => $halfArrived->refresh()->getKey()])
        ->assertSeeText('what came back has been recorded')
        ->assertSeeText('Nusrat Jahan')
        ->assertDontSeeText('nobody has said which items came back');

    /** A parcel that arrived whole is asking nothing. */
    Livewire::test(ViewShipment::class, ['record' => Shipment::factory()->delivered()->create()->getKey()])
        ->assertDontSeeText('Half arrived');
});

it('says a charge has not been named rather than reading it as free', function () {
    $shipment = Shipment::factory()->create(['courier_charge' => null]);

    Livewire::test(ViewShipment::class, ['record' => $shipment->getKey()])
        ->assertSeeText('Not said yet');
});

/*
|--------------------------------------------------------------------------
| An Order's whole delivery history
|--------------------------------------------------------------------------
*/

it('reads an order\'s attempts as the separate attempts they were, each labelled with its courier', function (string $page) {
    $order = Order::factory()->create();

    $returned = Shipment::factory()->for($order)->returned()->create(['courier' => Courier::Steadfast]);
    $returned->recordEvent(ShipmentStatus::Returned, 'cancelled');

    $resent = Shipment::factory()->for($order)->create(['courier' => Courier::Pathao]);
    $resent->recordEvent(ShipmentStatus::InTransit, 'In_Transit');

    Livewire::test($page, ['record' => $order->getKey()])
        ->assertSeeText('Delivery attempts')
        /** Two attempts, each under the name of the Courier that carried it. */
        ->assertSeeText(Courier::Steadfast->getLabel())
        ->assertSeeText(Courier::Pathao->getLabel())
        ->assertSeeText($returned->invoice_reference)
        ->assertSeeText($resent->invoice_reference)
        /** And each attempt's own history under it, in each Courier's own words. */
        ->assertSeeText('cancelled')
        ->assertSeeText('In_Transit');
})->with([ViewOrder::class, EditOrder::class]);

it('says nothing about delivery on an order that has never been sent', function () {
    $order = Order::factory()->create();

    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertDontSeeText('Delivery attempts');
});

/*
|--------------------------------------------------------------------------
| The two times, and the courier's own word
|--------------------------------------------------------------------------
*/

it('dates an entry by the courier where the courier said so, and by us where it did not', function () {
    $shipment = Shipment::factory()->create();

    $said = ShipmentEvent::factory()->for($shipment)->create([
        'status' => ShipmentStatus::InTransit,
        'raw_status' => 'In_Transit',
        'occurred_at' => now()->subDays(3),
        'recorded_at' => now(),
    ]);

    $found = ShipmentEvent::factory()->for($shipment)->create([
        'status' => ShipmentStatus::OnHold,
        'raw_status' => 'hold',
        'occurred_at' => null,
        'recorded_at' => now()->subDay(),
    ]);

    Livewire::test(ViewShipment::class, ['record' => $shipment->getKey()])
        ->assertSeeText(ShipmentTimeline::COURIERS_TIME)
        ->assertSeeText(ShipmentTimeline::OUR_TIME)
        /** The Courier's own instant where it gave one — never the moment we happened to ask. */
        ->assertSeeText($said->occurred_at->format('M j, Y'))
        ->assertSeeText($found->recorded_at->format('M j, Y'));
});

it('keeps the courier\'s raw status word where a user can quote it back to them', function (string $page) {
    $order = Order::factory()->create();

    $shipment = Shipment::factory()->for($order)->create(['courier' => Courier::RedEx]);

    /** RedEx's own word for a parcel it is holding, which is nothing our own seven states spell. */
    $shipment->recordEvent(ShipmentStatus::OnHold, 'pickup-failed');

    Livewire::test($page, ['record' => $order->getKey()])
        ->assertSeeText('pickup-failed');

    Livewire::test(ViewShipment::class, ['record' => $shipment->getKey()])
        ->assertSeeText('pickup-failed')
        ->assertSeeText(ShipmentStatus::OnHold->getLabel());
})->with([ViewOrder::class, EditOrder::class]);

it('says so where a courier reported a state in no words of its own', function () {
    $shipment = Shipment::factory()->create();

    $shipment->recordEvent(ShipmentStatus::Booked);

    Livewire::test(ViewShipment::class, ['record' => $shipment->getKey()])
        ->assertSeeText(ShipmentTimeline::NOTHING_SAID);
});
