<?php

use App\Enums\OrderKind;
use App\Enums\PageStatus;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Pack;
use App\Models\Page;
use App\Models\Product;
use App\Models\User;
use App\Support\Pages\Blocks\CartBlock;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;

/**
 * Checkout is opened for one Cart, named by its id (ADR-0038): the Order
 * records that Cart and the name it bore, a closed Cart is refused, and so
 * is a Cart sent lines of the other kind. Assignment is never a fence.
 *
 * One Assam throughout, ৳500 a kilogram against a ৳120 Delivery Charge.
 */
beforeEach(function () {
    $this->assam = Product::factory()->create(['name' => 'Assam', 'price' => 50000]);
});

/**
 * Two kilograms of a tea from a Cart, quoted as the store prices it.
 *
 * @return array<string, mixed>
 */
function twoKilogramsFrom(Cart $cart, Product $tea): array
{
    return [
        'name' => 'Rahim',
        'phone' => '01712345678',
        'district' => 'Dhaka',
        'address' => 'Banani',
        'cart' => $cart->getKey(),
        'lines' => [['productId' => $tea->getKey(), 'kilograms' => 2]],
        'quote' => [
            'lines' => [['productId' => $tea->getKey(), 'tierPrice' => 50000]],
            'deliveryCharge' => 12000,
            'total' => 112000,
        ],
    ];
}

it('records the Cart an Order came from and the name it bore, which a rename or a delete leaves alone', function () {
    $cart = Cart::factory()->bulk()->onAPage()->create(['name' => 'Hotel & Restaurant']);

    $this->postJson(route('checkout.store'), twoKilogramsFrom($cart, $this->assam))->assertCreated();

    $cart->update(['name' => 'Hotels']);
    $cart->delete();

    $order = Order::query()->sole();

    expect($order->cart_id)->toBe($cart->getKey())
        ->and($order->cart_name)->toBe('Hotel & Restaurant')
        ->and($order->cart->name)->toBe('Hotels');
});

it('takes a Product the Cart was never assigned', function () {
    $cart = Cart::factory()->bulk()->onAPage()->create();

    expect($cart->products()->exists())->toBeFalse();

    $this->postJson(route('checkout.store'), twoKilogramsFrom($cart, $this->assam))->assertCreated();
});

it('refuses a closed Cart with a clear message, and places nothing', function (Closure $closed) {
    $this->postJson(route('checkout.store'), twoKilogramsFrom($closed(), $this->assam))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cart' => 'এই কার্টটি এখন বন্ধ। এখান থেকে অর্ডার করা যাবে না।']);

    expect(Order::query()->exists())->toBeFalse();
})->with([
    'switched off' => [fn (): Cart => Cart::factory()->bulk()->switchedOff()->onAPage()->create()],
    'on no Page' => [fn (): Cart => Cart::factory()->bulk()->create()],
    'on a draft Page' => [fn (): Cart => Cart::factory()->bulk()->onAPage(published: false)->create()],
    'deleted' => [function (): Cart {
        $cart = Cart::factory()->bulk()->onAPage()->create();
        $cart->delete();

        return $cart;
    }],
]);

it('refuses a Checkout naming no Cart that exists', function (mixed $cart) {
    $this->postJson(route('checkout.store'), [...twoKilogramsFrom(openCart(), $this->assam), 'cart' => $cart])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cart');
})->with([null, 'bulk', 999]);

it('refuses a retail Cart sent Kilogram lines', function () {
    $this->postJson(route('checkout.store'), twoKilogramsFrom(openCart(OrderKind::Retail), $this->assam))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['lines', 'packs']);

    expect(Order::query()->exists())->toBeFalse();
});

it('refuses a bulk Cart sent Pack lines', function () {
    $pack = Pack::factory()->for($this->assam)->create(['grams' => 250, 'serves_bulk' => false, 'serves_retail' => true, 'mrp' => 15000, 'price' => 14000]);

    $this->postJson(route('checkout.store'), [
        ...twoKilogramsFrom(openCart(), $this->assam),
        'lines' => null,
        'packs' => [['packId' => $pack->getKey(), 'productId' => $this->assam->getKey(), 'count' => 1]],
    ])->assertUnprocessable()->assertJsonValidationErrors(['lines', 'packs']);

    expect(Order::query()->exists())->toBeFalse();
});

