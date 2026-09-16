<?php

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * The catalogue is one working area behind one Permission (#159). These assert
 * what a person with, and without, `manage products` can reach — never how the
 * gate is wired — through the admin panel over HTTP and Livewire, which is the
 * primary seam the spec names for a working-area gate.
 */
function sidebarLabels(): Collection
{
    return collect(Filament::getCurrentOrDefaultPanel()->getNavigation())
        ->flatMap(fn ($group) => $group->getItems())
        ->map(fn ($item) => $item->getLabel());
}

it('lets a User holding manage products open the list, create, edit and stock history, and lists Products in the sidebar', function () {
    $this->actingAs(User::factory()->managingProducts()->create());

    $product = Product::factory()->create();

    expect(ProductResource::canAccess())->toBeTrue();

    $this->get(ProductResource::getUrl('index'))->assertOk();
    $this->get(ProductResource::getUrl('create'))->assertOk();
    $this->get(ProductResource::getUrl('edit', ['record' => $product]))->assertOk();
    $this->get(ProductResource::getUrl('stock', ['record' => $product]))->assertOk();

    expect(sidebarLabels())->toContain('Products');
});

/**
 * The subject holds a Role that opens the panel but not this resource
 * (`manage settings`, not `manage products`). That is what makes the refusals
 * and the missing sidebar entry mean something: a role-less User is turned
 * away at the panel floor by `canAccessPanel()` and would fail these
 * assertions whether or not the resource were gated, so it would prove
 * nothing about `ProductResource::canAccess()`. This User reaches the panel
 * and sees other entries, so only the Products gate can be what closes these.
 */
it('refuses every Products screen to a panel User without manage products and hides it from the sidebar', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    $product = Product::factory()->create();

    expect(ProductResource::canAccess())->toBeFalse();

    $this->get(ProductResource::getUrl('index'))->assertForbidden();
    $this->get(ProductResource::getUrl('create'))->assertForbidden();
    $this->get(ProductResource::getUrl('edit', ['record' => $product]))->assertForbidden();
    $this->get(ProductResource::getUrl('stock', ['record' => $product]))->assertForbidden();

    expect(sidebarLabels())->not->toContain('Products');
});

/**
 * The whole resource is one unit of trust: there is no `delete products`
 * Permission, so the three verbs on a Product's editor ride on `manage
 * products` alone. Deleting is offered on a live Product, force-deleting and
 * restoring on a filed-away one.
 */
it('offers deleting a Product under manage products with no further Permission', function () {
    $this->actingAs(User::factory()->managingProducts()->create());

    $product = Product::factory()->create();

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->assertActionVisible('delete');
});

it('offers force-deleting and restoring a filed-away Product under manage products with no further Permission', function () {
    $this->actingAs(User::factory()->managingProducts()->create());

    $product = Product::factory()->trashed()->create();

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->assertActionVisible('forceDelete')
        ->assertActionVisible('restore');
});

/**
 * The gate lets the media-bearing screens through untouched: a Product's image
 * still stores on its disk and its editor still loads for the User who holds
 * the Permission.
 */
it('still stores and renders a Product image for a User holding manage products', function () {
    Storage::fake(Product::IMAGE_DISK, config('filesystems.disks.'.Product::IMAGE_DISK));

    $this->actingAs(User::factory()->managingProducts()->create());

    $product = Product::factory()->create();
    $product->addMediaFromString('image-bytes')
        ->usingFileName('shot.jpg')
        ->toMediaCollection(Product::IMAGE_COLLECTION);

    expect($product->imageUrl())->toContain('shot.jpg');

    $this->get(ProductResource::getUrl('edit', ['record' => $product]))->assertOk();
});
