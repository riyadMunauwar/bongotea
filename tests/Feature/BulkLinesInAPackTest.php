<?php

use App\Enums\PaymentMethod;
use App\Enums\SystemPage;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\Order;
use App\Models\Pack;
use App\Models\Page;
use App\Models\Product;
use App\Models\User;
use App\Support\Orders\OrderCapture;
use App\Support\Orders\PackingRefused;
use App\Support\Orders\QuoteRefused;
use App\Support\Orders\Repricing;
use Filament\Forms\Components\Select;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * A bulk line names the Pack its whole Kilograms are supplied in (ADR-0036):
 * the Product's default পাইকারি Pack unless the buyer chooses another of its
 * পাইকারি Packs. The Pack sets the packet count and never the price.
 *
 * Assam throughout: ৳500 a kilogram, flat, in 250 g Packs by default or 400 g
 * Packs on request, and 100 g Packs sold খুচরা only.
 */
beforeEach(function () {
    $this->assam = Product::factory()->withoutPacks()->create(['name' => 'Assam', 'price' => 50000]);
    $this->quarter = Pack::factory()->bulkOnly()->for($this->assam)->create(['grams' => 250]);
    $this->fourHundred = Pack::factory()->bulkOnly()->for($this->assam)->create(['grams' => 400]);
    $this->sachet = Pack::factory()->retailOnly()->for($this->assam)->create(['grams' => 100]);
    $this->assam->update(['default_bulk_pack_id' => $this->quarter->getKey()]);
});

/**
 * @param  array<string, mixed>  $line
 */
function captureBulk(array $line): Order
{
    return app(OrderCapture::class)->capture([$line], [
        'name' => 'Rahim',
        'phone' => '01712345678',
        'address' => 'Banani',
        'district' => 'Dhaka',
        'payment_method' => PaymentMethod::CashOnDelivery,
    ]);
}

describe('Capture', function () {
    it('records the Pack a bulk line is supplied in and the packets its Kilograms make', function () {
        $line = captureBulk(['product' => $this->assam, 'kilograms' => 2, 'pack' => $this->fourHundred])->lines->sole();

        expect($line->only(['pack_id', 'pack_grams', 'quantity']))->toBe(['pack_id' => $this->fourHundred->getKey(), 'pack_grams' => 400, 'quantity' => 2])
            ->and($line->packets())->toBe(5);
    });

    it('supplies a line naming no Pack in the Product\'s default পাইকারি Pack', function () {
        $line = captureBulk(['product' => $this->assam, 'kilograms' => 2])->lines->sole();

        expect($line->pack_id)->toBe($this->quarter->getKey())
            ->and($line->packets())->toBe(8);
    });

    it('refuses Kilograms the Pack cannot make exactly, naming weights that work, and places nothing', function () {
        expect(fn () => captureBulk(['product' => $this->assam, 'kilograms' => 1, 'pack' => $this->fourHundred]))
            ->toThrow(function (PackingRefused $refusal) {
                expect($refusal->workableKilograms)->toBe([2, 4])
                    ->and($refusal->banglaMessage())->toBe('Assam ৪০০ গ্রাম প্যাকেটে ১ কেজি হয় না — ২ বা ৪ কেজি নিন।');
            });

        expect(Order::query()->exists())->toBeFalse();
    });

    it('refuses a Pack that is not one of the Product\'s পাইকারি Packs now', function (Closure $packOf) {
        expect(fn () => captureBulk(['product' => $this->assam, 'kilograms' => 2, 'pack' => $packOf($this)]))
            ->toThrow(fn (QuoteRefused $refusal) => expect($refusal->unavailableProductIds)->toBe([$this->assam->getKey()]));
    })->with([
        'sold খুচরা only' => [fn ($test) => $test->sachet],
        'switched off' => [fn ($test) => tap($test->quarter)->update(['is_available' => false])],
        'another Product\'s' => [fn () => Product::factory()->create()->defaultBulkPack],
        'since deleted' => [fn () => null],
    ]);

    it('prices a line by its Tiers alone, whatever Pack it comes in', function () {
        $inQuarters = captureBulk(['product' => $this->assam, 'kilograms' => 2, 'pack' => $this->quarter]);
        $inFourHundreds = captureBulk(['product' => $this->assam, 'kilograms' => 2, 'pack' => $this->fourHundred]);

        expect($inQuarters->only(['subtotal', 'total']))->toBe($inFourHundreds->only(['subtotal', 'total']))
            ->and($inQuarters->subtotal)->toBe(100000);
    });

    it('keeps the Pack weight a line recorded after the Pack is edited or deleted', function () {
        $order = captureBulk(['product' => $this->assam, 'kilograms' => 2, 'pack' => $this->fourHundred]);

        $this->fourHundred->update(['grams' => 500]);
        expect($order->lines()->sole()->only(['pack_grams']))->toBe(['pack_grams' => 400]);

        $this->fourHundred->delete();
        $line = $order->lines()->sole();

        expect($line->only(['pack_id', 'pack_grams']))->toBe(['pack_id' => null, 'pack_grams' => 400])
            ->and($line->packets())->toBe(5);
    });

    it('still weighs a bulk line by its Kilograms', function () {
        $order = captureBulk(['product' => $this->assam, 'kilograms' => 2, 'pack' => $this->fourHundred]);

        expect($order->grams())->toBe(2000)
            ->and($order->bulkKilograms())->toBe(2)
            ->and($order->total_kilograms)->toBe(2);
    });
});

