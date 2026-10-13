<?php

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Pack;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

/**
 * A Product opened to retail by giving it Packs (ADR-0034): each a weight in
 * grams, an MRP and a Pack Price of its own and an Available switch, edited on
 * the Product's own form and refused, by the form and by the model alike,
 * when two weigh the same or a Pack Price exceeds its MRP.
 */
beforeEach(function (): void {
    $this->actingAs(User::factory()->managingCatalogue()->create());

    $this->category = Category::factory()->create();
    $this->brand = Brand::factory()->create();
});

/**
 * @param  list<array<string, mixed>>  $packs
 */
function createProductWithPacks(array $packs): mixed
{
    return Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'Premium',
            'slug' => 'premium',
            'category_id' => test()->category->getKey(),
            'brand_id' => test()->brand->getKey(),
            'mrp' => '700',
        ])
        ->set('data.tiers', [['min' => '1', 'max' => '', 'price' => '620']])
        ->set('data.packs', $packs)
        ->call('create');
}

it('gives a Product Packs, in the order the Merchant set, with money in minor units', function () {
    createProductWithPacks([
        ['grams' => '250', 'mrp' => '190', 'price' => '175', 'is_available' => true],
        ['grams' => '100', 'mrp' => '80', 'price' => '80', 'is_available' => false],
    ])->assertHasNoFormErrors();

    $packs = Product::query()->where('slug', 'premium')->sole()->packs;

    expect($packs->map->only(['grams', 'mrp', 'price', 'is_available'])->all())->toBe([
        ['grams' => 250, 'mrp' => 19000, 'price' => 17500, 'is_available' => true],
        ['grams' => 100, 'mrp' => 8000, 'price' => 8000, 'is_available' => false],
    ]);
});

it('names a Pack by its weight', function (int $grams, string $name) {
    expect(Pack::factory()->make(['grams' => $grams])->name())->toBe($name);
})->with([
    [250, '250 g'],
    [1000, '1 kg'],
    [1500, '1.5 kg'],
]);

it('lists a Product\'s Packs smallest first until the Merchant orders them otherwise', function () {
    $product = Product::factory()->create();
    Pack::factory()->for($product)->create(['grams' => 500]);
    Pack::factory()->for($product)->create(['grams' => 100]);
    Pack::factory()->for($product)->create(['grams' => 250]);

    expect($product->packs->pluck('grams')->all())->toBe([100, 250, 500]);
});

it('opens a Product\'s Packs in whole taka again', function () {
    $product = Product::factory()->create();
    Pack::factory()->for($product)->create(['grams' => 250, 'mrp' => 19000, 'price' => 17500]);

    $packs = Livewire::test(EditProduct::class, ['record' => $product->getKey()])->get('data.packs');

    expect(array_values($packs)[0])->toMatchArray(['grams' => 250, 'mrp' => 190, 'price' => 175]);
});

it('refuses on the form two Packs of one weight, a Pack Price above its MRP, or a weight or price of nothing', function (array $packs, string $field) {
    createProductWithPacks($packs)->assertHasFormErrors(["packs.0.{$field}"]);

    expect(Product::query()->where('slug', 'premium')->exists())->toBeFalse();
})->with([
    'the same weight twice' => [[
        ['grams' => '250', 'mrp' => '190', 'price' => '175', 'is_available' => true],
        ['grams' => '250', 'mrp' => '200', 'price' => '180', 'is_available' => true],
    ], 'grams'],
    'a Pack Price above its MRP' => [[['grams' => '250', 'mrp' => '170', 'price' => '175', 'is_available' => true]], 'price'],
    'a weight of nothing' => [[['grams' => '0', 'mrp' => '190', 'price' => '175', 'is_available' => true]], 'grams'],
    'a negative weight' => [[['grams' => '-100', 'mrp' => '190', 'price' => '175', 'is_available' => true]], 'grams'],
    'a Pack Price of nothing' => [[['grams' => '250', 'mrp' => '190', 'price' => '0', 'is_available' => true]], 'price'],
    'a negative Pack Price' => [[['grams' => '250', 'mrp' => '190', 'price' => '-5', 'is_available' => true]], 'price'],
    'an MRP of nothing' => [[['grams' => '250', 'mrp' => '0', 'price' => '0', 'is_available' => true]], 'mrp'],
]);

it('refuses a Pack saved from anywhere with a Pack Price above its MRP, or a weight or price of nothing', function (array $attributes) {
    Pack::factory()->create($attributes);
})->with([
    'a Pack Price above its MRP' => [['mrp' => 17000, 'price' => 17500]],
    'a weight of nothing' => [['grams' => 0]],
    'a negative weight' => [['grams' => -100]],
    'a Pack Price of nothing' => [['price' => 0, 'mrp' => 19000]],
    'a negative MRP' => [['mrp' => -1, 'price' => -2]],
])->throws(LogicException::class);

it('refuses a second Pack of the same weight on one Product saved from anywhere, but allows it on another', function () {
    $product = Product::factory()->create();
    Pack::factory()->for($product)->create(['grams' => 250]);

    Pack::factory()->create(['grams' => 250]);

    expect(fn () => Pack::factory()->for($product)->create(['grams' => 250]))->toThrow(LogicException::class);
});

it('removes a Pack from the form', function () {
    $product = Product::factory()->create();
    Pack::factory()->for($product)->create(['grams' => 250]);
    $kept = Pack::factory()->for($product)->create(['grams' => 500]);

    $component = Livewire::test(EditProduct::class, ['record' => $product->getKey()]);
    $packs = $component->get('data.packs');
    $component
        ->set('data.packs', array_filter($packs, fn (array $pack): bool => (int) $pack['grams'] === 500))
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->packs()->pluck('id')->all())->toBe([$kept->getKey()]);
});

it('orders a Pack only while its own switch is on and its Product is published, Available and in the catalogue', function (Closure $arrange, bool $orderable) {
    $pack = Pack::factory()->create();
    $arrange($pack);

    expect($pack->fresh()->isOrderable())->toBe($orderable);
})->with([
    'all on' => [fn (Pack $pack) => null, true],
    'switched off' => [fn (Pack $pack) => $pack->update(['is_available' => false]), false],
    'Product a draft' => [fn (Pack $pack) => $pack->product->update(['status' => 'draft']), false],
    'Product unavailable' => [fn (Pack $pack) => $pack->product->update(['is_available' => false]), false],
    'Product out of the catalogue' => [fn (Pack $pack) => $pack->product->delete(), false],
]);

it('shows which Products are open to retail, and filters by it', function () {
    $retail = Product::factory()->create();
    Pack::factory()->for($retail)->create();
    $bulkOnly = Product::factory()->create();

    Livewire::test(ListProducts::class)
        ->assertTableColumnStateSet('packs_exists', true, $retail)
        ->assertTableColumnStateSet('packs_exists', false, $bulkOnly)
        ->filterTable('packs', true)
        ->assertCanSeeTableRecords([$retail])
        ->assertCanNotSeeTableRecords([$bulkOnly])
        ->filterTable('packs', false)
        ->assertCanSeeTableRecords([$bulkOnly])
        ->assertCanNotSeeTableRecords([$retail]);
});
