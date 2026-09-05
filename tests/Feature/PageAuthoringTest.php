<?php

use App\Enums\PageStatus;
use App\Filament\Resources\Pages\Pages\CreatePage;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Filament\Resources\Pages\Pages\ListPages;
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
        ->assertHasFormErrors(['status']);
});

it('publishes a page that sells more than one product', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket']);
    $scarf = Product::factory()->create(['name' => 'Wool Scarf']);

    /** A combo, or an upsell alongside the headline item. One is the floor. */
    Livewire::test(CreatePage::class)
        ->fillForm([
            'title' => 'Winter Promo',
            'slug' => 'winter-promo',
            'body' => '<!doctype html><html><body>[checkout]</body></html>',
            'status' => PageStatus::Published->value,
            'products' => [$jacket->id, $scarf->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->get('/winter-promo')
        ->assertOk()
        ->assertSee('Winter Jacket')
        ->assertSee('Wool Scarf');
});

it('marks a published page that has lost a linked product', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket']);
    $scarf = Product::factory()->create(['name' => 'Wool Scarf']);

    $selling = Page::factory()->published()->withBody('<main>[checkout]</main>')->create(['slug' => 'promo']);
    $selling->products()->attach([$jacket->id, $scarf->id]);

    $intact = Page::factory()->published()->withBody('<main>[checkout]</main>')->create(['slug' => 'intact']);
    $intact->products()->attach($jacket);

    /**
     * The checkout quietly drops the scarf and keeps selling the jacket, which
     * is the right outcome and an invisible one. The merchant has to find out
     * at their desk rather than from a conversion graph a day later.
     */
    $scarf->delete();

    Livewire::test(ListPages::class)
        ->assertCanSeeTableRecords([$selling, $intact])
        ->assertTableColumnStateSet('deleted_products_count', '1 product deleted', $selling)
        ->assertTableColumnStateSet('deleted_products_count', null, $intact);
});

it('leaves a draft that has lost a product unmarked', function () {
    $product = Product::factory()->create();

    $draft = Page::factory()->withBody('<main>[checkout]</main>')->create(['slug' => 'promo']);
    $draft->products()->attach($product);

    /** Nothing is being spent on a draft, so there is nothing to warn about. */
    $product->delete();

    Livewire::test(ListPages::class)
        ->assertTableColumnStateSet('deleted_products_count', null, $draft);
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

it('serves a checkout in the wording the merchant gave it', function () {
    $product = Product::factory()->create();
    $page = Page::factory()->published()->withBody('<main>[checkout]</main>')->create(['slug' => 'promo']);
    $page->products()->attach($product);

    $this->cache->put('promo', '<h1>stale</h1>');

    $fields = $page->checkout_config->toArray()['fields'];
    $fields[0]['label'] = 'Who are we delivering to?';
    $fields[0]['placeholder'] = 'Your full name';
    $fields[0]['help'] = 'As it is written on your NID.';

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['checkout_config' => ['fields' => $fields]])
        ->call('save')
        ->assertHasNoFormErrors();

    /** A rewording changes what visitors receive, so the HTML written before it has to go. */
    expect($this->cache->has('promo'))->toBeFalse();

    $this->get('/promo')
        ->assertOk()
        ->assertSee('Who are we delivering to?')
        ->assertSee('Your full name')
        ->assertSee('As it is written on your NID.');
});

it('cannot lose a built-in field however the checkout tab is saved', function () {
    $page = Page::factory()->create();

    /**
     * No control on the tab adds, removes or reorders a row, so this is the
     * shape only a tampered submission could send. A Config short of a built-in
     * is completed as it is read, so the form can never stop asking for a phone
     * number nobody could then call.
     */
    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['checkout_config' => ['fields' => [
            ['key' => 'name', 'label' => 'The only question', 'required' => true],
        ]]])
        ->call('save')
        ->assertHasNoFormErrors();

    $fields = $page->refresh()->checkout_config->fields;

    expect(array_map(fn (CheckoutField $field): string => $field->key(), $fields))
        ->toBe(['name', 'phone', 'district', 'address'])
        ->and($fields[0]->label)->toBe('The only question')
        ->and($fields[1]->label)->toBe('Phone number');
});
