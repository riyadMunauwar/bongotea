<?php

use App\Enums\OrderStatus;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use App\Support\Money;
use Livewire\Livewire;

/*
| The Customers screens under Orders. A Customer is a phone number and
| nothing else, so every figure beside it is read off their Orders: the name,
| shop and District from the latest, the Orders counted whatever became of
| them, and Kilograms and Sales over the Orders Sales counts.
*/
beforeEach(function () {
    $this->actingAs(User::factory()->workingOrders()->create());
});

/**
 * An Order of the Customer's, captured some days ago, weighing what is given.
 *
 * @param  array<string, mixed>  $attributes
 */
function orderOf(Customer $customer, int $daysAgo, int $kilograms = 1, array $attributes = []): Order
{
    $order = Order::factory()->for($customer)->create(['created_at' => now()->subDays($daysAgo), ...$attributes]);

    OrderLine::factory()->for($order)->create(['quantity' => $kilograms]);

    return $order;
}

describe('a customer\'s page', function () {
    it('lists every name, shop and address they gave, newest first, with their figures', function () {
        $customer = Customer::factory()->create();

        orderOf($customer, 30, 4, ['customer_name' => 'Rahim', 'shop_name' => null, 'customer_address' => 'House 4, Banani', 'customer_district' => 'Dhaka']);
        orderOf($customer, 10, 6, ['customer_name' => 'Rahim', 'shop_name' => 'Rahim Store', 'customer_address' => 'Shop 9, Sonadanga', 'customer_district' => 'Khulna']);
        orderOf($customer, 5, 2, ['customer_name' => 'Rahim Uddin', 'status' => OrderStatus::Cancelled]);

        $this->get(CustomerResource::getUrl('view', ['record' => $customer]))
            ->assertOk()
            ->assertSeeInOrder([$customer->phone, 'Rahim Uddin', 'Rahim', 'Rahim Store', 'Shop 9, Sonadanga, Khulna', 'House 4, Banani, Dhaka'])
            ->assertSeeInOrder(['Orders', '3', 'Cancelled', '1', 'Kilograms Sold', '10 kg']);
    });

    /**
     * Sales over the Orders that count towards it: two Orders not Cancelled,
     * so the Cancelled one moves neither side.
     */
    it('works out the average order value over the orders sales counts', function () {
        $customer = Customer::factory()->create();

        $one = orderOf($customer, 3);
        $two = orderOf($customer, 2);
        orderOf($customer, 1, attributes: ['status' => OrderStatus::Cancelled]);

        $sales = $one->fresh()->total + $two->fresh()->total;

        $this->get(CustomerResource::getUrl('view', ['record' => $customer]))
            ->assertSeeInOrder(['Sales', Money::format($sales), 'Average Order Value', Money::format((int) round($sales / 2))]);
    });

    it('offers no way to edit or delete a customer', function () {
        $customer = Customer::factory()->create();

        Livewire::test(ViewCustomer::class, ['record' => $customer->getKey()])
            ->assertActionDoesNotExist('edit')
            ->assertActionDoesNotExist('delete');

        expect(CustomerResource::hasPage('create'))->toBeFalse()
            ->and(CustomerResource::hasPage('edit'))->toBeFalse();
    });
});

describe('a customer\'s orders', function () {
    it('lists every order of theirs, cancelled ones included and deleted ones not, and nobody else\'s', function () {
        $customer = Customer::factory()->create();

        $open = orderOf($customer, 3);
        $cancelled = orderOf($customer, 2, attributes: ['status' => OrderStatus::Cancelled]);
        $deleted = tap(orderOf($customer, 1))->delete();
        $someoneElses = orderOf(Customer::factory()->create(), 1);

        Livewire::test(OrdersRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => ViewCustomer::class])
            ->assertCanSeeTableRecords([$cancelled, $open], inOrder: true)
            ->assertCanNotSeeTableRecords([$deleted, $someoneElses]);
    });

    it('opens each order and offers nothing to create, attach or delete', function () {
        $customer = Customer::factory()->create();
        $order = orderOf($customer, 1, 7);

        Livewire::test(OrdersRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => ViewCustomer::class])
            ->assertTableColumnFormattedStateSet('kilograms', '7 kg', $order)
            ->assertSee(OrderResource::getUrl('view', ['record' => $order]))
            ->assertTableActionDoesNotExist('create')
            ->assertTableActionDoesNotExist('attach')
            ->assertTableActionDoesNotExist('delete');
    });
});