describe('the Checkout', function () {
    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    function checkoutOfAssam(Product $assam, array $line): array
    {
        return [
            'name' => 'Rahim',
            'phone' => '01712345678',
            'district' => 'Dhaka',
            'address' => 'Banani',
            'lines' => [['productId' => $assam->getKey(), ...$line]],
            'quote' => [
                'lines' => [['productId' => $assam->getKey(), 'tierPrice' => 50000]],
                'deliveryCharge' => 12000,
                'total' => 50000 * $line['kilograms'] + 12000,
            ],
        ];
    }

    it('captures the Pack chosen, working out the packets itself whatever the browser says', function () {
        $this->postJson(route('checkout.store'), checkoutOfAssam($this->assam, ['kilograms' => 2, 'packId' => $this->fourHundred->getKey(), 'packets' => 99]))
            ->assertCreated();

        expect(Order::query()->sole()->lines->sole())
            ->pack_id->toBe($this->fourHundred->getKey())
            ->packets()->toBe(5);
    });

    it('supplies a line from a Cart that names no Pack in the default পাইকারি Pack', function () {
        $this->postJson(route('checkout.store'), checkoutOfAssam($this->assam, ['kilograms' => 2]))->assertCreated();

        expect(Order::query()->sole()->lines->sole()->pack_id)->toBe($this->quarter->getKey());
    });

    it('answers Kilograms the Pack cannot make on the Cart\'s lines, in the buyer\'s words', function () {
        $this->postJson(route('checkout.store'), checkoutOfAssam($this->assam, ['kilograms' => 1, 'packId' => $this->fourHundred->getKey()]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines' => 'Assam ৪০০ গ্রাম প্যাকেটে ১ কেজি হয় না — ২ বা ৪ কেজি নিন।']);

        expect(Order::query()->exists())->toBeFalse();
    });

    it('refuses a Pack since deleted with the Cart as it is now', function () {
        $packId = $this->fourHundred->getKey();
        $this->fourHundred->delete();

        $this->postJson(route('checkout.store'), checkoutOfAssam($this->assam, ['kilograms' => 2, 'packId' => $packId]))
            ->assertConflict()
            ->assertJsonPath('unavailable', [$this->assam->getKey()]);
    });
});

describe('Repricing', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->workingOrders()->create());
        $this->order = captureBulk(['product' => $this->assam, 'kilograms' => 2, 'pack' => $this->fourHundred]);
    });

    /**
     * The Order's one line as the form holds it, changed.
     *
     * @param  array<string, mixed>  $change
     */
    function editingAssamLine(Order $order, array $change): Testable
    {
        $component = Livewire::test(EditOrder::class, ['record' => $order->getKey()]);
        $lines = $component->get('data.lines');
        $key = array_key_first($lines);

        return $component->set('data.lines', [$key => [...$lines[$key], ...$change]]);
    }

    it('keeps the line\'s Pack and the weight it recorded when the Kilograms change', function () {
        $this->fourHundred->update(['grams' => 500]);

        editingAssamLine($this->order, ['kilograms' => 4])->call('save')->assertHasNoFormErrors();

        $line = $this->order->lines()->sole();

        expect($line->only(['pack_id', 'pack_grams', 'quantity']))->toBe(['pack_id' => $this->fourHundred->getKey(), 'pack_grams' => 400, 'quantity' => 4])
            ->and($line->packets())->toBe(10);
    });

    it('moves a line to another of its tea\'s পাইকারি Packs without touching its price', function () {
        $total = $this->order->total;

        editingAssamLine($this->order, ['pack_id' => $this->quarter->getKey()])->call('save')->assertHasNoFormErrors();

        expect($this->order->lines()->sole()->only(['pack_id', 'pack_grams']))->toBe(['pack_id' => $this->quarter->getKey(), 'pack_grams' => 250])
            ->and($this->order->refresh()->total)->toBe($total);
    });

    it('refuses on the form Kilograms the line\'s Pack cannot make, naming weights that work', function () {
        $component = editingAssamLine($this->order, ['kilograms' => 1])->call('save');

        $key = array_key_first($component->get('data.lines'));
        expect($component->errors()->first("data.lines.{$key}.kilograms"))->toBe('Assam: 1 kg cannot be packed in 400 g Packs; 2 or 4 kg can.');

        expect($this->order->lines()->sole()->quantity)->toBe(2);
    });

    it('refuses a Pack its tea no longer offers পাইকারি, even on a line already holding it', function () {
        $this->fourHundred->update(['serves_bulk' => false, 'serves_retail' => true, 'mrp' => 20000, 'price' => 20000]);

        expect(fn () => app(Repricing::class)->apply($this->order, [[...Repricing::stateOf($this->order)[0], 'kilograms' => 4]], auth()->user()))
            ->toThrow(InvalidArgumentException::class);
    });

    it('refuses on the form, on the Pack, a Pack its tea no longer offers পাইকারি', function () {
        $this->fourHundred->update(['serves_bulk' => false, 'serves_retail' => true, 'mrp' => 20000, 'price' => 20000]);

        $component = editingAssamLine($this->order, ['kilograms' => 4])->call('save');

        $key = array_key_first($component->get('data.lines'));
        expect($component->errors()->first("data.lines.{$key}.pack_id"))->toBe('Assam is no longer sent in 400 g Packs পাইকারি.')
            ->and($this->order->lines()->sole()->quantity)->toBe(2);
    });

    it('refuses Kilograms the Pack cannot make however the rows reach it', function () {
        expect(fn () => app(Repricing::class)->apply($this->order, [[...Repricing::stateOf($this->order)[0], 'kilograms' => 1]], auth()->user()))
            ->toThrow(PackingRefused::class);
    });

    it('offers a bulk line only its tea\'s পাইকারি Packs, and takes the default where none is chosen', function () {
        $component = Livewire::test(CreateOrder::class)
            ->fillForm(['customer_phone' => '01712345678', 'customer_district' => 'Dhaka'])
            ->set('data.lines', ['a' => ['product_id' => $this->assam->getKey(), 'kilograms' => 3]]);

        $component->assertFormFieldExists('lines.a.pack_id', fn (Select $field): bool => array_keys($field->getOptions()) === [$this->quarter->getKey(), $this->fourHundred->getKey()]);

        $component->call('create')->assertHasNoFormErrors();

        expect(Order::query()->latest('id')->first()->lines->sole())
            ->pack_id->toBe($this->quarter->getKey())
            ->packets()->toBe(12);
    });
});

