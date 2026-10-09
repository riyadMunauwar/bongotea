<?php

use App\Enums\SystemPage;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Page;
use App\Models\Shipment;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The My Orders Block: a sign-in form for a stranger, and for a signed-in
 * Customer every Order placed with their number, newest first, with where
 * each one stands â€” its Order Progress, worked out from the Order and its
 * Shipments and never naming a Consignment.
 */
beforeEach(function () {
    Page::factory()->system(SystemPage::MyOrders)->withBlocks([
        ['type' => 'my_orders', 'data' => ['heading' => 'à¦†à¦®à¦¾à¦° à¦…à¦°à§à¦¡à¦¾à¦°', 'text' => 'à¦®à§‹à¦¬à¦¾à¦‡à¦² à¦¨à¦®à§à¦¬à¦° à¦¦à¦¿à¦¯à¦¼à§‡ à¦¢à§à¦•à§à¦¨à¥¤']],
    ])->create();
});

function customerOf(string $phone): Customer
{
    return Customer::factory()->create(['phone' => $phone]);
}

it('offers a stranger the sign-in form and no Orders', function () {
    Order::factory()->for(customerOf('+8801712345678'))->create();

    $this->get('/my-orders')
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.blocks.0.type', 'my_orders')
            ->where('page.blocks.0.props', [
                'heading' => 'à¦†à¦®à¦¾à¦° à¦…à¦°à§à¦¡à¦¾à¦°',
                'text' => 'à¦®à§‹à¦¬à¦¾à¦‡à¦² à¦¨à¦®à§à¦¬à¦° à¦¦à¦¿à¦¯à¦¼à§‡ à¦¢à§à¦•à§à¦¨à¥¤',
                'phone' => null,
                'orders' => [],
            ])
        );
});

it('lists every Order placed with the signed-in number, newest first, and no other', function () {
    $customer = customerOf('+8801712345678');

    $this->travelTo('2026-09-02 10:00');
    $older = Order::factory()->for($customer)->create(['total_kilograms' => 10]);
    OrderLine::factory()->for($older)->create(['product_name' => 'Gold', 'quantity' => 10]);

    $this->travelTo('2026-10-03 16:00');
    $newer = Order::factory()->for($customer)->create(['total_kilograms' => 12]);
    OrderLine::factory()->for($newer)->create(['product_name' => 'Premium', 'quantity' => 8]);
    OrderLine::factory()->for($newer)->create(['product_name' => 'Strong', 'quantity' => 4]);

    Order::factory()->for(customerOf('+8801812345678'))->create();

    signInAs('01712345678');

    $this->get('/my-orders')
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.blocks.0.props.phone', '01712345678')
            ->where('page.blocks.0.props.orders', [
                [
                    'number' => $newer->order_number,
                    'placedOn' => '2026-10-03',
                    'lines' => [['name' => 'Premium', 'kilograms' => 8], ['name' => 'Strong', 'kilograms' => 4]],
                    'kilograms' => 12,
                    'total' => $newer->fresh()->total,
                    'progress' => 'received',
                ],
                [
                    'number' => $older->order_number,
                    'placedOn' => '2026-09-02',
                    'lines' => [['name' => 'Gold', 'kilograms' => 10]],
                    'kilograms' => 10,
                    'total' => $older->fresh()->total,
                    'progress' => 'received',
                ],
            ])
        );
});

it('dates an Order by the Merchant\'s day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 20:00', 'UTC'));
    Order::factory()->for(customerOf('+8801712345678'))->create();

    signInAs('01712345678');

    $this->get('/my-orders')
        ->assertInertia(fn (Assert $page) => $page->where('page.blocks.0.props.orders.0.placedOn', '2026-10-03'));
});

it('shows a signed-in number with no Orders an empty list', function () {
    signInAs('01712345678');

    $this->get('/my-orders')
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.blocks.0.props.phone', '01712345678')
            ->where('page.blocks.0.props.orders', [])
        );

    expect(Customer::query()->count())->toBe(0);
});