describe('the list', function () {
    it('reads each customer\'s figures off their orders', function () {
        $customer = Customer::factory()->create();

        $first = orderOf($customer, 30, 4, ['customer_name' => 'Rahim', 'shop_name' => null, 'customer_district' => 'Khulna']);
        $latest = orderOf($customer, 2, 6, ['customer_name' => 'Rahim', 'shop_name' => 'Rahim Store', 'customer_district' => 'Dhaka']);

        /** A Cancelled Order is an Order, but nothing in it was sold; a deleted one is gone. */
        orderOf($customer, 5, 50, ['status' => OrderStatus::Cancelled]);
        orderOf($customer, 0, 9)->delete();

        Livewire::test(ListCustomers::class)
            ->assertCanSeeTableRecords([$customer])
            ->assertTableColumnStateSet('latest_name', 'Rahim', $customer)
            ->assertTableColumnStateSet('latest_shop', 'Rahim Store', $customer)
            ->assertTableColumnStateSet('latest_district', 'Dhaka', $customer)
            ->assertTableColumnStateSet('orders_count', 3, $customer)
            ->assertTableColumnStateSet('kilograms', 10, $customer)
            ->assertTableColumnStateSet('sales', $first->fresh()->total + $latest->fresh()->total, $customer)
            ->assertTableColumnStateSet('first_ordered_at', $first->created_at, $customer)
            ->assertTableColumnStateSet('last_ordered_at', $latest->created_at, $customer);
    });

    /**
     * The number still exists though every Order it placed was deleted, so
     * the Customer stays on the list, with nothing to its name.
     */
    it('keeps a customer whose orders were all deleted, with nothing beside them', function () {
        $customer = Customer::factory()->create();
        orderOf($customer, 3)->delete();

        Livewire::test(ListCustomers::class)
            ->assertCanSeeTableRecords([$customer])
            ->assertTableColumnStateSet('orders_count', 0, $customer)
            ->assertTableColumnStateSet('sales', 0, $customer)
            ->assertTableColumnStateSet('last_ordered_at', null, $customer);
    });

    it('lists the customer who ordered last first', function () {
        $quiet = Customer::factory()->create();
        $recent = Customer::factory()->create();

        $gone = Customer::factory()->create();

        orderOf($quiet, 20);
        orderOf($recent, 1);
        orderOf($gone, 0)->delete();

        /** A Customer with no Order left has no last order at all, and comes last on every database. */
        Livewire::test(ListCustomers::class)
            ->assertCanSeeTableRecords([$recent, $quiet, $gone], inOrder: true);
    });

    it('finds a customer by phone, by a name they gave or by their shop', function (Closure $term) {
        $rahim = Customer::factory()->create();
        $karim = Customer::factory()->create();

        orderOf($rahim, 3, attributes: ['customer_name' => 'Rahim Uddin', 'shop_name' => 'Uddin Tea House']);
        orderOf($karim, 3, attributes: ['customer_name' => 'Karim', 'shop_name' => null]);

        Livewire::test(ListCustomers::class)
            ->searchTable($term($rahim))
            ->assertCanSeeTableRecords([$rahim])
            ->assertCanNotSeeTableRecords([$karim]);
    })->with([
        'phone' => [fn (Customer $customer): string => substr($customer->phone, -6)],
        'name, in any case' => [fn (): string => 'rahim'],
        'shop, in any case' => [fn (): string => 'TEA HOUSE'],
    ]);

    it('is refused to a user who does not work orders', function () {
        $this->actingAs(User::factory()->viewingReports()->create());

        $this->get(CustomerResource::getUrl())->assertForbidden();
    });
});
