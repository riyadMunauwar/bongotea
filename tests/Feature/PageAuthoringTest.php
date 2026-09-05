<?php

use App\Enums\PageStatus;
use App\Filament\Resources\Pages\Pages\CreatePage;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Models\Page;
use App\Models\Product;
use App\Models\User;
use App\Support\Checkout\CheckoutField;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('opens the authoring screens', function (string $url) {
    $this->get($url)->assertOk();
})->with([
    'pages' => '/admin/pages',
    'new page' => '/admin/pages/create',
    'products' => '/admin/products',
    'new product' => '/admin/products/create',
]);

it('publishes a page that is then served at its slug', function () {
    $product = Product::factory()->create(['name' => 'Winter Jacket']);

    Livewire::test(CreatePage::class)
        ->fillForm([
            'title' => 'Winter Promo',
            'slug' => 'winter-promo',
            'body' => '<!doctype html><html><body><h1>Winter</h1>[checkout]</body></html>',
            'status' => PageStatus::Published->value,
            'products' => [$product->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->get('/winter-promo')
        ->assertOk()
        ->assertSee('Winter Jacket');

    expect(Page::where('slug', 'winter-promo')->sole()->published_at)->not->toBeNull();
});

it('refuses to publish a page whose checkout has no product to sell', function () {
    Livewire::test(CreatePage::class)
        ->fillForm([
            'title' => 'Winter Promo',
            'slug' => 'winter-promo',
            'body' => '[checkout]',
            'status' => PageStatus::Published->value,
            'products' => [],
        ])
        ->call('create')
        ->assertHasFormErrors(['body']);
});

it('refuses to publish a page that links more than one product', function () {
    $products = Product::factory()->count(2)->create();

    /**
     * Falling back to "the first product by pivot position" would retarget the
     * live page on a reorder, with nothing reporting the change.
     */
    Livewire::test(CreatePage::class)
        ->fillForm([
            'title' => 'Winter Promo',
            'slug' => 'winter-promo',
            'body' => '[checkout]',
            'status' => PageStatus::Published->value,
            'products' => $products->pluck('id')->all(),
        ])
        ->call('create')
        ->assertHasFormErrors(['body']);
});

it('lets a draft hold a shortcode it could not be published with', function () {
    /** A draft is a work in progress; publishing is what puts it on paid traffic. */
    Livewire::test(CreatePage::class)
        ->fillForm([
            'title' => 'Half written',
            'slug' => 'half-written',
            'body' => '[checkout]',
            'status' => PageStatus::Draft->value,
            'products' => [],
        ])
        ->call('create')
        ->assertHasNoFormErrors();
});

it('invalidates the cached page when it is saved in the admin', function () {
    $page = Page::factory()->published()->withBody('<h1>Before</h1>')->create(['slug' => 'promo']);

    $this->cache->put('promo', '<h1>Before</h1>');

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['body' => '<h1>After</h1>'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->cache->has('promo'))->toBeFalse();

    $this->get('/promo')->assertSee('After', escape: false);
});

it('invalidates the cached page when only its product link changes', function () {
    $product = Product::factory()->create();
    $page = Page::factory()->published()->withBody('<main>[checkout]</main>')->create(['slug' => 'promo']);

    $this->cache->put('promo', '<h1>stale</h1>');

    /**
     * The observer fires on `saved`, which is *before* Filament syncs the
     * pivot — so this case is the one the resource's afterSave hook exists for.
     */
    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['products' => [$product->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->cache->has('promo'))->toBeFalse();
});

it('stores a product price as integer minor units', function () {
    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'Winter Jacket',
            'price' => '990.50',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Product::query()->sole()->price)->toBe(99050);
});

it('gives a new page a complete checkout nobody had to configure', function () {
    Livewire::test(CreatePage::class)
        ->fillForm([
            'title' => 'Winter Promo',
            'slug' => 'winter-promo',
            'body' => '<h1>Winter</h1>',
            'status' => PageStatus::Draft->value,
            'products' => [],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $config = Page::where('slug', 'winter-promo')->sole()->checkout_config;

    /**
     * Seeded on create and never partial, so a Page publishes without anyone
     * opening the checkout settings and nothing downstream has to ask whether
     * this Page has been configured yet.
     */
    expect(array_map(fn (CheckoutField $field): string => $field->key(), $config->fields))
        ->toBe(['name', 'phone', 'district', 'address'])
        ->and(array_filter($config->fields, fn (CheckoutField $field): bool => ! $field->required))
        ->toBeEmpty();
});
