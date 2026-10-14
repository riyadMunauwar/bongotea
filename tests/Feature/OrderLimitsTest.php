<?php

use App\Enums\PaymentMethod;
use App\Enums\SystemPage;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use App\Models\Pack;
use App\Models\Page;
use App\Models\Product;
use App\Models\User;
use App\Support\Orders\OrderCapture;
use App\Support\Orders\QuoteRefused;
use App\Support\Orders\Repricing;
use App\Support\Orders\RetailQuoteRefused;
use App\Support\Orders\RetailRepricing;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;

/**
 * An Order Limit (CONTEXT.md): the least and the most of one Product a single
 * Order may hold, set separately for পাইকারি in whole kilograms and for খুচরা
 * by weight, each end optional. It is measured on the Product's whole weight
 * in the Order, every line and Pack of it added up, and binds a User taking
 * or repricing an Order exactly as it binds a Customer at Checkout.
 *
 * Assam throughout: ৳500 a kilogram flat in 1 kg Packs, sold খুচরা in 100 g
 * Packs at ৳60 and 250 g Packs at ৳140.
 */
beforeEach(function () {
    $this->assam = Product::factory()->create(['name' => 'Assam', 'price' => 50000]);
    $this->hundred = Pack::factory()->retailOnly()->for($this->assam)->create(['grams' => 100, 'mrp' => 6000, 'price' => 6000]);
    $this->quarter = Pack::factory()->retailOnly()->for($this->assam)->create(['grams' => 250, 'mrp' => 14000, 'price' => 14000]);
});

/** @return array<string, mixed> */
function limitDetails(): array
{
    return ['name' => 'Rahim', 'phone' => '01712345678', 'address' => 'Banani', 'district' => 'Dhaka', 'payment_method' => PaymentMethod::CashOnDelivery];
}

function captureAssamKilograms(Product $assam, int $kilograms): Order
{
    return app(OrderCapture::class)->capture([['product' => $assam, 'kilograms' => $kilograms]], limitDetails());
}

/**
 * @param  list<array{0: Pack, 1: int}>  $packs
 */
function captureAssamPacks(array $packs): Order
{
    return app(OrderCapture::class)->captureRetail(array_map(
        fn (array $line): array => ['packId' => $line[0]->getKey(), 'productId' => $line[0]->product_id, 'pack' => $line[0], 'count' => $line[1]],
        $packs,
    ), limitDetails());
}

describe('the Product', function () {
    it('takes a missing end as no limit at that end', function () {
        $this->assam->update(['bulk_max_kilograms' => 5, 'retail_min_grams' => 200]);

        expect(captureAssamKilograms($this->assam, 1)->bulkKilograms())->toBe(1)
            ->and(captureAssamPacks([[$this->quarter, 20]])->grams())->toBe(5000);
    });

    it('refuses a maximum below its minimum, whoever saves it', function (array $limits) {
        $this->assam->update($limits);
    })->with([
        'পাইকারি' => [['bulk_min_kilograms' => 5, 'bulk_max_kilograms' => 4]],
        'খুচরা' => [['retail_min_grams' => 500, 'retail_max_grams' => 250]],
    ])->throws(LogicException::class);

    it('forgets the limits of a way it is no longer sold', function () {
        $this->assam->update(['bulk_min_kilograms' => 2, 'retail_max_grams' => 1000]);
        $this->hundred->delete();
        $this->quarter->delete();

        $this->assam->update(['sold_retail' => false]);
        expect($this->assam->fresh()->only(['bulk_min_kilograms', 'retail_max_grams']))->toBe(['bulk_min_kilograms' => 2, 'retail_max_grams' => null]);

        $this->assam->update(['sold_retail' => true, 'sold_bulk' => false, 'retail_max_grams' => 1000]);
        expect($this->assam->fresh()->only(['bulk_min_kilograms', 'retail_max_grams']))->toBe(['bulk_min_kilograms' => null, 'retail_max_grams' => 1000]);
    });
});

