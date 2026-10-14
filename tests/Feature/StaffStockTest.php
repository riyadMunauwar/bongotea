<?php

use App\Enums\AdjustmentCause;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\Schemas\OrderForm;
use App\Models\Order;
use App\Models\Pack;
use App\Models\Product;
use App\Models\User;
use App\Support\Inventory\StockLedger;
use App\Support\Orders\OrderCapture;
use App\Support\Orders\Repricing;
use App\Support\Orders\RetailRepricing;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Staff and stock (ADR-0037): a User taking an Order by phone or repricing one
 * is held to Free to Sell as a Customer is, less the Order's own weight, and
 * never refused for taking weight off. A User holding `sell beyond stock`
 * passes by giving a reason, kept on the Order with their name. No Permission
 * passes an Order Limit.
 *
 * Assam throughout: ৳500 a kilogram flat in 1 kg Packs, and a 250 g খুচরা Pack.
 */
beforeEach(function () {
    $this->assam = Product::factory()->bothWays()->create(['name' => 'Assam', 'price' => 50000]);
    $this->quarter = Pack::factory()->retailOnly()->for($this->assam)->create(['grams' => 250, 'mrp' => 14000, 'price' => 14000]);
    $this->counter = User::factory()->countingStock()->create();
});

/** Somebody counted this many kilograms of Assam on the shelf. */
function shelveAssam(int $kilograms): void
{
    app(StockLedger::class)->recordCount(test()->assam, $kilograms * 1000, AdjustmentCause::OpeningCount, null, test()->counter);
}

/** A bulk Order for Assam as the Checkout captures it. */
function assamOrdered(int $kilograms): Order
{
    return app(OrderCapture::class)->capture(
        [['product' => test()->assam, 'kilograms' => $kilograms]],
        ['name' => 'Rahim', 'phone' => '01712345678', 'address' => 'Banani', 'district' => 'Dhaka', 'payment_method' => PaymentMethod::CashOnDelivery],
    );
}

/** The editor of a one-line Order with that line's quantity changed, saved. */
function savedWith(Order $order, string $field, int $quantity, array $data = []): Testable
{
    $component = Livewire::test(EditOrder::class, ['record' => $order->getKey()]);
    $lines = $component->get('data.lines');
    $key = array_key_first($lines);

    $component->set('data.lines', [$key => [...$lines[$key], $field => $quantity]]);

    if ($data !== []) {
        $component->fillForm($data);
    }

    return $component->call('save');
}

/** A phone Order for Assam, taken. */
function takenByPhone(int $kilograms, array $data = []): Testable
{
    return Livewire::test(CreateOrder::class)
        ->fillForm(['customer_phone' => '01712345678', 'customer_district' => 'Dhaka', ...$data])
        ->set('data.lines', ['a' => ['product_id' => test()->assam->getKey(), 'kilograms' => $kilograms]])
        ->call('create');
}

