<?php

use App\Enums\AdjustmentCause;
use App\Enums\PaymentMethod;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Models\Order;
use App\Models\Pack;
use App\Models\Product;
use App\Models\User;
use App\Support\Inventory\StockLedger;
use App\Support\Orders\CheckoutUnavailable;
use App\Support\Orders\OrderCapture;
use App\Support\Orders\QuoteRefused;
use App\Support\Orders\Repricing;
use Livewire\Livewire;

/**
 * A bulk Cart takes one tea in several পাইকারি Packs: each Product + Pack is
 * a line of its own, and the tea's Kilograms across them are one tea's to its
 * Tier, its Order Limit and its stock.
 *
 * Darjeeling throughout: ৳600 a kilogram up to five and ৳550 from six, in
 * 1 kg Packs by default or in 500 g ones. Five kilograms in 1 kg Packs and two
 * in 500 g Packs are seven, so both lines charge ৳550.
 */
beforeEach(function () {
    $this->darjeeling = Product::factory()->withoutPacks()->create([
        'name' => 'Darjeeling',
        'mrp' => 65000,
        'tiers' => [['min' => 1, 'max' => 5, 'price' => 60000], ['min' => 6, 'max' => null, 'price' => 55000]],
    ]);
    $this->kilo = Pack::factory()->bulkOnly()->for($this->darjeeling)->create(['grams' => 1000]);
    $this->half = Pack::factory()->bulkOnly()->for($this->darjeeling)->create(['grams' => 500]);
    $this->darjeeling->update(['default_bulk_pack_id' => $this->kilo->getKey()]);
});

/**
 * Darjeeling captured in each Pack given, as the Checkout hands it over.
 *
 * @param  list<array{0: Pack|null, 1: int}>  $packs  each line's Pack, null for none named, and its Kilograms
 */
function captureDarjeeling(Product $darjeeling, array $packs): Order
{
    return app(OrderCapture::class)->capture(array_map(fn (array $line): array => [
        'product' => $darjeeling,
        'kilograms' => $line[1],
        ...($line[0] === null ? [] : ['pack' => $line[0]]),
    ], $packs), [
        'name' => 'Rahim',
        'phone' => '01712345678',
        'address' => 'Banani',
        'district' => 'Dhaka',
        'payment_method' => PaymentMethod::CashOnDelivery,
    ]);
}

describe('Capture', function () {
    it('captures one tea in two Packs as two Order Lines, each at the Tier the whole Cart\'s Kilograms fall in', function () {
        $order = captureDarjeeling($this->darjeeling, [[$this->kilo, 5], [$this->half, 2]]);

        expect($order->lines->map(fn ($line): array => [...$line->only(['pack_id', 'quantity', 'unit_price', 'tier_min']), 'packets' => $line->packets()])->all())->toBe([
            ['pack_id' => $this->kilo->getKey(), 'quantity' => 5, 'unit_price' => 55000, 'tier_min' => 6, 'packets' => 5],
            ['pack_id' => $this->half->getKey(), 'quantity' => 2, 'unit_price' => 55000, 'tier_min' => 6, 'packets' => 4],
        ])
            ->and($order->total_kilograms)->toBe(7)
            ->and($order->subtotal)->toBe(385000);
    });

    it('refuses the same tea in the same Pack twice, however the Pack is named', function (Closure $lines) {
        expect(fn () => captureDarjeeling($this->darjeeling, $lines($this)))->toThrow(CheckoutUnavailable::class, 'twice');

        expect(Order::query()->exists())->toBeFalse();
    })->with([
        'named both times' => [fn ($test) => [[$test->kilo, 1], [$test->kilo, 2]]],
        'once as the default' => [fn ($test) => [[null, 1], [$test->kilo, 2]]],
    ]);

    it('measures the Order Limit on the tea\'s Kilograms across its Packs', function () {
        $this->darjeeling->update(['bulk_min_kilograms' => 6, 'bulk_max_kilograms' => 6]);

        expect(fn () => captureDarjeeling($this->darjeeling, [[$this->kilo, 5], [$this->half, 2]]))
            ->toThrow(fn (QuoteRefused $refusal) => expect($refusal->outsideLimitProductIds)->toBe([$this->darjeeling->getKey()]));

        expect(captureDarjeeling($this->darjeeling, [[$this->kilo, 4], [$this->half, 2]])->total_kilograms)->toBe(6);
    });

    it('holds the tea\'s whole weight across its Packs to what is free to sell', function () {
        app(StockLedger::class)->recordCount($this->darjeeling, 6000, AdjustmentCause::OpeningCount, null, User::factory()->countingStock()->create());

        expect(fn () => captureDarjeeling($this->darjeeling, [[$this->kilo, 5], [$this->half, 2]]))
            ->toThrow(fn (QuoteRefused $refusal) => expect($refusal->shortProductIds)->toBe([$this->darjeeling->getKey()]));

        expect(captureDarjeeling($this->darjeeling, [[$this->kilo, 4], [$this->half, 2]])->total_kilograms)->toBe(6);
    });
});

