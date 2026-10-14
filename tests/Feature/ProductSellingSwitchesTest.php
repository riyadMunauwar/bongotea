<?php

use App\Enums\OrderKind;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Pack;
use App\Models\Product;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Each Product is switched পাইকারি, খুচরা or both, and each Pack ticked for
 * the ways it serves (ADR-0036), refused by the model whoever saves them.
 */
it('refuses a Product switched neither পাইকারি nor খুচরা', function () {
    Product::factory()->create(['sold_bulk' => false, 'sold_retail' => false]);
})->throws(LogicException::class);

it('saves a Product sold only খুচরা with no Tiers, price or MRP', function () {
    $product = Product::factory()->retailOnly()->create();

    expect($product->fresh())
        ->tiers->toBeNull()
        ->price->toBeNull()
        ->mrp->toBeNull();
});

it('drops the Tiers of a Product no longer sold পাইকারি', function () {
    $product = Product::factory()->create();

    $product->update(['sold_bulk' => false, 'sold_retail' => true]);

    expect($product->fresh())->tiers->toBeNull()->mrp->toBeNull();
});

it('still requires an MRP of a Product sold পাইকারি', function () {
    Product::factory()->bulkOnly()->create(['mrp' => null]);
})->throws(LogicException::class);

it('says which ways a Product is sold', function (string $state, bool $bulk, bool $retail) {
    $product = Product::factory()->{$state}()->create();

    expect($product->isSoldAs(OrderKind::Bulk))->toBe($bulk)
        ->and($product->isSoldAs(OrderKind::Retail))->toBe($retail)
        ->and(Product::query()->soldAs(OrderKind::Bulk)->whereKey($product)->exists())->toBe($bulk)
        ->and(Product::query()->soldAs(OrderKind::Retail)->whereKey($product)->exists())->toBe($retail);
})->with([
    'পাইকারি only' => ['bulkOnly', true, false],
    'খুচরা only' => ['retailOnly', false, true],
    'both' => ['bothWays', true, true],
]);

it('refuses a Pack ticked for a way its Product is not sold, or for no way at all', function (string $productState, array $ticks) {
    Pack::factory()->for(Product::factory()->{$productState}())->create($ticks);
})->with([
    'খুচরা on a পাইকারি-only Product' => ['bulkOnly', ['serves_bulk' => false, 'serves_retail' => true]],
    'পাইকারি on a খুচরা-only Product' => ['retailOnly', ['serves_bulk' => true, 'serves_retail' => false]],
    'neither' => ['bothWays', ['serves_bulk' => false, 'serves_retail' => false]],
])->throws(LogicException::class);

it('requires an MRP and a Pack Price only of a Pack ticked খুচরা', function () {
    $bulkPack = Pack::factory()->bulkOnly()->for(Product::factory()->bulkOnly())->create();

    expect($bulkPack->fresh())->mrp->toBeNull()->price->toBeNull();
    expect(fn () => Pack::factory()->create(['serves_retail' => true, 'mrp' => null, 'price' => null]))->toThrow(LogicException::class);
});

it('never opens a Pack to retail because a price was typed', function () {
    $pack = Pack::factory()->bulkOnly()->create(['mrp' => 20000, 'price' => 17500]);

    expect($pack->fresh()->servesAs(OrderKind::Retail))->toBeFalse()
        ->and($pack->fresh()->isOrderableAs(OrderKind::Retail))->toBeFalse();
});

it('orders a Pack for a way only while it is ticked for it and its Product is sold that way', function (string $productState, string $packState, bool $bulk, bool $retail) {
    $pack = Pack::factory()->{$packState}()->for(Product::factory()->{$productState}())->create();

    expect($pack->isOrderableAs(OrderKind::Bulk))->toBe($bulk)
        ->and($pack->isOrderableAs(OrderKind::Retail))->toBe($retail);
})->with([
    'both, ticked both' => ['bothWays', 'bothWays', true, true],
    'both, ticked পাইকারি' => ['bothWays', 'bulkOnly', true, false],
    'both, ticked খুচরা' => ['bothWays', 'retailOnly', false, true],
    'খুচরা only' => ['retailOnly', 'retailOnly', false, true],
]);

/**
 * The Product form, which a Merchant switches and ticks on. Its Packs are set
 * whole, so each row says everything it holds.
 *
 * @param  array<string, mixed>  $fields
 * @param  list<array<string, mixed>>  $packs
 */