describe('the Product form', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->managingCatalogue()->create());
    });

    /**
     * @param  array<string, mixed>  $limits
     */
    function createProductWithLimits(array $limits): mixed
    {
        return Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Darjeeling', 'slug' => 'darjeeling', 'mrp' => '700', 'sold_bulk' => true, 'sold_retail' => true,
                'category_id' => Category::factory()->create()->getKey(), 'brand_id' => Brand::factory()->create()->getKey(),
            ])
            ->set('data.tiers', [['min' => '1', 'max' => '', 'price' => '620']])
            ->set('data.packs', [
                ['grams' => '400', 'serves_bulk' => true, 'serves_retail' => false, 'is_available' => true],
                ['grams' => '250', 'serves_bulk' => false, 'serves_retail' => true, 'mrp' => '190', 'price' => '175', 'is_available' => true],
            ])
            ->set('data.default_bulk_pack', '400')
            ->fillForm($limits)
            ->call('create');
    }

    it('saves the four limits, পাইকারি in kilograms and খুচরা in grams', function () {
        createProductWithLimits(['bulk_min_kilograms' => '2', 'bulk_max_kilograms' => '20', 'retail_min_grams' => '250', 'retail_max_grams' => ''])
            ->assertHasNoFormErrors();

        expect(Product::query()->where('slug', 'darjeeling')->sole()->only(['bulk_min_kilograms', 'bulk_max_kilograms', 'retail_min_grams', 'retail_max_grams']))
            ->toBe(['bulk_min_kilograms' => 2, 'bulk_max_kilograms' => 20, 'retail_min_grams' => 250, 'retail_max_grams' => null]);
    });

    it('refuses a maximum below its minimum', function () {
        createProductWithLimits(['bulk_min_kilograms' => '4', 'bulk_max_kilograms' => '2', 'retail_min_grams' => '500', 'retail_max_grams' => '250'])
            ->assertHasFormErrors(['bulk_max_kilograms', 'retail_max_grams']);
    });

    it('refuses a পাইকারি minimum no পাইকারি Pack makes exactly', function () {
        createProductWithLimits(['bulk_min_kilograms' => '1'])
            ->assertHasFormErrors(['bulk_min_kilograms']);

        createProductWithLimits(['bulk_min_kilograms' => '2'])
            ->assertHasNoFormErrors();
    });
});

describe('Capture', function () {
    it('measures the খুচরা limit on the whole weight of the tea, however it is split across Packs', function () {
        $this->assam->update(['retail_min_grams' => 500]);

        expect(fn () => captureAssamPacks([[$this->hundred, 1], [$this->quarter, 1]]))
            ->toThrow(fn (RetailQuoteRefused $refusal) => expect($refusal->outsideLimitPackIds)->toBe([$this->hundred->getKey(), $this->quarter->getKey()]));

        expect(Order::query()->exists())->toBeFalse()
            ->and(captureAssamPacks([[$this->hundred, 1], [$this->quarter, 2]])->grams())->toBe(600);
    });

    it('refuses a খুচরা basket above the maximum', function () {
        $this->assam->update(['retail_max_grams' => 500]);

        expect(fn () => captureAssamPacks([[$this->quarter, 3]]))->toThrow(RetailQuoteRefused::class, 'Order Limit');
    });

    it('refuses a bulk line outside its পাইকারি limit', function (int $kilograms) {
        $this->assam->update(['bulk_min_kilograms' => 2, 'bulk_max_kilograms' => 5]);

        expect(fn () => captureAssamKilograms($this->assam, $kilograms))
            ->toThrow(fn (QuoteRefused $refusal) => expect($refusal->outsideLimitProductIds)->toBe([$this->assam->getKey()]));
    })->with([1, 6]);

    it('keeps the two ways\' limits apart', function () {
        $this->assam->update(['bulk_min_kilograms' => 5, 'retail_max_grams' => 250]);

        expect(captureAssamPacks([[$this->hundred, 1]])->grams())->toBe(100)
            ->and(captureAssamKilograms($this->assam, 5)->bulkKilograms())->toBe(5);
    });

    it('refuses a bulk line above its maximum at the Checkout, naming it', function () {
        $this->assam->update(['bulk_max_kilograms' => 5]);

        $this->postJson(route('checkout.store'), [
            ...limitDetails(),
            'lines' => [['productId' => $this->assam->getKey(), 'kilograms' => 6]],
            'quote' => ['lines' => [['productId' => $this->assam->getKey(), 'tierPrice' => 50000]], 'deliveryCharge' => 12000, 'total' => 312000],
        ])
            ->assertConflict()
            ->assertJsonPath('outsideLimit', [$this->assam->getKey()]);

        expect(Order::query()->exists())->toBeFalse();
    });
});