describe('a User without the Permission', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->workingOrders()->create());
    });

    it('cannot take a phone Order beyond Free to Sell', function () {
        shelveAssam(5);

        $component = takenByPhone(6);

        expect($component->errors()->first('data.lines.a.kilograms'))->toBe('Assam: only 5 kg is free to sell to this Order, and it would hold 6 kg.')
            ->and(Order::query()->exists())->toBeFalse();
    });

    it('is asked only for the weight an edit adds, the Order\'s own left out', function () {
        shelveAssam(6);
        $order = assamOrdered(4);

        savedWith($order, 'kilograms', 6)->assertHasNoFormErrors();

        expect($order->lines()->sole()->quantity)->toBe(6);

        savedWith($order->refresh(), 'kilograms', 7)->assertHasFormErrors();

        expect(fn () => app(Repricing::class)->apply($order, [[...Repricing::stateOf($order)[0], 'kilograms' => 7]], auth()->user()))
            ->toThrow(InvalidArgumentException::class, 'Assam: only 6 kg is free to sell to this Order, and it would hold 7 kg.');

        expect($order->lines()->sole()->quantity)->toBe(6);
    });

    it('is never refused for taking weight off, even where stock has since fallen below the Order', function () {
        shelveAssam(5);
        $order = assamOrdered(5);
        app(StockLedger::class)->recordCount($this->assam, 2000, AdjustmentCause::Lost, null, $this->counter);

        savedWith($order, 'kilograms', 3)->assertHasNoFormErrors();

        expect($order->lines()->sole()->quantity)->toBe(3);
    });

    it('cancels an Order beyond stock', function () {
        shelveAssam(5);
        $order = assamOrdered(5);
        app(StockLedger::class)->recordCount($this->assam, 2000, AdjustmentCause::Lost, null, $this->counter);

        Livewire::test(EditOrder::class, ['record' => $order->getKey()])
            ->fillForm(['status' => OrderStatus::Cancelled->value])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($order->refresh()->status)->toBe(OrderStatus::Cancelled);
    });

    it('checks a Cancelled Order reopened and made heavier in the same save', function () {
        shelveAssam(5);
        $order = assamOrdered(5);
        $order->update(['status' => OrderStatus::Cancelled]);
        assamOrdered(3);

        savedWith($order, 'kilograms', 6, ['status' => OrderStatus::New->value])->assertHasFormErrors();

        expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
            ->and($order->lines()->sole()->quantity)->toBe(5);
    });

    it('holds a retail Order to Free to Sell across its Packs, its own weight left out', function () {
        shelveAssam(1);
        $order = app(OrderCapture::class)->captureRetail(
            [['packId' => $this->quarter->getKey(), 'productId' => $this->assam->getKey(), 'pack' => $this->quarter, 'count' => 2]],
            ['name' => 'Rahim', 'phone' => '01712345678', 'address' => 'Banani', 'district' => 'Dhaka', 'payment_method' => PaymentMethod::CashOnDelivery],
        );

        savedWith($order, 'count', 4)->assertHasNoFormErrors();

        $component = savedWith($order->refresh(), 'count', 5);

        expect($component->errors()->first())->toBe('Assam: only 1 kg is free to sell to this Order, and it would hold 1.25 kg.')
            ->and(fn () => app(RetailRepricing::class)->apply($order, [[...RetailRepricing::stateOf($order)[0], 'count' => 5]], auth()->user(), 'Tomorrow\'s delivery'))
            ->toThrow(InvalidArgumentException::class, 'would hold 1.25 kg');

        expect($order->refresh()->grams())->toBe(1000);
    });

    it('is offered no way past', function () {
        Livewire::test(CreateOrder::class)->assertFormFieldDoesNotExist(OrderForm::BEYOND_STOCK_REASON);
    });
});

describe('a User holding the Permission to sell beyond stock', function () {
    beforeEach(function () {
        $this->seller = User::factory()->sellingBeyondStock()->create(['name' => 'Karim']);
        $this->actingAs($this->seller);
    });

    it('must give a reason', function () {
        shelveAssam(5);

        $component = takenByPhone(6);

        expect($component->errors()->first('data.lines.a.kilograms'))->toBe('Assam: only 5 kg is free to sell to this Order, and it would hold 6 kg. Give a reason below to sell beyond stock.')
            ->and(Order::query()->exists())->toBeFalse();

        $order = assamOrdered(5);

        expect(fn () => app(Repricing::class)->apply($order, [[...Repricing::stateOf($order)[0], 'kilograms' => 6]], $this->seller))
            ->toThrow(InvalidArgumentException::class, 'Selling beyond stock needs a reason.');
    });

    it('takes a phone Order beyond stock with a reason, kept on the Order with their name', function () {
        shelveAssam(5);

        takenByPhone(6, [OrderForm::BEYOND_STOCK_REASON => 'Against tomorrow\'s Supply'])->assertHasNoFormErrors();

        $order = Order::query()->sole();

        expect($order->grams())->toBe(6000)
            ->and($order->sold_beyond_stock_reason)->toBe('Against tomorrow\'s Supply')
            ->and($order->soldBeyondStockBy->is($this->seller))->toBeTrue();

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertSee('Sold beyond stock')
            ->assertSee('Against tomorrow\'s Supply — Karim');
    });

    it('records nothing where the Order was within stock', function () {
        shelveAssam(6);
        $order = assamOrdered(4);

        savedWith($order, 'kilograms', 6, [OrderForm::BEYOND_STOCK_REASON => 'Just in case'])->assertHasNoFormErrors();

        expect($order->refresh()->sold_beyond_stock_reason)->toBeNull()
            ->and($order->sold_beyond_stock_by)->toBeNull();
    });

    it('cannot pass an Order Limit, reason or not', function () {
        $this->assam->update(['bulk_max_kilograms' => 5]);
        shelveAssam(10);

        $component = takenByPhone(6, [OrderForm::BEYOND_STOCK_REASON => 'A good customer']);

        expect($component->errors()->first('data.lines.a.kilograms'))->toBe('Assam: 6 kg is above its পাইকারি maximum of 5 kg.')
            ->and(Order::query()->exists())->toBeFalse();
    });
});