describe('the default পাইকারি Pack', function () {
    it('refuses a default that is not one of the Product\'s পাইকারি Packs', function (Closure $packOf) {
        $this->assam->update(['default_bulk_pack_id' => $packOf($this)->getKey()]);
    })->with([
        'sold খুচরা only' => [fn ($test) => $test->sachet],
        'another Product\'s' => [fn () => Product::factory()->create()->defaultBulkPack],
    ])->throws(LogicException::class);

    it('lets go of a default that stops serving পাইকারি', function () {
        $this->quarter->update(['serves_bulk' => false, 'serves_retail' => true, 'mrp' => 9000, 'price' => 9000]);

        expect($this->assam->fresh()->default_bulk_pack_id)->toBeNull();
    });

    it('is required on the form of a Product sold পাইকারি, and saved as the Pack of that weight', function () {
        $this->actingAs(User::factory()->managingCatalogue()->create());
        $fields = [
            'name' => 'Darjeeling', 'slug' => 'darjeeling', 'mrp' => '700', 'is_available' => true, 'sold_bulk' => true, 'sold_retail' => false,
            'category_id' => $this->assam->category_id, 'brand_id' => $this->assam->brand_id,
        ];
        $packs = [
            ['grams' => '250', 'serves_bulk' => true, 'is_available' => true],
            ['grams' => '500', 'serves_bulk' => true, 'is_available' => true],
        ];

        Livewire::test(CreateProduct::class)
            ->fillForm($fields)
            ->set('data.tiers', [['min' => '1', 'max' => '', 'price' => '620']])
            ->set('data.packs', $packs)
            ->call('create')
            ->assertHasFormErrors(['default_bulk_pack' => 'required']);

        Livewire::test(CreateProduct::class)
            ->fillForm($fields)
            ->set('data.tiers', [['min' => '1', 'max' => '', 'price' => '620']])
            ->set('data.packs', $packs)
            ->set('data.default_bulk_pack', '500')
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::query()->where('slug', 'darjeeling')->sole();

        expect($product->defaultBulkPack->grams)->toBe(500);

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->assertSchemaStateSet(['default_bulk_pack' => 500])
            ->set('data.default_bulk_pack', '250')
            ->call('save')
            ->assertHasNoFormErrors();

        expect($product->fresh()->defaultBulkPack->grams)->toBe(250);
    });
});

it('sends the Cart each tea\'s পাইকারি Packs on offer and its default, never one sold খুচরা only', function () {
    Page::factory()->system(SystemPage::Home)->create();
    Pack::factory()->bulkOnly()->unavailable()->for($this->assam)->create(['grams' => 500]);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('pricing.products.0.packs', [
            ['id' => $this->quarter->getKey(), 'grams' => 250],
            ['id' => $this->fourHundred->getKey(), 'grams' => 400],
        ])
        ->where('pricing.products.0.defaultPackId', $this->quarter->getKey()));
});
