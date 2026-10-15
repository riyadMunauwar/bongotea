<?php

use App\Enums\OrderKind;
use App\Filament\Resources\Carts\CartResource;
use App\Filament\Resources\Carts\Pages\CreateCart;
use App\Filament\Resources\Carts\Pages\EditCart;
use App\Filament\Resources\Carts\Pages\ListCarts;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Livewire\Livewire;

/**
 * The Carts screen, where the Merchant makes places to order: a name, a kind
 * locked once made, an on/off switch, a sort order, and the Products each
 * offers — picked one by one, or ticked a Category at a time. Driven through
 * Livewire against the real admin screens, as `CatalogueScreensTest` is.
 */
beforeEach(function (): void {
    $this->actingAs(User::factory()->managingCatalogue()->create());
});

it('opens the Carts screens to a User holding manage catalogue', function () {
    $cart = Cart::factory()->create();

    $this->get(CartResource::getUrl('index'))->assertOk();
    $this->get(CartResource::getUrl('create'))->assertOk();
    $this->get(CartResource::getUrl('edit', ['record' => $cart]))->assertOk();
});

it('refuses the Carts screens to a panel User without manage catalogue', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    $cart = Cart::factory()->create();

    $this->get(CartResource::getUrl('index'))->assertForbidden();
    $this->get(CartResource::getUrl('create'))->assertForbidden();
    $this->get(CartResource::getUrl('edit', ['record' => $cart]))->assertForbidden();
});

it('creates a Cart with a name, a kind and its Products', function () {
    $assam = Product::factory()->bulkOnly()->create();
    $darjeeling = Product::factory()->bothWays()->create();

    Livewire::test(CreateCart::class)
        ->fillForm([
            'name' => 'Hotel & Restaurant',
            'kind' => OrderKind::Bulk->value,
            'sort_order' => 3,
        ])
        ->fillForm(['products' => [$assam->getKey(), $darjeeling->getKey()]])
        ->call('create')
        ->assertHasNoFormErrors();

    $cart = Cart::query()->where('name', 'Hotel & Restaurant')->sole();

    expect($cart->kind)->toBe(OrderKind::Bulk)
        ->and($cart->is_switched_on)->toBeTrue()
        ->and($cart->sort_order)->toBe(3)
        ->and($cart->products->modelKeys())->toEqualCanonicalizing([$assam->getKey(), $darjeeling->getKey()]);
});