it('works out where an Order stands from the Order and its Shipments', function (Closure $order, string $progress) {
    $order(customerOf('+8801712345678'));

    signInAs('01712345678');

    $this->get('/my-orders')
        ->assertInertia(fn (Assert $page) => $page->where('page.blocks.0.props.orders.0.progress', $progress));
})->with([
    'new' => [fn (Customer $customer) => Order::factory()->for($customer)->create(), 'received'],
    'confirmed' => [fn (Customer $customer) => Order::factory()->for($customer)->confirmed()->create(), 'confirmed'],
    'awaiting dispatch' => [fn (Customer $customer) => tap(Order::factory()->for($customer)->confirmed()->create(), fn (Order $order) => Shipment::factory()->for($order)->create(['status' => 'written', 'consignment_reference' => null, 'booked_at' => null])), 'sent'],
    'booked' => [fn (Customer $customer) => tap(Order::factory()->for($customer)->confirmed()->create(), fn (Order $order) => Shipment::factory()->for($order)->create()), 'sent'],
    'in transit' => [fn (Customer $customer) => tap(Order::factory()->for($customer)->confirmed()->create(), fn (Order $order) => Shipment::factory()->for($order)->create(['status' => 'in_transit'])), 'sent'],
    'on hold' => [fn (Customer $customer) => tap(Order::factory()->for($customer)->confirmed()->create(), fn (Order $order) => Shipment::factory()->for($order)->create(['status' => 'on_hold'])), 'sent'],
    'delivered' => [fn (Customer $customer) => tap(Order::factory()->for($customer)->confirmed()->create(), fn (Order $order) => Shipment::factory()->for($order)->delivered()->create()), 'delivered'],
    'partly delivered' => [fn (Customer $customer) => tap(Order::factory()->for($customer)->confirmed()->create(), fn (Order $order) => Shipment::factory()->for($order)->partlyDelivered()->create()), 'delivered'],
    'returned' => [fn (Customer $customer) => tap(Order::factory()->for($customer)->confirmed()->create(), fn (Order $order) => Shipment::factory()->for($order)->returned()->create()), 'confirmed'],
    'abandoned' => [fn (Customer $customer) => tap(Order::factory()->for($customer)->confirmed()->create(), fn (Order $order) => Shipment::factory()->for($order)->abandoned()->create()), 'confirmed'],
    'refused by the courier' => [fn (Customer $customer) => tap(Order::factory()->for($customer)->confirmed()->create(), fn (Order $order) => Shipment::factory()->for($order)->refused()->create()), 'confirmed'],
    'returned and sent again' => [fn (Customer $customer) => tap(Order::factory()->for($customer)->confirmed()->create(), function (Order $order) {
        Shipment::factory()->for($order)->returned()->create();
        Shipment::factory()->for($order)->create();
    }), 'sent'],
    'returned and delivered at the second attempt' => [fn (Customer $customer) => tap(Order::factory()->for($customer)->confirmed()->create(), function (Order $order) {
        Shipment::factory()->for($order)->returned()->create();
        Shipment::factory()->for($order)->delivered()->create();
    }), 'delivered'],
    'cancelled' => [fn (Customer $customer) => Order::factory()->for($customer)->cancelled()->create(), 'cancelled'],
    'cancelled after a parcel came back' => [fn (Customer $customer) => tap(Order::factory()->for($customer)->cancelled()->create(), fn (Order $order) => Shipment::factory()->for($order)->returned()->create()), 'cancelled'],
]);

it('never names a Consignment', function () {
    $order = Order::factory()->for(customerOf('+8801712345678'))->confirmed()->create();
    $shipment = Shipment::factory()->for($order)->create(['consignment_reference' => '7654321', 'tracking_code' => 'TRAK1234']);

    signInAs('01712345678');

    $this->get('/my-orders')
        ->assertOk()
        ->assertDontSee($shipment->consignment_reference)
        ->assertDontSee($shipment->tracking_code);
});
