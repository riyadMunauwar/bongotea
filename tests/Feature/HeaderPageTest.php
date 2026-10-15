<?php

use App\Enums\OrderKind;
use App\Enums\SystemPage;
use App\Filament\Pages\Header;
use App\Models\Cart;
use App\Models\Menu;
use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings\HeaderSettings;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;

/** A Cart the store opened with for that kind, standing on a Page of its own. */
function startingCartOf(OrderKind $kind): Cart
{
    $cart = Cart::factory()->state(['kind' => $kind])->onAPage()->create();
    $cart->forceFill(['starting_kind' => $kind])->save();

    return $cart;
}

beforeEach(function (): void {
    $this->actingAs(User::factory()->authoringPages()->create());

    $this->retail = startingCartOf(OrderKind::Retail);
    $this->bulk = startingCartOf(OrderKind::Bulk);
});

it('opens on the mockup announcement and no header Menu, and assigns the Menu chosen', function () {
    $menu = Menu::factory()->create(['name' => 'Main menu']);

    Livewire::test(Header::class)
        ->assertSchemaStateSet(['menuId' => null, 'announcementEnabled' => true, 'announcementPill' => 'অফার'])
        ->fillForm(['menuId' => $menu->id])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetInstance(HeaderSettings::class);

    expect(app(HeaderSettings::class)->menuId)->toBe($menu->id);
});

it('switches the announcement off, and asks for a message while it is on', function () {
    Livewire::test(Header::class)
        ->fillForm(['announcementEnabled' => true, 'announcementMessage' => ''])
        ->call('save')
        ->assertHasFormErrors(['announcementMessage' => 'required']);

    Livewire::test(Header::class)
        ->fillForm(['announcementEnabled' => false, 'announcementMessage' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetInstance(HeaderSettings::class);

    expect(app(HeaderSettings::class)->announcementEnabled)->toBeFalse();
});

it('reads a damaged Menu id as no Menu, rather than failing every request', function () {
    Setting::query()->create(['key' => HeaderSettings::key(), 'value' => ['menuId' => ['oops']]]);

    expect(HeaderSettings::load()->menuId)->toBeNull();
});

describe('the order buttons', function () {
    it('open on the starting Carts until another is chosen, and save the Carts chosen', function () {
        $eid = Cart::factory()->retail()->onAPage()->create(['name' => 'Eid Gift Packs']);

        Livewire::test(Header::class)
            ->assertSchemaStateSet(['retailCartId' => $this->retail->id, 'bulkCartId' => $this->bulk->id])
            ->fillForm(['retailCartId' => $eid->id])
            ->call('save')
            ->assertHasNoFormErrors();

        app()->forgetInstance(HeaderSettings::class);

        expect(app(HeaderSettings::class)->cartFor(OrderKind::Retail)->is($eid))->toBeTrue();
    });

    it('offer only Carts of their own kind', function () {
        Livewire::test(Header::class)
            ->fillForm(['retailCartId' => $this->bulk->id])
            ->call('save')
            ->assertHasFormErrors(['retailCartId']);
    });

    it('draw খুচরা অর্ডার করুন and পাইকারি অর্ডার করুন on the storefront, each linking to its Cart\'s page', function () {
        Page::factory()->system(SystemPage::Home)->create();

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('headerCarts', [
            'retail' => ['id' => $this->retail->id, 'name' => $this->retail->name, 'href' => $this->retail->link()],
            'bulk' => ['id' => $this->bulk->id, 'name' => $this->bulk->name, 'href' => $this->bulk->link()],
        ]));
    });

    it('leave out a button whose Cart is closed', function (Closure $close) {
        Page::factory()->system(SystemPage::Home)->create();
        $close($this->bulk);

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('headerCarts.bulk', null)->where('headerCarts.retail.id', $this->retail->id));
    })->with([
        'switched off' => [fn (Cart $cart) => $cart->update(['is_switched_on' => false])],
        'on no Page' => [fn (Cart $cart) => $cart->page()->delete()],
        'deleted' => [fn (Cart $cart) => $cart->delete()],
    ]);
});

it('falls back to the starting Cart once the Cart chosen is deleted, and still saves', function () {
    $eid = Cart::factory()->bulk()->onAPage()->create();
    app(HeaderSettings::class)->with(['bulkCartId' => $eid->id])->save();
    $eid->delete();
    $this->bulk->delete();
    app()->forgetInstance(HeaderSettings::class);

    expect(app(HeaderSettings::class)->cartFor(OrderKind::Bulk)->is($this->bulk))->toBeTrue();

    Livewire::test(Header::class)
        ->assertSchemaStateSet(['bulkCartId' => null])
        ->call('save')
        ->assertHasNoFormErrors();
});