describe('staff', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->workingOrders()->create());
    });

    it('cannot take a bulk Order above the পাইকারি maximum by phone', function () {
        $this->assam->update(['bulk_max_kilograms' => 5]);

        Livewire::test(CreateOrder::class)
            ->fillForm(['customer_phone' => '01712345678', 'customer_district' => 'Dhaka'])
            ->set('data.lines', ['a' => ['product_id' => $this->assam->getKey(), 'kilograms' => 6]])
            ->call('create')
            ->assertHasFormErrors(['lines.a.kilograms']);

        expect(Order::query()->exists())->toBeFalse();
    });

    it('cannot reprice a bulk Order above the পাইকারি maximum, on the form or however the rows reach it', function () {
        $order = captureAssamKilograms($this->assam, 4);
        $this->assam->update(['bulk_max_kilograms' => 5]);

        $component = Livewire::test(EditOrder::class, ['record' => $order->getKey()]);
        $lines = $component->get('data.lines');
        $key = array_key_first($lines);
        $component->set('data.lines', [$key => [...$lines[$key], 'kilograms' => 6]])->call('save');

        expect($component->errors()->first("data.lines.{$key}.kilograms"))->toBe('Assam: 6 kg is above its পাইকারি maximum of 5 kg.')
            ->and(fn () => app(Repricing::class)->apply($order, [[...Repricing::stateOf($order)[0], 'kilograms' => 6]], auth()->user()))
            ->toThrow(InvalidArgumentException::class, 'maximum');

        expect($order->lines()->sole()->quantity)->toBe(4);
    });

    it('cannot reprice a retail Order below the খুচরা minimum across its Packs', function () {
        $order = captureAssamPacks([[$this->hundred, 1], [$this->quarter, 2]]);
        $this->assam->update(['retail_min_grams' => 500]);
        $state = RetailRepricing::stateOf($order);

        $component = Livewire::test(EditOrder::class, ['record' => $order->getKey()]);
        $lines = $component->get('data.lines');
        $keys = array_keys($lines);
        $component->set("data.lines.{$keys[1]}.count", 1)->call('save');

        expect($component->errors()->first("data.lines.{$keys[1]}.count"))->toBe('Assam: 0.35 kg is below its খুচরা minimum of 0.5 kg.')
            ->and(fn () => app(RetailRepricing::class)->apply($order, [$state[0], [...$state[1], 'count' => 1]], auth()->user()))
            ->toThrow(InvalidArgumentException::class, 'minimum');

        expect($order->refresh()->grams())->toBe(600);
    });

    it('lets an untouched Order outside a limit set since keep its lines', function () {
        $order = captureAssamKilograms($this->assam, 6);
        $this->assam->update(['bulk_max_kilograms' => 5]);

        Livewire::test(EditOrder::class, ['record' => $order->getKey()])
            ->fillForm(['note' => 'Call first'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($order->refresh()->note)->toBe('Call first');
    });
});

it('sends both Carts each tea\'s limits', function () {
    Page::factory()->system(SystemPage::Home)->create();
    $this->assam->update(['bulk_min_kilograms' => 2, 'retail_max_grams' => 1000]);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('pricing.products.0.minKilograms', 2)
        ->where('pricing.products.0.maxKilograms', null)
        ->where('retailPricing.packs.0.minGrams', null)
        ->where('retailPricing.packs.0.maxGrams', 1000));
});