it('renames a Cart and switches it off and on again', function () {
    $cart = Cart::factory()->create(['name' => 'Eid Gift Packs']);

    Livewire::test(EditCart::class, ['record' => $cart->getKey()])
        ->fillForm(['name' => 'Eid Packs', 'is_switched_on' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($cart->refresh()->name)->toBe('Eid Packs')
        ->and($cart->is_switched_on)->toBeFalse();

    Livewire::test(EditCart::class, ['record' => $cart->getKey()])
        ->fillForm(['is_switched_on' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($cart->refresh()->is_switched_on)->toBeTrue();
});

it('lists the Carts in the Merchant\'s sort order, dragged to reorder', function () {
    $second = Cart::factory()->create(['sort_order' => 2]);
    $first = Cart::factory()->create(['sort_order' => 1]);

    Livewire::test(ListCarts::class)
        ->assertCanSeeTableRecords([$first, $second], inOrder: true)
        ->call('reorderTable', [(string) $second->getKey(), (string) $first->getKey()]);

    expect($second->refresh()->sort_order)->toBeLessThan($first->refresh()->sort_order);
});

it('deletes a Cart softly, so an Order from it still reads its name', function () {
    $cart = Cart::factory()->create(['name' => 'Winter Cart']);
    $order = Order::factory()->create(['cart_id' => $cart->getKey(), 'cart_name' => $cart->name]);

    Livewire::test(EditCart::class, ['record' => $cart->getKey()])
        ->callAction(DeleteAction::class);

    expect(Cart::query()->find($cart->getKey()))->toBeNull()
        ->and($order->refresh()->cart->name)->toBe('Winter Cart')
        ->and($order->cart_name)->toBe('Winter Cart');

    Livewire::test(EditCart::class, ['record' => $cart->getKey()])
        ->callAction(RestoreAction::class);

    expect(Cart::query()->find($cart->getKey()))->not->toBeNull();
});

it('locks the kind once a Cart is made, on the form and on the model', function () {
    $cart = Cart::factory()->retail()->create();

    Livewire::test(EditCart::class, ['record' => $cart->getKey()])
        ->assertFormFieldDisabled('kind')
        ->fillForm(['kind' => OrderKind::Bulk->value])
        ->call('save');

    expect($cart->refresh()->kind)->toBe(OrderKind::Retail);

    $cart->kind = OrderKind::Bulk;

    expect(fn () => $cart->save())->toThrow(LogicException::class);
});

it('ticks the Products a Category holds when it is picked, any of which can be unticked', function () {
    $category = Category::factory()->create();
    $assam = Product::factory()->bulkOnly()->for($category)->create();
    $nilgiri = Product::factory()->bulkOnly()->for($category)->create();
    $packetOnly = Product::factory()->retailOnly()->for($category)->create();
    $elsewhere = Product::factory()->bulkOnly()->create();

    $form = Livewire::test(CreateCart::class)
        ->fillForm(['name' => 'Tea stalls', 'kind' => OrderKind::Bulk->value])
        ->fillForm(['products' => [$elsewhere->getKey()]])
        ->fillForm(['category_pick' => $category->getKey()]);

    expect(collect($form->get('data.products'))->map(fn (int|string $id): int => (int) $id)->all())
        ->toEqualCanonicalizing([$elsewhere->getKey(), $assam->getKey(), $nilgiri->getKey()]);

    $form->fillForm(['products' => [$elsewhere->getKey(), $assam->getKey()]])
        ->call('create')
        ->assertHasNoFormErrors();

    $cart = Cart::query()->where('name', 'Tea stalls')->sole();

    expect($cart->products->modelKeys())->toEqualCanonicalizing([$elsewhere->getKey(), $assam->getKey()])
        ->and($cart->products->modelKeys())->not->toContain($packetOnly->getKey());

    /** A tea added to the Category afterwards joins no Cart until it is assigned itself. */
    Product::factory()->bulkOnly()->for($category)->create();

    expect($cart->products()->count())->toBe(2);
});

it('refuses a Product not sold the Cart\'s way, on the form and on the model', function () {
    $cart = Cart::factory()->retail()->create();
    $bulkOnly = Product::factory()->bulkOnly()->create();

    Livewire::test(EditCart::class, ['record' => $cart->getKey()])
        ->fillForm(['products' => [$bulkOnly->getKey()]])
        ->call('save')
        ->assertHasFormErrors(['products.0']);

    expect($cart->products()->count())->toBe(0)
        ->and(fn () => $cart->products()->attach($bulkOnly))->toThrow(LogicException::class);
});

it('assigns one Product to as many Carts as the Merchant likes', function () {
    $assam = Product::factory()->bulkOnly()->create();
    [$everyday, $festive] = Cart::factory()->bulk()->count(2)->create();

    $everyday->products()->attach($assam);
    $festive->products()->attach($assam);

    expect($everyday->products->modelKeys())->toBe([$assam->getKey()])
        ->and($festive->products->modelKeys())->toBe([$assam->getKey()]);
});

it('takes a Product out of every Cart of a way it stops being sold, so its Carts still save', function () {
    $darjeeling = Product::factory()->bothWays()->create();
    $bulkCart = Cart::factory()->bulk()->create();
    $retailCart = Cart::factory()->retail()->create();

    $bulkCart->products()->attach($darjeeling);
    $retailCart->products()->attach($darjeeling);

    $darjeeling->update(['sold_retail' => false]);

    expect($retailCart->products()->count())->toBe(0)
        ->and($bulkCart->products()->count())->toBe(1);

    Livewire::test(EditCart::class, ['record' => $retailCart->getKey()])
        ->call('save')
        ->assertHasNoFormErrors();
});