function productFormWith(array $fields, array $packs): Testable
{
    return Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'Assam',
            'slug' => 'assam',
            'category_id' => Category::factory()->create()->getKey(),
            'brand_id' => Brand::factory()->create()->getKey(),
            'mrp' => '700',
            'is_available' => true,
            'sold_bulk' => true,
            'sold_retail' => true,
            ...$fields,
        ])
        ->set('data.tiers', [['min' => '1', 'max' => '', 'price' => '620']])
        ->set('data.packs', $packs)
        ->set('data.default_bulk_pack', $fields['default_bulk_pack'] ?? collect($packs)->firstWhere('serves_bulk', true)['grams'] ?? null);
}

/** @return array<string, mixed> */
function bulkPackRow(array $row = []): array
{
    return ['grams' => '1000', 'serves_bulk' => true, 'serves_retail' => false, 'is_available' => true, ...$row];
}

/** @return array<string, mixed> */
function retailPackRow(array $row = []): array
{
    return ['grams' => '250', 'serves_bulk' => false, 'serves_retail' => true, 'mrp' => '190', 'price' => '175', 'is_available' => true, ...$row];
}

describe('the Product form', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->managingCatalogue()->create());
    });

    it('saves a Product sold both ways with a Pack ticked for each', function () {
        productFormWith([], [bulkPackRow(), retailPackRow()])->call('create')->assertHasNoFormErrors();

        $product = Product::query()->where('slug', 'assam')->sole();

        expect($product->only(['sold_bulk', 'sold_retail']))->toBe(['sold_bulk' => true, 'sold_retail' => true])
            ->and($product->packs->map->only(['grams', 'serves_bulk', 'serves_retail', 'mrp', 'price'])->all())->toBe([
                ['grams' => 1000, 'serves_bulk' => true, 'serves_retail' => false, 'mrp' => null, 'price' => null],
                ['grams' => 250, 'serves_bulk' => false, 'serves_retail' => true, 'mrp' => 19000, 'price' => 17500],
            ]);
    });

    it('refuses a Product switched neither way', function () {
        productFormWith(['sold_bulk' => false, 'sold_retail' => false], [bulkPackRow()])
            ->call('create')
            ->assertHasFormErrors(['sold_bulk']);

        expect(Product::query()->exists())->toBeFalse();
    });

    it('saves a খুচরা-only Product with no Tiers', function () {
        productFormWith(['sold_bulk' => false], [retailPackRow()])
            ->set('data.tiers', [])
            ->set('data.mrp', null)
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Product::query()->where('slug', 'assam')->sole())->tiers->toBeNull()->mrp->toBeNull();
    });

    it('still requires Tiers of a Product sold পাইকারি', function () {
        productFormWith([], [bulkPackRow(), retailPackRow()])
            ->set('data.tiers', [])
            ->call('create')
            ->assertHasFormErrors(['tiers']);
    });

    it('needs a Pack serving each way the Product is sold', function () {
        productFormWith([], [bulkPackRow()])->call('create')->assertHasFormErrors(['packs']);
        productFormWith(['sold_bulk' => false], [bulkPackRow(['serves_retail' => true, 'mrp' => '700', 'price' => '650'])])
            ->set('data.tiers', [])
            ->call('create')
            ->assertHasNoFormErrors();
    });

    it('keeps a Pack\'s ticks within its Product\'s switches', function () {
        productFormWith(['sold_retail' => false], [bulkPackRow(['serves_retail' => true, 'mrp' => '700', 'price' => '650'])])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Pack::query()->sole()->serves_retail)->toBeFalse();
    });

    it('refuses a Pack ticked for no way', function () {
        productFormWith([], [bulkPackRow(['serves_bulk' => false]), retailPackRow()])
            ->call('create')
            ->assertHasFormErrors(['packs.0.serves_bulk']);
    });

    it('requires an MRP and a Pack Price, the Pack Price within the MRP, only of a Pack ticked খুচরা', function (array $row, array $errors) {
        $form = productFormWith([], [bulkPackRow(), retailPackRow($row)])->call('create');

        $errors === [] ? $form->assertHasNoFormErrors() : $form->assertHasFormErrors($errors);
    })->with([
        'no MRP' => [['mrp' => ''], ['packs.1.mrp']],
        'no Pack Price' => [['price' => ''], ['packs.1.price']],
        'a Pack Price above the MRP' => [['price' => '200'], ['packs.1.price']],
        'both, within' => [[], []],
    ]);

    it('opens no Pack to retail because a price was typed on it', function () {
        productFormWith([], [bulkPackRow(['mrp' => '700', 'price' => '650']), retailPackRow()])->call('create')->assertHasNoFormErrors();

        expect(Pack::query()->where('grams', 1000)->sole()->serves_retail)->toBeFalse();
    });

    it('refuses to make a Product with no Pack Available, and saves one that is not', function () {
        productFormWith([], [])->call('create')->assertHasFormErrors(['is_available']);
        productFormWith(['is_available' => false], [])->call('create')->assertHasNoFormErrors();
    });
});