describe('the Checkout', function () {
    /**
     * Darjeeling's lines as the checkout page posts them, quoted at ৳550 for seven kilograms.
     *
     * @param  list<array{0: Pack, 1: int}>  $packs
     * @return array<string, mixed>
     */
    function checkoutOfDarjeeling(Product $darjeeling, array $packs): array
    {
        return [
            'name' => 'Rahim',
            'phone' => '01712345678',
            'district' => 'Dhaka',
            'address' => 'Banani',
            'cart' => openCart()->getKey(),
            'lines' => array_map(fn (array $line): array => ['productId' => $darjeeling->getKey(), 'packId' => $line[0]->getKey(), 'kilograms' => $line[1]], $packs),
            'quote' => [
                'lines' => array_map(fn (): array => ['productId' => $darjeeling->getKey(), 'tierPrice' => 55000], $packs),
                'deliveryCharge' => 15000,
                'total' => 385000 + 15000,
            ],
        ];
    }

    it('accepts one tea in two Packs', function () {
        $this->postJson(route('checkout.store'), checkoutOfDarjeeling($this->darjeeling, [[$this->kilo, 5], [$this->half, 2]]))->assertCreated();

        expect(Order::query()->sole()->lines->pluck('pack_id')->all())->toBe([$this->kilo->getKey(), $this->half->getKey()]);
    });

    it('refuses the same tea in the same Pack twice', function () {
        $this->postJson(route('checkout.store'), checkoutOfDarjeeling($this->darjeeling, [[$this->kilo, 5], [$this->kilo, 2]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines']);

        expect(Order::query()->exists())->toBeFalse();
    });
});

describe('the admin Order form', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->workingOrders()->create());
    });

    it('takes a tea twice in different Packs, pricing both from its whole Kilograms', function () {
        Livewire::test(CreateOrder::class)
            ->fillForm(['customer_phone' => '01712345678', 'customer_district' => 'Dhaka'])
            ->set('data.lines', [
                'a' => ['product_id' => $this->darjeeling->getKey(), 'kilograms' => 5, 'pack_id' => $this->kilo->getKey()],
                'b' => ['product_id' => $this->darjeeling->getKey(), 'kilograms' => 2, 'pack_id' => $this->half->getKey()],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $order = Order::query()->sole();

        expect($order->lines->map->only(['pack_id', 'quantity', 'unit_price'])->all())->toBe([
            ['pack_id' => $this->kilo->getKey(), 'quantity' => 5, 'unit_price' => 55000],
            ['pack_id' => $this->half->getKey(), 'quantity' => 2, 'unit_price' => 55000],
        ]);
    });

    it('refuses a tea twice in the same Pack, on the form and however the rows reach Repricing', function () {
        $order = captureDarjeeling($this->darjeeling, [[$this->kilo, 5], [$this->half, 2]]);

        $component = Livewire::test(EditOrder::class, ['record' => $order->getKey()]);
        $lines = $component->get('data.lines');
        [$first, $second] = array_keys($lines);
        $component->set('data.lines', [$first => $lines[$first], $second => [...$lines[$second], 'pack_id' => null]])->call('save');

        expect($component->errors()->first("data.lines.{$second}.product_id"))->toBe('Darjeeling is already on this order in 1 kg Packs.');

        $state = Repricing::stateOf($order);

        expect(fn () => app(Repricing::class)->apply($order, [$state[0], [...$state[1], 'pack_id' => $this->kilo->getKey()]], auth()->user()))
            ->toThrow(InvalidArgumentException::class, 'already on this order in 1 kg Packs');

        expect($order->lines()->pluck('pack_id')->all())->toBe([$this->kilo->getKey(), $this->half->getKey()]);
    });

    it('reprices a tea in two Packs from its whole Kilograms', function () {
        $order = captureDarjeeling($this->darjeeling, [[$this->kilo, 4], [$this->half, 1]]);

        expect($order->lines->pluck('unit_price')->all())->toBe([60000, 60000]);

        $state = Repricing::stateOf($order);
        app(Repricing::class)->apply($order, [$state[0], [...$state[1], 'kilograms' => 2]], auth()->user());

        expect($order->lines()->orderBy('id')->pluck('unit_price')->all())->toBe([55000, 55000])
            ->and($order->refresh()->total_kilograms)->toBe(6);
    });

    it('measures the Order Limit on the tea\'s Kilograms across its rows', function () {
        $order = captureDarjeeling($this->darjeeling, [[$this->kilo, 4], [$this->half, 1]]);
        $this->darjeeling->update(['bulk_max_kilograms' => 5]);

        $state = Repricing::stateOf($order);

        expect(fn () => app(Repricing::class)->apply($order, [$state[0], [...$state[1], 'kilograms' => 2]], auth()->user()))
            ->toThrow(InvalidArgumentException::class, 'Darjeeling: 6 kg is above its পাইকারি maximum of 5 kg.');
    });
});