it('opens the Checkout for a closed Cart as closed', function () {
    $cart = Cart::factory()->bulk()->switchedOff()->onAPage()->create();

    $this->get(route('checkout', ['cart' => $cart->getKey()]))
        ->assertInertia(fn (Assert $page) => $page->where('cart.isOpen', false)->where('cart.name', $cart->name));
});

describe('a Cart on a Page', function () {
    it('draws the Cart its Block names', function () {
        $cart = Cart::factory()->retail()->onAPage()->create(['name' => 'Eid Gift Packs']);

        $this->get($cart->link())->assertInertia(fn (Assert $page) => $page
            ->where('page.blocks.0.type', CartBlock::type())
            ->where('page.blocks.0.props.cart', ['id' => $cart->getKey(), 'name' => 'Eid Gift Packs', 'kind' => 'retail', 'isOpen' => true]));
    });

    it('lists only open Carts in the shared props, in the Merchant\'s order, each with its Page\'s link', function () {
        $later = Cart::factory()->bulk()->onAPage()->create(['name' => 'Later', 'sort_order' => 2]);
        $first = Cart::factory()->retail()->onAPage()->create(['name' => 'First', 'sort_order' => 1]);
        Cart::factory()->bulk()->switchedOff()->onAPage()->create();
        Cart::factory()->bulk()->create();

        $this->get($first->link())->assertInertia(fn (Assert $page) => $page->where('carts', [
            ['id' => $first->getKey(), 'name' => 'First', 'kind' => 'retail', 'href' => $first->link(), 'sortOrder' => 1],
            ['id' => $later->getKey(), 'name' => 'Later', 'kind' => 'bulk', 'href' => $later->link(), 'sortOrder' => 2],
        ]));
    });

    it('names every closed Cart apart, by name and kind alone, so kept Contents can be shown as closed', function () {
        $open = Cart::factory()->retail()->onAPage()->create();
        $off = Cart::factory()->bulk()->switchedOff()->onAPage()->create(['name' => 'Winter']);
        $deleted = Cart::factory()->retail()->onAPage()->create(['name' => 'Eid 2025']);
        $deleted->delete();

        $this->get($open->link())->assertInertia(fn (Assert $page) => $page->where('closedCarts', [
            ['id' => $off->id, 'name' => 'Winter', 'kind' => 'bulk'],
            ['id' => $deleted->id, 'name' => 'Eid 2025', 'kind' => 'retail'],
        ]));
    });

    it('refuses to place a Cart on a second Page, naming the first', function () {
        $cart = Cart::factory()->bulk()->onAPage()->create();
        $first = $cart->page();

        expect(fn () => Page::factory()->withBlocks([['type' => CartBlock::type(), 'data' => ['cart_id' => $cart->getKey()]]])->create())
            ->toThrow(LogicException::class, "already stands on the Page \"{$first->title}\"");
    });

    it('refuses on the Page form a Cart another Page draws, naming that Page', function () {
        $cart = Cart::factory()->bulk()->onAPage()->create();
        $page = Page::factory()->create(['status' => PageStatus::Published]);

        $this->actingAs(User::factory()->authoringPages()->create());

        Livewire::test(EditPage::class, ['record' => $page->getKey()])
            ->fillForm(['blocks' => [['type' => CartBlock::type(), 'data' => ['cart_id' => $cart->getKey()]]]])
            ->call('save')
            ->assertHasFormErrors();

        expect($page->refresh()->blocks)->toBeEmpty();
    });
});

it('refuses on the Page form a Page drawing the same Cart twice', function () {
    $cart = Cart::factory()->bulk()->create();
    $page = Page::factory()->create(['status' => PageStatus::Published]);
    $block = ['type' => CartBlock::type(), 'data' => ['cart_id' => $cart->getKey()]];

    $this->actingAs(User::factory()->authoringPages()->create());

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['blocks' => [$block, $block]])
        ->call('save')
        ->assertHasFormErrors(['blocks']);

    expect($page->refresh()->blocks)->toBeEmpty();
});
