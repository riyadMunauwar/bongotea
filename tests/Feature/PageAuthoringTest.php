<?php

use App\Enums\PageStatus;
use App\Enums\PaymentMethod;
use App\Filament\Resources\Pages\Pages\CreatePage;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Filament\Resources\Pages\Pages\ListPages;
use App\Filament\Resources\Pages\Schemas\Checkout\Questions;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Models\Order;
use App\Models\Page;
use App\Models\Product;
use App\Models\User;
use App\Support\Checkout\CheckoutConfig;
use App\Support\Checkout\CheckoutField;
use App\Support\Checkout\CheckoutLayout;
use App\Support\Checkout\FieldOption;
use App\Support\Checkout\FieldWidth;
use App\Support\Checkout\StyleToken;
use App\Support\OrderComplete\OrderCompleteLayout;
use App\Support\Settings\PaymentSettings;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
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

it('stages a file a merchant drags in where no visitor can reach it', function () {
    /**
     * Every file dropped into any upload in the panel is written to Livewire's
     * staging directory before anything decides whether to keep it — including
     * the ones a merchant drags in, thinks better of, and never saves. The
     * disk Product images live on is publicly visible from the moment a file
     * lands on it (ADR-0011), so staging cannot share it: an unguessable
     * filename is not a permission, and nothing would report the exposure.
     */
    $staging = config('livewire.temporary_file_upload.disk');

    expect($staging)->not->toBeNull('Livewire stages onto the default disk unless a disk is named.');

    $disk = config("filesystems.disks.{$staging}");

    /**
     * Three ways a file becomes fetchable, and the disk must offer none of
     * them. The root is asserted present first because a disk without one is
     * a disk this cannot reason about, and a check that quietly passes on
     * anything it does not understand is the failure it exists to prevent.
     */
    expect($disk['root'] ?? null)->not->toBeNull("The [{$staging}] disk has no root to place.")
        ->and($disk['url'] ?? null)->toBeNull("The [{$staging}] disk publishes an address.")
        ->and($disk['visibility'] ?? null)->not->toBe('public');

    expect(collect(config('filesystems.links'))->contains(
        fn (string $target): bool => str_starts_with($disk['root'], $target)
    ))->toBeFalse("The [{$staging}] disk is reachable through a document-root link.");
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
        ->toBe(['name', 'phone', 'email', 'district', 'address'])
        /**
         * The form itself is unchanged: email is in the Config so the shape is
         * complete, but switched off, because adding a question to every live
         * campaign is not something a deployment does on a merchant's behalf.
         */
        ->and(array_map(fn (CheckoutField $field): string => $field->key(), $config->enabledFields()))
        ->toBe(['name', 'phone', 'district', 'address'])
        ->and(array_filter($config->enabledFields(), fn (CheckoutField $field): bool => ! $field->required))
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
            ['key' => 'name', 'built_in' => true, 'label' => 'The only question', 'required' => true],
        ]]])
        ->call('save')
        ->assertHasNoFormErrors();

    $fields = $page->refresh()->checkout_config->fields;

    expect(array_map(fn (CheckoutField $field): string => $field->key(), $fields))
        ->toBe(['name', 'phone', 'email', 'district', 'address'])
        ->and($fields[0]->label)->toBe('The only question')
        ->and($fields[1]->label)->toBe('Phone number');
});

it('stops asking for a field the merchant switched off on the checkout tab', function () {
    $product = Product::factory()->create();
    $page = Page::factory()->published()->withBody('<main>[checkout]</main>')->create(['slug' => 'promo']);
    $page->products()->attach($product);

    $this->cache->put('promo', '<h1>stale</h1>');

    $fields = collect($page->checkout_config->fieldsToArray())
        ->map(function (array $field): array {
            if ($field['key'] === 'address') {
                $field['enabled'] = false;
            }

            if ($field['key'] === 'email') {
                $field['enabled'] = true;
            }

            return $field;
        })
        ->all();

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['checkout_config' => ['fields' => $fields]])
        ->call('save')
        ->assertHasNoFormErrors();

    /** A form that asks different questions is different bytes, so the written HTML has to go. */
    expect($this->cache->has('promo'))->toBeFalse();

    $asked = array_map(
        fn (CheckoutField $field): string => $field->key(),
        $page->refresh()->checkout_config->enabledFields(),
    );

    expect($asked)->toBe(['name', 'phone', 'email', 'district']);

    $this->get('/promo')
        ->assertOk()
        ->assertSee('Email address')
        ->assertDontSee('Delivery address');
});

it('will not let the checkout tab stop asking for a phone number', function () {
    $page = Page::factory()->create();

    /**
     * The toggle is held shut on screen, so this is the shape only a tampered
     * submission could send. A customer is their phone number: an order nobody
     * can call cannot be fulfilled, and cannot be worth taking.
     */
    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['checkout_config' => ['fields' => [
            ['key' => 'phone', 'built_in' => true, 'label' => 'Phone number', 'enabled' => false, 'required' => false],
        ]]])
        ->call('save')
        ->assertHasNoFormErrors();

    $phone = collect($page->refresh()->checkout_config->fields)
        ->firstWhere(fn (CheckoutField $field): bool => $field->key() === 'phone');

    expect($phone->enabled)->toBeTrue()
        ->and($phone->required)->toBeTrue();
});

/**
 * The Delivery Options a Page offers, in the shape the Checkout tab fills in:
 * a name, and an amount typed in major units the way a merchant types money.
 *
 * @param  list<array{label: string, amount: string}>  $options
 * @return array<string, mixed>
 */
function deliveryOptionRows(array $options): array
{
    return ['checkout_config' => ['delivery_options' => $options]];
}

it('starts a new page off offering the delivery the installation charged for', function () {
    Livewire::test(CreatePage::class)
        ->fillForm([
            'title' => 'Winter Promo',
            'slug' => 'winter-promo',
            'body' => '<!doctype html><html><body>[checkout]</body></html>',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    /**
     * One option, added with no question asked, is what a Page charged before
     * it could price its own delivery — so the create screen has to arrive
     * holding it rather than empty, which would silently make every new Page
     * deliver for free.
     */
    expect(Page::where('slug', 'winter-promo')->sole()->checkout_config->deliveryOptionsToArray())
        ->toBe([['label' => 'Delivery', 'amount' => 6000]]);
});

it('lets a merchant author the delivery options a page offers', function () {
    $product = Product::factory()->create();
    $page = Page::factory()->published()->withBody('<main>[checkout]</main>')->create(['slug' => 'promo']);
    $page->products()->attach($product);

    $this->cache->put('promo', '<h1>stale</h1>');

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(deliveryOptionRows([
            ['label' => 'Inside Dhaka', 'amount' => '60'],
            ['label' => 'Outside Dhaka', 'amount' => '120'],
            ['label' => 'Free', 'amount' => '0'],
        ]))
        ->call('save')
        ->assertHasNoFormErrors();

    /** A form quoting different figures is different bytes, so the written HTML has to go. */
    expect($this->cache->has('promo'))->toBeFalse();

    expect($page->refresh()->checkout_config->deliveryOptionsToArray())->toBe([
        ['label' => 'Inside Dhaka', 'amount' => 6000],
        ['label' => 'Outside Dhaka', 'amount' => 12000],
        ['label' => 'Free', 'amount' => 0],
    ]);

    $this->get('/promo')
        ->assertOk()
        ->assertSee('Inside Dhaka')
        ->assertSee('Outside Dhaka')
        ->assertSee('Free');
});

it('renames, reprices, reorders and deletes a delivery option', function () {
    $page = Page::factory()->create();

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(deliveryOptionRows([
            ['label' => 'Inside Dhaka', 'amount' => '60'],
            ['label' => 'Outside Dhaka', 'amount' => '120'],
        ]))
        ->call('save')
        ->assertHasNoFormErrors();

    expect($page->refresh()->checkout_config->deliveryLabels())
        ->toBe(['Inside Dhaka', 'Outside Dhaka']);

    /**
     * Reordered, renamed, repriced and one of them dropped, all in one save.
     * The order is the order the customer reads them in and the first is the
     * one already chosen when the form is drawn, so moving one changes what a
     * page sells rather than how it looks.
     */
    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(deliveryOptionRows([
            ['label' => 'Outside Dhaka', 'amount' => '150'],
            ['label' => 'Dhaka city', 'amount' => '60'],
        ]))
        ->call('save')
        ->assertHasNoFormErrors();

    expect($page->refresh()->checkout_config->deliveryOptionsToArray())->toBe([
        ['label' => 'Outside Dhaka', 'amount' => 15000],
        ['label' => 'Dhaka city', 'amount' => 6000],
    ]);
});

it('lets a page charge for no delivery at all', function () {
    $page = Page::factory()->create();

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(deliveryOptionRows([]))
        ->call('save')
        ->assertHasNoFormErrors();

    /**
     * Emptied on purpose, and stored as emptied. A page collecting at a counter
     * charges nothing to deliver, and an empty list is how a merchant says so —
     * distinct from a config that never mentioned delivery at all, which keeps
     * charging what it always charged.
     */
    expect($page->refresh()->checkout_config->deliveryOptions)->toBe([]);
});

it('refuses a delivery option nobody could choose or be charged', function (array $options) {
    $page = Page::factory()->create();

    $component = Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(deliveryOptionRows($options))
        ->call('save')
        ->assertHasFormErrors();

    /**
     * Named against the delivery options and nothing else. A row-level refusal
     * lands under a key the repeater invents per item, so there is no one key
     * to assert — but "the save was refused, and refused over this" is what the
     * test is actually for, and a refusal hung on an unrelated field would pass
     * without it.
     */
    expect(array_keys($component->errors()->messages()))
        ->each->toStartWith('data.checkout_config.delivery_options');

    /**
     * Refused at save rather than tidied up on read: what a page charges is
     * collected in cash at a customer's door, so a merchant has to be told
     * their page does not say what they think it says.
     */
    expect($page->refresh()->checkout_config->deliveryOptionsToArray())
        ->toBe([['label' => 'Delivery', 'amount' => 6000]]);
})->with([
    'a blank name' => [[['label' => '  ', 'amount' => '60']]],
    'an amount below nothing' => [[['label' => 'Inside Dhaka', 'amount' => '-60']]],
    'two options under one name' => [[
        ['label' => 'Inside Dhaka', 'amount' => '60'],
        ['label' => 'Inside Dhaka', 'amount' => '120'],
    ]],

    /**
     * A name a merchant may perfectly well give an option, and the one a
     * carelessly written uniqueness check drops before comparing anything:
     * both rows would save, and reading them back would silently leave one.
     */
    'two options under one name that reads as nothing' => [[
        ['label' => '0', 'amount' => '60'],
        ['label' => '0', 'amount' => '120'],
    ]],
]);

it('starts a new page off selling everything it links', function () {
    Livewire::test(CreatePage::class)
        ->fillForm([
            'title' => 'Winter Promo',
            'slug' => 'winter-promo',
            'body' => '<!doctype html><html><body>[checkout]</body></html>',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    /**
     * The picker is a decision a merchant makes about one campaign. Switching
     * it on for every new page would put an unticked box between paid traffic
     * and an order nobody asked us to change.
     */
    expect(Page::where('slug', 'winter-promo')->sole()->checkout_config->productPicker)
        ->toBeFalse();
});

it('lets a merchant hand the choice of products to the customer', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket']);
    $boots = Product::factory()->create(['name' => 'Hiking Boots']);

    $page = Page::factory()->published()->withBody('<main>[checkout]</main>')->create(['slug' => 'promo']);
    $page->products()->attach([$jacket->id, $boots->id]);

    $this->cache->put('promo', '<h1>stale</h1>');

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['checkout_config' => ['product_picker' => true]])
        ->call('save')
        ->assertHasNoFormErrors();

    /** A form with boxes to tick is different bytes, so the written HTML has to go. */
    expect($this->cache->has('promo'))->toBeFalse()
        ->and($page->refresh()->checkout_config->productPicker)->toBeTrue();

    $html = $this->get('/promo')->assertOk()->getContent();

    expect($html)->toContain('name="selected[]"')
        ->and($html)->toContain('value="'.$jacket->getKey().'"')
        ->and($html)->toContain('value="'.$boots->getKey().'"');
});

it('keeps the delivery options and the questions a page had when the picker is switched on', function () {
    $page = Page::factory()->create();

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(deliveryOptionRows([['label' => 'Inside Dhaka', 'amount' => '60']]))
        ->call('save')
        ->assertHasNoFormErrors();

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['checkout_config' => ['product_picker' => true]])
        ->call('save')
        ->assertHasNoFormErrors();

    /**
     * One Checkout Config, edited a control at a time. A switch saved on the
     * Checkout tab must not take the rest of the tab down with it.
     */
    $config = $page->refresh()->checkout_config;

    expect($config->productPicker)->toBeTrue()
        ->and($config->deliveryOptionsToArray())->toBe([['label' => 'Inside Dhaka', 'amount' => 6000]])
        ->and(array_map(fn (CheckoutField $field): string => $field->key(), $config->fields))
        ->toBe(['name', 'phone', 'email', 'district', 'address']);
});

/**
 * One of the merchant's own questions, in the shape the Checkout tab fills a
 * row in: every control on the row, whether or not it has anything in it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function questionRow(string $key, array $overrides = []): array
{
    return array_merge([
        /**
         * Which kind of field wrote the row. The tab carries it so that what a
         * row may do never turns on the key box the merchant types in, and it
         * is false here because these are the merchant's own questions.
         */
        'built_in' => false,
        'key' => $key,
        'label' => 'Gift note',
        'type' => 'text',
        'enabled' => true,
        'required' => false,
        'placeholder' => null,
        'help' => null,
        'width' => 'full',
        'constraints' => [],
        'options' => [],
    ], $overrides);
}

/**
 * The field rows this Page already has, with the merchant's own placed after a
 * named built-in rather than at the end.
 *
 * One list holds both kinds, so adding a question is inserting a row rather
 * than filling in a second form (ADR-0006).
 *
 * @param  list<array<string, mixed>>  $questions
 * @return array<string, mixed>
 */
function fieldRows(Page $page, array $questions, string $after = 'phone'): array
{
    $rows = [];

    foreach ($page->checkout_config->fieldsToArray() as $row) {
        $rows[] = $row;

        if ($row['key'] === $after) {
            $rows = [...$rows, ...$questions];
        }
    }

    return ['checkout_config' => ['fields' => $rows]];
}

it('lets a merchant add a question of their own between two built-in ones', function () {
    $product = Product::factory()->create();
    $page = Page::factory()->published()->withBody('<main>[checkout]</main>')->create(['slug' => 'promo']);
    $page->products()->attach($product);

    $this->cache->put('promo', '<h1>stale</h1>');

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(fieldRows($page, [
            questionRow('gift_note', [
                'label' => 'Anything we should write on the card?',
                'type' => 'textarea',
                'help' => 'We will copy it out by hand.',
            ]),
        ], after: 'phone'))
        ->call('save')
        ->assertHasNoFormErrors();

    /** A form asking a question more is different bytes, so the written HTML has to go. */
    expect($this->cache->has('promo'))->toBeFalse();

    /**
     * Where the merchant put it, not appended after the built-ins. A question
     * that can only go last is a question the merchant cannot really place.
     */
    expect(array_map(fn (CheckoutField $field): string => $field->key(), $page->refresh()->checkout_config->fields))
        ->toBe(['name', 'phone', 'gift_note', 'email', 'district', 'address']);

    $this->get('/promo')
        ->assertOk()
        ->assertSee('Anything we should write on the card?')
        ->assertSee('We will copy it out by hand.');
});

it('lets a merchant author the options one of their own questions offers', function () {
    $product = Product::factory()->create();
    $page = Page::factory()->published()->withBody('<main>[checkout]</main>')->create(['slug' => 'promo']);
    $page->products()->attach($product);

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(fieldRows($page, [
            questionRow('colour', [
                'label' => 'Which colour?',
                'type' => 'select',
                'placeholder' => 'Choose a colour',
                'options' => [
                    ['value' => 'sky', 'label' => 'Sky blue'],
                    /** No wording of its own, so the value is what the customer reads. */
                    ['value' => 'Moss green', 'label' => null],
                ],
            ]),
        ]))
        ->call('save')
        ->assertHasNoFormErrors();

    $colour = collect($page->refresh()->checkout_config->fields)
        ->firstWhere(fn (CheckoutField $field): bool => $field->key() === 'colour');

    expect(array_map(fn (FieldOption $option): array => $option->toArray(), $colour->options()))
        ->toBe([
            ['value' => 'sky', 'label' => 'Sky blue'],
            ['value' => 'Moss green', 'label' => 'Moss green'],
        ]);

    $this->get('/promo')
        ->assertOk()
        ->assertSee('<option value="sky">Sky blue</option>', false);
});

it('refuses a question of the merchant that nothing could answer', function (array $questions) {
    $page = Page::factory()->create();

    $component = Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(fieldRows($page, $questions))
        ->call('save')
        ->assertHasFormErrors();

    /**
     * Named against the row that is wrong rather than against the repeater, so
     * a merchant with a dozen questions is told which one to fix. The key the
     * repeater invents per item is in the middle of the path, so the ends are
     * what there is to assert on.
     */
    expect(array_keys($component->errors()->messages()))
        ->each->toStartWith('data.checkout_config.fields');

    /**
     * Refused at save rather than tidied up on read: a question a merchant
     * thinks their page asks and does not is a campaign collecting less than
     * they believe it is.
     */
    expect(array_map(fn (CheckoutField $field): string => $field->key(), $page->refresh()->checkout_config->fields))
        ->toBe(['name', 'phone', 'email', 'district', 'address']);
})->with([
    'two questions under one key' => [[
        questionRow('gift_note'),
        questionRow('gift_note', ['label' => 'A second note']),
    ]],
    'a key the order form already uses' => [[questionRow('page', ['label' => 'Which page?'])]],
    /**
     * The key the label box derives on its own, so this is the one a merchant
     * reaches by typing an ordinary question rather than by inventing a name.
     * Saved, it would post the customer's answer in place of the pricing hash
     * and every order on the page would be turned away as stale.
     */
    'the key the pricing hash arrives under' => [[questionRow('fingerprint', ['label' => 'Fingerprint'])]],
    'a key no form input could carry' => [[questionRow('Gift Note!')]],
    'a dropdown with nothing to choose between' => [[questionRow('colour', ['type' => 'select', 'options' => []])]],
    'radio buttons with nothing to choose between' => [[questionRow('colour', ['type' => 'radio', 'options' => []])]],
]);

it('refuses a duplicated key on the row that holds it', function () {
    $page = Page::factory()->create();

    $component = Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(fieldRows($page, [
            questionRow('gift_note'),
            questionRow('gift_note', ['label' => 'A second note']),
        ]))
        ->call('save')
        ->assertHasFormErrors();

    /**
     * On the key box itself, which is the control the merchant has to change.
     * Both rows are named, because either one of the pair could be the one that
     * was meant to be something else.
     */
    expect(array_keys($component->errors()->messages()))
        ->toHaveCount(2)
        ->each->toEndWith('.key');
});

it('refuses a built-in key on the row that typed it and not on the built-in', function () {
    $page = Page::factory()->create();

    $component = Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(fieldRows($page, [questionRow('district', ['label' => 'Which district?'])]))
        ->call('save')
        ->assertHasFormErrors();

    /**
     * One refusal, on the row the merchant can put right. The district built-in
     * is untouched: its key box is read-only and its row cannot be deleted, so
     * an error raised there would name a control nobody can act on and leave
     * the merchant unable to save at all.
     */
    expect($component->errors()->messages())
        ->toHaveCount(1)
        ->and(array_key_first($component->errors()->messages()))->toEndWith('.key')
        ->and(array_values($component->errors()->messages())[0][0])
        ->toBe('This checkout already asks a question under that key. Give yours one of its own.');
});

it('lets a merchant bound what one of their own questions accepts', function () {
    $product = Product::factory()->create();
    $page = Page::factory()->published()->withBody('<main>[checkout]</main>')->create(['slug' => 'promo']);
    $page->products()->attach($product);

    $this->cache->put('promo', '<h1>stale</h1>');

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(fieldRows($page, [
            questionRow('nickname', [
                'label' => 'What should we call you?',
                'type' => 'text',
                'constraints' => ['min_length' => 3, 'max_length' => 40, 'pattern' => 'letters'],
            ]),
            questionRow('floor', [
                'label' => 'Which floor?',
                'type' => 'number',
                'constraints' => ['min' => '1', 'max' => '20', 'whole_numbers' => true],
            ]),
        ]))
        ->call('save')
        ->assertHasNoFormErrors();

    /** A form that refuses more than it did is different bytes, so the written HTML has to go. */
    expect($this->cache->has('promo'))->toBeFalse();

    $stored = collect($page->refresh()->checkout_config->fieldsToArray())->keyBy('key');

    expect($stored['nickname']['constraints'])
        ->toBe(['min_length' => 3, 'max_length' => 40, 'pattern' => 'letters'])
        ->and($stored['floor']['constraints'])
        ->toBe(['min' => '1', 'max' => '20', 'whole_numbers' => true]);

    /** And out the other side, on the form a customer is served. */
    $html = $this->get('/promo')->assertOk()->getContent();

    expect($html)
        ->toMatch('/<input[^>]*name="nickname"[^>]*minlength="3"/')
        ->toMatch('/<input[^>]*name="floor"[^>]*max="20"/');
});

it('keeps a bound the merchant left empty out of the stored config', function () {
    $page = Page::factory()->create();

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(fieldRows($page, [questionRow('gift_note', ['constraints' => ['max_length' => 200]])]))
        ->call('save')
        ->assertHasNoFormErrors();

    $stored = collect($page->refresh()->checkout_config->fieldsToArray())->keyBy('key');

    /**
     * Sparse, like the style tokens: a box left empty is the question taking
     * whatever it is given, which is not a figure to store.
     */
    expect($stored['gift_note']['constraints'])->toBe(['max_length' => 200]);
});

it('refuses a bound nothing could satisfy on the row that holds it', function (array $constraints, string $box, string $message) {
    $page = Page::factory()->create();

    $component = Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(fieldRows($page, [questionRow('detail', [
            'type' => isset($constraints['min']) ? 'number' : 'text',
            'constraints' => $constraints,
        ])]))
        ->call('save')
        ->assertHasFormErrors();

    /**
     * On the smaller figure's own box, which is the control that has to come
     * down — and on the offending row, so a merchant with a dozen questions is
     * told which one to fix rather than that something somewhere is wrong.
     *
     * Worded by what the pair bounds, the way a customer's refusal is: a box
     * labelled "Shortest answer" told it is larger than the largest is being
     * told about a control it is not.
     */
    expect($component->errors()->messages())
        ->toHaveCount(1)
        ->and(array_key_first($component->errors()->messages()))->toEndWith($box)
        ->and(array_values($component->errors()->messages())[0][0])
        ->toBe($message);

    expect($page->refresh()->checkout_config->fields)->toHaveCount(5);
})->with([
    'a shortest above the longest' => [
        ['min_length' => 40, 'max_length' => 10],
        '.constraints.min_length',
        'A shortest answer longer than the longest is a question nobody can answer.',
    ],
    'a smallest above the largest' => [
        ['min' => '20', 'max' => '2'],
        '.constraints.min',
        'A smallest larger than the largest is a question nobody can answer.',
    ],
]);

it('refuses a longest above what the kind of question will hold', function (string $type, int $beyond) {
    $page = Page::factory()->create();

    /**
     * Refused with a message rather than quietly capped at save: there is a
     * merchant here to tell, and a form that silently takes less than they
     * asked for is one they find out about from a support call.
     */
    $component = Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(fieldRows($page, [questionRow('detail', [
            'type' => $type,
            'constraints' => ['max_length' => $beyond],
        ])]))
        ->call('save')
        ->assertHasFormErrors();

    expect(array_keys($component->errors()->messages()))
        ->each->toEndWith('.constraints.max_length');
})->with([
    'a single line' => ['text', 256],
    'a paragraph' => ['textarea', 1001],
]);

it('offers no box anywhere that takes a rule the merchant wrote themselves', function (string $pattern) {
    $page = Page::factory()->create();

    /**
     * The shape is picked from a closed list, so an expression typed into it by
     * anything driving this form is refused rather than compiled. A malformed
     * rule would not show up in the admin as an error — it would show up as a
     * conversion rate going to zero on a page that is spending money.
     */
    $component = Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(fieldRows($page, [questionRow('gift_note', ['constraints' => ['pattern' => $pattern]])]))
        ->call('save')
        ->assertHasFormErrors();

    expect(array_keys($component->errors()->messages()))
        ->each->toEndWith('.constraints.pattern');

    expect($page->refresh()->checkout_config->fields)->toHaveCount(5);
})->with([
    'an expression of their own' => ['^[a-z]+$'],
    'a rule string' => ['regex:/^[a-z]+$/'],
    'a shape by a name nothing knows' => ['postcode'],
]);

it('asks nothing about bounds on a question there is nothing to bound', function () {
    $page = Page::factory()->create();

    /**
     * A choice is answered by picking and the list is the only bound worth
     * having; a built-in answers to the column waiting for it (ADR-0006). So a
     * bound typed onto either row is not saved, and there is no control on
     * screen that could have typed it.
     */
    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(fieldRows($page, [
            questionRow('colour', [
                'type' => 'select',
                'options' => [['value' => 'sky', 'label' => 'Sky blue']],
                'constraints' => ['min_length' => 8],
            ]),
        ]))
        ->call('save')
        ->assertHasNoFormErrors();

    $stored = collect($page->refresh()->checkout_config->fieldsToArray())->keyBy('key');

    expect($stored['colour']['constraints'])->toBe([])
        ->and($stored['name']['constraints'])->toBe([]);
});

it('restyles a checkout from the checkout tab', function () {
    $product = Product::factory()->create();
    $page = Page::factory()->published()->withBody('<main>[checkout]</main>')->create(['slug' => 'promo']);
    $page->products()->attach($product);

    $this->cache->put('promo', '<h1>stale</h1>');

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['checkout_config' => ['style_tokens' => [
            'accent' => '#ff0000',
            'button_background' => '#101010',
            'radius' => '12px',
            'input_size' => '1.125',
        ]]])
        ->call('save')
        ->assertHasNoFormErrors();

    /** A restyle changes what visitors receive, so the HTML written before it has to go. */
    expect($this->cache->has('promo'))->toBeFalse();

    $html = $this->get('/promo')->assertOk()->getContent();

    expect($html)->toContain('--gio-accent: #ff0000;')
        ->and($html)->toContain('--gio-button-background: #101010;')
        ->and($html)->toContain('--gio-radius: 12px;')
        ->and($html)->toContain('--gio-input-size: 1.125;');
});

it('keeps a token the merchant left alone out of the stored config', function () {
    $page = Page::factory()->create();

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['checkout_config' => ['style_tokens' => ['accent' => '#ff0000']]])
        ->call('save')
        ->assertHasNoFormErrors();

    /**
     * Only what was said. A default copied into every Page's Config would be a
     * decision recorded where none was made, and would have to be migrated the
     * next time one of them changes.
     */
    expect($page->refresh()->checkout_config->styleTokens->toArray())
        ->toBe(['accent' => '#ff0000']);
});

it('refuses a style value on the box that holds it', function (string $token, string $value) {
    $page = Page::factory()->create();

    $component = Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['checkout_config' => ['style_tokens' => [$token => $value]]])
        ->call('save')
        ->assertHasFormErrors(['checkout_config.style_tokens.'.$token]);

    /**
     * One refusal, on the control the merchant has to correct, rather than a
     * page-level complaint about styling in general.
     */
    expect($component->errors()->messages())->toHaveCount(1);
})->with([
    'a colour that does not parse' => ['accent', 'reddish'],
    'a colour that would close the declaration' => ['button_background', 'red; } body { display: none } .x {'],
    'an error colour that does not parse' => ['error', 'reddish'],
    'a selected colour that names another token' => ['selected', 'var(--gio-accent)'],
    'a length whose unit is not allowed' => ['radius', '4pt'],
    'a length that is not a length' => ['border_width', 'thin'],
    'a negative length' => ['font_size', '-1rem'],
    'a size nobody was offered' => ['input_size', '9'],
    /** A width the form would escape the merchant's own layout with. */
    'a width measured against the phone' => ['max_width', '100vw'],
    'a width that is not a width' => ['max_width', 'full'],
]);

it('lets every style box be left empty', function () {
    $page = Page::factory()->create();

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['checkout_config' => ['style_tokens' => array_fill_keys(
            array_map(fn (StyleToken $token): string => $token->value, StyleToken::cases()),
            null,
        )]])
        ->call('save')
        ->assertHasNoFormErrors();

    /** Nothing said is not something to refuse; it is the checkout as it looks. */
    expect($page->refresh()->checkout_config->styleTokens->isEmpty())->toBeTrue();
});

it('lets a merchant restyle one question on its own', function () {
    $product = Product::factory()->create();
    $page = Page::factory()->published()->withBody('<main>[checkout]</main>')->create(['slug' => 'promo']);
    $page->products()->attach($product);

    $rows = $page->checkout_config->fieldsToArray();
    $rows[0]['style_tokens'] = ['border' => '#ff0000', 'border_width' => '2px'];

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['checkout_config' => ['fields' => $rows]])
        ->call('save')
        ->assertHasNoFormErrors();

    $html = $this->get('/promo')->assertOk()->getContent();

    /**
     * The one question the merchant wanted picked out, and nothing else. The
     * override reaches that field alone through the cascade, so drawing
     * attention to it costs a redeclaration and no selector.
     */
    expect($page->refresh()->checkout_config->fields[0]->styleTokens->toArray())
        ->toBe(['border' => '#ff0000', 'border_width' => '2px'])
        ->and(substr_count($html, '--gio-border: #ff0000;'))->toBe(1);
});

it('refuses a style value on the row of the question it was typed on', function () {
    $page = Page::factory()->create();

    $rows = $page->checkout_config->fieldsToArray();
    $rows[1]['style_tokens'] = ['accent' => 'reddish'];

    $component = Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['checkout_config' => ['fields' => $rows]])
        ->call('save')
        ->assertHasFormErrors();

    expect($component->errors()->messages())
        ->toHaveCount(1)
        ->and(array_key_first($component->errors()->messages()))->toEndWith('.style_tokens.accent');
});

it('restyles one question in the checkout tab and serves it that way', function () {
    $product = Product::factory()->create();
    $page = Page::factory()->published()->withBody('<main>[checkout]</main>')->create(['slug' => 'promo']);
    $page->products()->attach($product);

    $rows = $page->checkout_config->fieldsToArray();
    $rows[0]['style_tokens'] = ['text' => '#ff0000', 'input_size' => '1.125'];

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['checkout_config' => ['fields' => $rows]])
        ->call('save')
        ->assertHasNoFormErrors();

    /**
     * Text colour and size are the two a merchant reaches for to pick a
     * question out, and both are inherited properties the root also declares —
     * so this is the case that would silently do nothing if the field wrapper
     * did not read the tokens itself.
     */
    expect($this->get('/promo')->assertOk()->getContent())
        ->toContain('style="--gio-text: #ff0000; --gio-input-size: 1.125;"');
});

it('asks a question about no token nothing inside it reads', function () {
    /**
     * A question holds no order button, so the three tokens that dress one are
     * not offered on a field row — nor is the width, which for a question is
     * the grid's to decide and is chosen from two rather than typed. A control
     * that provably does nothing is worse than no control: a merchant would set
     * the button colour on a question, see no change, and have nothing to tell
     * them why.
     */
    $inert = array_filter(StyleToken::cases(), fn (StyleToken $token): bool => ! $token->appliesToOneField());

    expect(array_map(fn (StyleToken $token): string => $token->value, array_values($inert)))
        ->toBe(['max_width', 'button_background', 'button_text', 'button_radius']);

    $page = Page::factory()->create();

    $rendered = Livewire::test(EditPage::class, ['record' => $page->getKey()])->html();

    /**
     * A border colour is asked for once for the checkout and once on every one
     * of the five questions; a button colour only for the checkout. Counting
     * rather than matching a state path, because the path of a repeater row is
     * a generated id and not something a test should know.
     */
    expect(substr_count($rendered, 'style_tokens.border'))
        ->toBeGreaterThan(substr_count($rendered, 'style_tokens.button_background'));
});

it('lets a merchant arrange the checkout without its product panel', function () {
    $product = Product::factory()->create(['name' => 'Winter Jacket']);
    $page = Page::factory()->published()->withBody('<main>[checkout]</main>')->create(['slug' => 'promo']);
    $page->products()->attach($product);

    $this->cache->put('promo', '<h1>stale</h1>');

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['checkout_config' => ['layout' => CheckoutLayout::FormOnly->value]])
        ->call('save')
        ->assertHasNoFormErrors();

    /** A different arrangement is different bytes, so the written HTML has to go. */
    expect($this->cache->has('promo'))->toBeFalse()
        ->and($page->refresh()->checkout_config->layout)->toBe(CheckoutLayout::FormOnly);

    $html = $this->get('/promo')->assertOk()->getContent();

    /**
     * The page's own markup sold the jacket above the fold. The checkout stops
     * repeating it and still takes the order.
     */
    expect($html)->not->toContain('Winter Jacket')
        ->and($html)->toContain('gio-checkout-form-only')
        ->and($html)->toContain('name="quantities['.$product->getKey().']" value="1"');
});

it('lets a merchant lead the checkout with the total', function () {
    $page = Page::factory()->published()->withBody('<main>[checkout]</main>')->create(['slug' => 'promo']);
    $page->products()->attach(Product::factory()->create(['name' => 'Winter Jacket']));

    $this->cache->put('promo', '<h1>stale</h1>');

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['checkout_config' => ['layout' => CheckoutLayout::SummaryFirst->value]])
        ->call('save')
        ->assertHasNoFormErrors();

    /** A different arrangement is different bytes, so the written HTML has to go. */
    expect($this->cache->has('promo'))->toBeFalse()
        ->and($page->refresh()->checkout_config->layout)->toBe(CheckoutLayout::SummaryFirst);

    $html = $this->get('/promo')->assertOk()->getContent();

    /**
     * Picked from the same list as every other arrangement, and the page the
     * merchant is served back is the one they picked: the jacket still sold and
     * still priced, under a root naming the arrangement. What that arrangement
     * then draws is LandingPageTest's to pin; what is pinned here is that a
     * choice made on the tab reaches a visitor at all.
     */
    expect($html)->toContain('gio-checkout-summary-first')
        ->and($html)->toContain('Winter Jacket');
});

it('lets a merchant put the checkout form beside what it sells', function () {
    $page = Page::factory()->published()->withBody('<main>[checkout]</main>')->create(['slug' => 'promo']);
    $page->products()->attach(Product::factory()->create(['name' => 'Winter Jacket']));

    $this->cache->put('promo', '<h1>stale</h1>');

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['checkout_config' => ['layout' => CheckoutLayout::Split->value]])
        ->call('save')
        ->assertHasNoFormErrors();

    /** A different arrangement is different bytes, so the written HTML has to go. */
    expect($this->cache->has('promo'))->toBeFalse()
        ->and($page->refresh()->checkout_config->layout)->toBe(CheckoutLayout::Split);

    $html = $this->get('/promo')->assertOk()->getContent();

    /**
     * Picked from the same list as every other arrangement, and the jacket is
     * still sold and still priced under a root naming what was chosen. Where
     * the two columns land and what stays on screen is LandingPageTest's to
     * pin; what is pinned here is that the choice reaches a visitor at all.
     */
    expect($html)->toContain('gio-checkout-split')
        ->and($html)->toContain('Winter Jacket');
});

it('keeps the picker a merchant set while the layout has nothing to tick', function () {
    $page = Page::factory()->create();

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['checkout_config' => ['product_picker' => true]])
        ->call('save')
        ->assertHasNoFormErrors();

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['checkout_config' => ['layout' => CheckoutLayout::FormOnly->value]])
        ->call('save')
        ->assertHasNoFormErrors();

    /**
     * The switch survives the layout that has nowhere to draw it, so switching
     * back restores the page the merchant configured. What it must not do
     * meanwhile is stand: a picker on a form-only page compiles a rule
     * demanding a choice the form never offered, and every order on a live page
     * is refused for a box no customer can find.
     */
    $config = $page->refresh()->checkout_config;

    expect($config->productPicker)->toBeTrue()
        ->and($config->layout)->toBe(CheckoutLayout::FormOnly)
        ->and($config->picksProducts())->toBeFalse();
});

it('pre-ticks the products a merchant chose, and offers only the ones the page links', function () {
    $jacket = Product::factory()->create(['name' => 'Winter Jacket']);
    $scarf = Product::factory()->create(['name' => 'Wool Scarf']);
    $unlinked = Product::factory()->create(['name' => 'Hiking Boots']);

    /** Linked to nothing yet, so what is offered below can only have come from the form. */
    $page = Page::factory()->create();

    $edit = Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm([
            'products' => [$jacket->getKey(), $scarf->getKey()],
            'checkout_config' => [
                'product_picker' => true,
                'default_selection' => [$jacket->getKey()],
            ],
        ]);

    /**
     * A merchant ticks among what this page sells, read off the links as they
     * stand in the form rather than off the saved page — so a product linked
     * and pre-ticked in one visit needs no save in between, and nothing offers
     * a tick against something the page does not sell. Asserted before the
     * save, which is the whole of what that claim means.
     */
    $ticks = fieldInside($edit->instance()->getSchema('form'), 'checkout_config.default_selection');

    expect($ticks)->not->toBeNull()
        ->and(array_keys($ticks->getOptions()))->toBe([$jacket->getKey(), $scarf->getKey()])
        ->and(array_keys($ticks->getOptions()))->not->toContain($unlinked->getKey());

    $edit->call('save')->assertHasNoFormErrors();

    expect($page->refresh()->checkout_config->defaultSelection)->toBe([$jacket->getKey()]);
});

it('keeps live every control the default selection reads', function () {
    $page = Page::factory()->create();

    /**
     * The tick list builds its options from the links and holds itself shut on
     * the picker and the layout, so all three have to report their state as it
     * is changed. Left un-live, a merchant links a product and finds no box
     * for it, or switches the picker on and finds the list still greyed out —
     * with nothing failing and no error to act on. The layout is the one most
     * easily dropped, because it is on another tab.
     */
    $tabs = checkoutTabsOf($page);

    $reads = [
        fieldInside($tabs['Products'], 'products'),
        fieldInside($tabs['Products'], 'checkout_config.product_picker'),
        fieldInside($tabs['Products'], 'checkout_config.default_selection'),
        fieldInside($tabs['Layout'], 'checkout_config.layout'),
    ];

    foreach ($reads as $control) {
        expect($control)->not->toBeNull()
            ->and($control->isLive())->toBeTrue();
    }

    /**
     * A control nothing reads, so this test fails for the reason it says it
     * does rather than because every field in the panel reports live.
     */
    expect(fieldInside(tabsInside(editFormOf($page))[0], 'slug')->isLive())->toBeFalse();
});

it('keeps the products a merchant pre-ticked while nothing can be ticked', function () {
    $jacket = Product::factory()->create();
    $scarf = Product::factory()->create();

    $page = Page::factory()->create();
    $page->products()->attach([$jacket->getKey(), $scarf->getKey()]);

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['checkout_config' => [
            'product_picker' => true,
            'default_selection' => [$jacket->getKey()],
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['checkout_config' => ['layout' => CheckoutLayout::FormOnly->value]])
        ->call('save')
        ->assertHasNoFormErrors();

    /**
     * The list survives a layout with nowhere to draw it, exactly as the
     * switch above it does, so switching back restores the page the merchant
     * configured. What it must not do meanwhile is stand: a tick on a
     * form-only page is a box nobody can see and nobody can untick.
     */
    $config = $page->refresh()->checkout_config;

    expect($config->defaultSelection)->toBe([$jacket->getKey()])
        ->and($config->opensTicked())->toBe([]);
});

it('keeps the questions and the delivery options a page had when the layout changes', function () {
    $page = Page::factory()->create();

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(deliveryOptionRows([['label' => 'Inside Dhaka', 'amount' => '60']]))
        ->call('save')
        ->assertHasNoFormErrors();

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['checkout_config' => ['layout' => CheckoutLayout::FormOnly->value]])
        ->call('save')
        ->assertHasNoFormErrors();

    /**
     * One Checkout Config, edited a control at a time. A layout saved on the
     * Checkout tab must not take the rest of the tab down with it — and a
     * layout decides what is drawn around the form, never what it asks for or
     * what it charges.
     */
    $config = $page->refresh()->checkout_config;

    expect($config->deliveryOptionsToArray())->toBe([['label' => 'Inside Dhaka', 'amount' => 6000]])
        ->and(array_map(fn (CheckoutField $field): string => $field->key(), $config->fields))
        ->toBe(['name', 'phone', 'email', 'district', 'address']);
});

it('lets a merchant sit two short questions side by side', function () {
    $product = Product::factory()->create();
    $page = Page::factory()->published()->withBody('<main>[checkout]</main>')->create(['slug' => 'promo']);
    $page->products()->attach($product);

    $rows = $page->checkout_config->fieldsToArray();
    $rows[0]['width'] = FieldWidth::Half->value;
    $rows[1]['width'] = FieldWidth::Half->value;

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['checkout_config' => ['fields' => $rows]])
        ->call('save')
        ->assertHasNoFormErrors();

    /**
     * Offered on a built-in like any other question: name beside phone is the
     * pairing a merchant reaches for first, and where an answer goes is what a
     * built-in has no say over — how wide the question sits is not that.
     */
    $config = $page->refresh()->checkout_config;

    expect($config->fields[0]->width)->toBe(FieldWidth::Half)
        ->and($config->fields[1]->width)->toBe(FieldWidth::Half)
        ->and($config->fields[2]->width)->toBe(FieldWidth::Full);

    expect(substr_count(
        $this->get('/promo')->assertOk()->getContent(),
        'class="gio-checkout-field gio-checkout-field-half"',
    ))->toBe(2);
});

it('says where the widths are set that a compact page will not pair them', function () {
    $page = Page::factory()->create([
        'checkout_config' => CheckoutConfig::fromArray(['layout' => CheckoutLayout::Compact->value]),
    ]);

    $compact = Livewire::test(EditPage::class, ['record' => $page->getKey()])->html();

    $page->update(['checkout_config' => CheckoutConfig::fromArray([])]);

    $paired = Livewire::test(EditPage::class, ['record' => $page->getKey()])->html();

    /**
     * A control a merchant can still see and set, on a page that has stopped
     * drawing it, is the setting with no error this whole tab keeps guarding
     * against. It is said where the widths are, rather than left on the layout
     * tab the merchant has already moved on from — and it goes away again the
     * moment the page is arranged in something that pairs.
     */
    expect($compact)->toContain('asks every question across the whole row')
        ->and($paired)->not->toContain('asks every question across the whole row');
});

it('gives a compacted page back its pairing when the merchant moves it off compact', function () {
    $product = Product::factory()->create();
    $page = Page::factory()->published()->withBody('<main>[checkout]</main>')->create(['slug' => 'promo']);
    $page->products()->attach($product);

    $rows = $page->checkout_config->fieldsToArray();
    $rows[0]['width'] = FieldWidth::Half->value;
    $rows[1]['width'] = FieldWidth::Half->value;

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['checkout_config' => [
            'layout' => CheckoutLayout::Compact->value,
            'fields' => $rows,
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    /**
     * Compact asks every question across the whole row, and says nothing back
     * to the config about it: a merchant who compacts a page has not thrown
     * away the pairing they arranged, and the control they can still see on the
     * questions tab still means what it says.
     */
    $compacted = $page->refresh()->checkout_config;

    expect($compacted->layout)->toBe(CheckoutLayout::Compact)
        ->and($compacted->fields[0]->width)->toBe(FieldWidth::Half)
        ->and($compacted->fields[1]->width)->toBe(FieldWidth::Half);

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['checkout_config' => ['layout' => CheckoutLayout::Stacked->value]])
        ->call('save')
        ->assertHasNoFormErrors();

    /**
     * So moving back is one control: the two questions still carry the class
     * that pairs them, and the block that was filling their rows is gone from
     * the page rather than something a merchant has to undo question by
     * question.
     */
    expect($page->refresh()->checkout_config->layout)->toBe(CheckoutLayout::Stacked);

    $html = $this->get('/promo')->assertOk()->getContent();

    expect(substr_count($html, 'class="gio-checkout-field gio-checkout-field-half"'))->toBe(2)
        ->and($html)->not->toContain('gio-checkout-compact');
});

it('boxes a checkout at the width typed on the checkout tab', function () {
    $product = Product::factory()->create();
    $page = Page::factory()->published()->withBody('<main>[checkout]</main>')->create(['slug' => 'promo']);
    $page->products()->attach($product);

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['checkout_config' => ['style_tokens' => ['max_width' => '100%']]])
        ->call('save')
        ->assertHasNoFormErrors();

    /**
     * Width is a token like the colours beside it, so it is typed in the same
     * section, refused by the same closure and emitted as the same kind of
     * declaration. A percentage resolves against the element the merchant put
     * [checkout] in, which is what full width means here.
     */
    expect($this->get('/promo')->assertOk()->getContent())->toContain('--gio-max-width: 100%;');
});

/**
 * The tabs directly inside a schema or a component, in the order a merchant
 * meets them.
 *
 * A `Tabs` is transparent here: it is the arrangement, not a thing on screen,
 * so asking a Tab what tabs are inside it answers with the nested ones rather
 * than with the container that holds them.
 *
 * @return list<Tab>
 */
function tabsInside(Schema|Component $node): array
{
    $schema = $node instanceof Schema ? $node : $node->getChildSchema();

    $tabs = [];

    foreach ($schema?->getComponents(withHidden: true) ?? [] as $component) {
        if ($component instanceof Tabs) {
            $tabs = [...$tabs, ...tabsInside($component)];

            continue;
        }

        if ($component instanceof Tab) {
            $tabs[] = $component;
        }
    }

    return $tabs;
}

/**
 * Every state path a merchant can type into somewhere under this component,
 * however deeply it is nested.
 *
 * Read without the form's own root, so a path here is the one the Config is
 * written at rather than the Livewire property the panel happens to hold the
 * whole form in.
 *
 * @return list<string>
 */
function statePathsInside(Schema|Component $node): array
{
    $schemas = $node instanceof Schema ? [$node] : $node->getChildSchemas(withHidden: true);

    $paths = [];

    foreach ($schemas as $schema) {
        foreach ($schema->getComponents(withHidden: true) as $component) {
            if ($component instanceof Field) {
                $paths[] = Str::after(
                    $component->getStatePath(),
                    $component->getRootContainer()->getStatePath().'.',
                );
            }

            $paths = [...$paths, ...statePathsInside($component)];
        }
    }

    return $paths;
}

/**
 * One control a merchant types into, found by the path its value is written
 * at.
 *
 * The same walk `statePathsInside()` makes, stopping at the field rather than
 * collecting every path — so a test can ask what a control is *offering* and
 * not only that it is on the right tab.
 */
function fieldInside(Schema|Component $node, string $path): ?Field
{
    $schemas = $node instanceof Schema ? [$node] : $node->getChildSchemas(withHidden: true);

    foreach ($schemas as $schema) {
        foreach ($schema->getComponents(withHidden: true) as $component) {
            if ($component instanceof Field && Str::after(
                $component->getStatePath(),
                $component->getRootContainer()->getStatePath().'.',
            ) === $path) {
                return $component;
            }

            if ($found = fieldInside($component, $path)) {
                return $found;
            }
        }
    }

    return null;
}

/**
 * The form a merchant is actually served on the edit screen, rather than the
 * schema classes that build it — so what is asserted is what they open.
 */
function editFormOf(Page $page): Schema
{
    return Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->instance()
        ->getSchema('form');
}

/**
 * The nested tabs the Checkout tab is split into, by their label.
 *
 * @return Collection<string, Tab>
 */
function checkoutTabsOf(Page $page): Collection
{
    $checkout = collect(tabsInside(editFormOf($page)))
        /** Exactly one, so a Checkout tab that went missing fails here rather than further down. */
        ->sole(fn (Tab $tab): bool => (string) $tab->getLabel() === 'Checkout');

    return collect(tabsInside($checkout))
        ->keyBy(fn (Tab $tab): string => (string) $tab->getLabel());
}

it('splits the checkout tab into one nested tab per concern', function () {
    /**
     * One concern on screen at a time, in the order a merchant reads them: the
     * layout is what decides whether products can be picked from at all, so it
     * is met before the picker it gates.
     */
    expect(checkoutTabsOf(Page::factory()->create())->keys()->all())
        ->toBe(['Layout', 'Products', 'Questions', 'Delivery', 'Payment', 'Appearance']);
});

it('keeps everything the checkout decides inside the checkout tab', function () {
    /**
     * A Checkout Config is one thing a Page decides, so splitting it up inside
     * the tab must not promote its parts to sit beside the Page itself. The
     * Order Complete Config is another thing a Page decides, and sits beside
     * it rather than inside it: the Checkout captures the Order, and that is
     * where the Customer lands afterwards.
     */
    expect(array_map(
        fn (Tab $tab): string => (string) $tab->getLabel(),
        tabsInside(editFormOf(Page::factory()->create())),
    ))->toBe(['Page', 'Checkout', 'Order complete']);
});

it('reaches and saves a setting on every nested checkout tab', function (string $tab) {
    $page = Page::factory()->create();

    $questions = $page->checkout_config->fieldsToArray();
    $questions[0]['label'] = 'Your full name';

    /**
     * One setting per concern: where it is asked, what is typed into it, and
     * what the Config holds afterwards, kept together so a tab cannot be
     * checked against the wrong box.
     *
     * @var array{control: string, typed: array<string, mixed>, saved: Closure(CheckoutConfig): mixed, expected: mixed} $setting
     */
    $setting = match ($tab) {
        'Layout' => [
            'control' => 'checkout_config.layout',
            'typed' => ['checkout_config' => ['layout' => CheckoutLayout::FormOnly->value]],
            'saved' => fn (CheckoutConfig $config): CheckoutLayout => $config->layout,
            'expected' => CheckoutLayout::FormOnly,
        ],
        'Products' => [
            'control' => 'checkout_config.product_picker',
            'typed' => ['checkout_config' => ['product_picker' => true]],
            'saved' => fn (CheckoutConfig $config): bool => $config->productPicker,
            'expected' => true,
        ],
        'Questions' => [
            'control' => 'checkout_config.fields',
            'typed' => ['checkout_config' => ['fields' => $questions]],
            'saved' => fn (CheckoutConfig $config): string => $config->fields[0]->label,
            'expected' => 'Your full name',
        ],
        'Delivery' => [
            'control' => 'checkout_config.delivery_options',
            'typed' => deliveryOptionRows([['label' => 'Next day', 'amount' => '90']]),
            'saved' => fn (CheckoutConfig $config): array => $config->deliveryOptionsToArray(),
            'expected' => [['label' => 'Next day', 'amount' => 9000]],
        ],
        'Payment' => [
            'control' => 'checkout_config.hide_lone_cash_choice',
            'typed' => ['checkout_config' => ['hide_lone_cash_choice' => false]],
            'saved' => fn (CheckoutConfig $config): bool => $config->hideLoneCashChoice,
            'expected' => false,
        ],
        'Appearance' => [
            'control' => 'checkout_config.style_tokens.accent',
            'typed' => ['checkout_config' => ['style_tokens' => ['accent' => '#ff0000']]],
            'saved' => fn (CheckoutConfig $config): ?string => $config->styleTokens->value(StyleToken::Accent),
            'expected' => '#ff0000',
        ],
    };

    /**
     * Reached first, and on this tab rather than anywhere on the form: filling
     * a form writes Livewire state straight through, so a test that only saved
     * would pass just as well with all five settings back on one scroll.
     */
    expect(statePathsInside(checkoutTabsOf($page)[$tab]))->toContain($setting['control']);

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm($setting['typed'])
        ->call('save')
        ->assertHasNoFormErrors();

    /** And still written where it always was: no stored Config changed shape. */
    expect(($setting['saved'])($page->refresh()->checkout_config))->toBe($setting['expected']);
})->with(['Layout', 'Products', 'Questions', 'Delivery', 'Payment', 'Appearance']);

it('puts the checkout-wide appearance controls where no question stands in front of them', function () {
    $tabs = checkoutTabsOf(Page::factory()->create());

    $appearance = statePathsInside($tabs['Appearance']);
    $questions = statePathsInside($tabs['Questions']);

    /**
     * The whole named token set, on a tab of its own — so a merchant changing
     * a colour no longer scrolls past every question they have ever authored
     * to reach it.
     */
    expect($appearance)->toContain('checkout_config.style_tokens.accent')
        ->and($appearance)->toContain('checkout_config.style_tokens.button_background');

    /**
     * A question's own overrides stay in the question's row, because what is
     * being restyled there is the question. So the questions tab holds style
     * boxes too — none of them the checkout's.
     */
    expect(array_filter($questions, fn (string $path): bool => str_starts_with($path, 'checkout_config.style_tokens.')))
        ->toBeEmpty()
        ->and(array_filter($questions, fn (string $path): bool => (bool) preg_match(
            '/^checkout_config\.fields\..+\.style_tokens\.border$/',
            $path,
        )))->not->toBeEmpty();
});

/**
 * The questions repeater a merchant is actually served, taken off the live
 * edit form rather than built from the schema class — so what is asserted is
 * the control they open.
 */
function questionsRepeaterIn(Testable $livewire): Repeater
{
    return $livewire->instance()->getSchema('form')
        ->getFlatComponents(withHidden: true)['checkout_config.fields'];
}

/**
 * What each collapsed question row says about itself, filed under the key the
 * question is stored as.
 *
 * @return array<string, string>
 */
function questionSummariesOf(Page $page): array
{
    $questions = questionsRepeaterIn(Livewire::test(EditPage::class, ['record' => $page->getKey()]));

    $summaries = [];

    foreach (array_keys($questions->getItems()) as $itemKey) {
        $summaries[$questions->getRawItemState($itemKey)['key']] = (string) $questions->getItemLabel($itemKey);
    }

    return $summaries;
}

it('opens the questions tab with every row collapsed', function () {
    /**
     * Ten questions are ten lines to scan, not a page to scroll. Asserted on
     * each row rather than on the repeater, because collapsed is decided per
     * item and a merchant reads the rows one at a time.
     */
    $questions = questionsRepeaterIn(Livewire::test(EditPage::class, [
        'record' => Page::factory()->create()->getKey(),
    ]));

    expect($questions->isCollapsible())->toBeTrue()
        ->and($questions->getItems())->not->toBeEmpty();

    foreach ($questions->getItems() as $item) {
        expect($questions->isCollapsed($item))->toBeTrue();
    }
});

it('opens a question the merchant has just added', function () {
    /**
     * Collapsing the rows must not make adding one slower. A row that arrived
     * shut would cost a click before the first box the merchant came here to
     * type in — every time, on a control whose whole purpose is to be typed
     * into.
     */
    $livewire = Livewire::test(EditPage::class, ['record' => Page::factory()->create()->getKey()])
        ->mountAction(TestAction::make('add')->schemaComponent('checkout_config.fields'));

    $questions = questionsRepeaterIn($livewire);

    expect($questions->getItems())->toHaveCount(6)
        ->and($questions->isCollapsed())->toBeFalse();
});

it('says enough about a collapsed question to identify it without opening it', function () {
    $page = Page::factory()->create();

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(fieldRows($page, [questionRow('gift_note', [
            'label' => 'Anything we should write on the card?',
            'type' => 'textarea',
            'required' => true,
            'width' => 'half',
        ])]))
        ->call('save')
        ->assertHasNoFormErrors();

    $summaries = questionSummariesOf($page->refresh());

    /**
     * Everything a merchant picks a question by: the words the customer reads,
     * how they are asked, whether they may leave it, and how much of the row
     * it takes. Pinned whole rather than by fragments, because the summary is
     * one line a merchant scans and the order the parts arrive in is what
     * makes ten of them scannable.
     */
    expect($summaries['gift_note'])
        ->toBe('Anything we should write on the card? · Paragraph · Must be answered · Half width');

    /**
     * A built-in is summarised by the type its column decides rather than by
     * whatever the row happens to carry — the same reading the row's own
     * controls make.
     */
    expect($summaries['district'])->toBe('District · Dropdown · Must be answered · Full width')
        ->and($summaries['address'])->toBe('Delivery address · Paragraph · Must be answered · Full width')
        /**
         * Phone's two switches are held shut, and a control a merchant cannot
         * throw is one Filament drops from the row unless it is told
         * otherwise. Left dropped, the summary would read the absence as an
         * answer and call the one field a checkout may never stop asking for
         * optional.
         */
        ->and($summaries['phone'])->toBe('Phone number · Single line · Must be answered · Full width');
});

it('says separately that a question is not asked and that its answer is compulsory', function () {
    $page = Page::factory()->create();

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(fieldRows($page, [questionRow('gift_note', [
            'label' => 'Gift note',
            'enabled' => false,
            'required' => true,
        ])]))
        ->call('save')
        ->assertHasNoFormErrors();

    /**
     * Two different things, and the summary must not spend one word on both. A
     * switched-off question keeps what the merchant configured behind it, so a
     * row can be one the page does not ask and one whose answer is compulsory
     * on the day it comes back — and a merchant reading "Optional" there would
     * switch it on to find a box they cannot get past.
     */
    $summaries = questionSummariesOf($page->refresh());

    expect($summaries['gift_note'])->toBe('Gift note · Not asked · Single line · Must be answered · Full width')
        /** Off and optional, which is where email starts, still reads as both. */
        ->and($summaries['email'])->toBe('Email address · Not asked · Single line · Optional · Full width');
});

it('hangs the theme\'s dimming hook on the switch that stops a question being asked', function () {
    /**
     * A switched-off row is dimmed by `forms.css`, which finds it by this
     * attribute on this one control — see `Questions::ASKED_MARKER`. Nothing
     * in the rendered HTML says which rule matched it, so the pair is pinned
     * here or it breaks silently and a merchant is left reading a dead
     * question as a live one.
     */
    $questions = questionsRepeaterIn(Livewire::test(EditPage::class, [
        'record' => Page::factory()->create()->getKey(),
    ]));

    $switches = [];

    foreach (array_keys($questions->getItems()) as $itemKey) {
        foreach ($questions->getChildSchema($itemKey)->getComponents(withHidden: true) as $component) {
            if ($component instanceof Toggle && $component->getName() === 'enabled') {
                $switches[] = $component->getExtraAttributes();
            }
        }
    }

    expect($switches)->toHaveCount(5)
        ->each->toHaveKey(Questions::ASKED_MARKER);
});

it('reorders the questions while their rows are collapsed', function () {
    $page = Page::factory()->create();
    $livewire = Livewire::test(EditPage::class, ['record' => $page->getKey()]);

    $questions = questionsRepeaterIn($livewire);
    $rows = array_keys($questions->getItems());

    /** Collapsed is the state a row is dragged in, so it is the state it is dragged from here. */
    expect($questions->isCollapsed($questions->getChildSchema($rows[0])))->toBeTrue();

    /**
     * Called rather than mounted, because the handle is only drawn while the
     * repeater is reorderable — so this asserts a merchant can reach the drag
     * before it asserts the drag lands.
     */
    $livewire
        ->callAction(
            TestAction::make('reorder')->schemaComponent('checkout_config.fields'),
            arguments: ['items' => [$rows[1], $rows[0], ...array_slice($rows, 2)]],
        )
        ->call('save')
        ->assertHasNoFormErrors();

    expect(array_map(fn (CheckoutField $field): string => $field->key(), $page->refresh()->checkout_config->fields))
        ->toBe(['phone', 'name', 'email', 'district', 'address']);
});

it('lets a merchant word the order button and the line beneath it', function () {
    $product = Product::factory()->create();
    $page = Page::factory()->published()->withBody('<main>[checkout]</main>')->create(['slug' => 'promo']);
    $page->products()->attach($product);

    /** Asked where the rest of how the checkout looks is asked, and nowhere else. */
    expect(statePathsInside(checkoutTabsOf($page)['Appearance']))
        ->toContain('checkout_config.order_button.wording')
        ->toContain('checkout_config.order_button.note');

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['checkout_config' => ['order_button' => [
            'wording' => 'Order for {total}',
            'note' => 'You pay the courier when it arrives.',
        ]]])
        ->call('save')
        ->assertHasNoFormErrors();

    $html = $this->get('/promo')->assertOk()->getContent();

    expect($html)->toContain('data-gio-submit-wording="Order for {total}"')
        ->and($html)->toContain('<p class="gio-checkout-note">You pay the courier when it arrives.</p>');
});

it('keeps button copy the merchant left alone out of the stored config', function () {
    $page = Page::factory()->create();

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['checkout_config' => ['order_button' => ['wording' => '  ', 'note' => '']]])
        ->call('save')
        ->assertHasNoFormErrors();

    /** Blank is the merchant saying nothing, so the page keeps the words it always had rather than storing a blank. */
    expect($page->refresh()->checkout_config->orderButton->wording)->toBeNull()
        ->and($page->checkout_config->orderButton->note)->toBeNull();
});

it('refuses button copy on the box that holds it', function (array $typed, string $box) {
    $page = Page::factory()->create();

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['checkout_config' => ['order_button' => $typed]])
        ->call('save')
        ->assertHasFormErrors([$box]);
})->with([
    'a wording too long for a button' => [['wording' => str_repeat('x', 81)], 'checkout_config.order_button.wording'],
    'a wording broken over two lines' => [['wording' => "Order\nnow"], 'checkout_config.order_button.wording'],
    'a note too long for one line beneath the button' => [['note' => str_repeat('y', 201)], 'checkout_config.order_button.note'],
]);

it('starts a new page off offering cash on delivery alone with the choice hidden', function () {
    $page = Page::factory()->create();

    /**
     * Nothing about an existing page changes when paying by other means
     * arrives: cash on delivery only, no chooser drawn, logos on for the day
     * a second method is listed.
     */
    expect($page->checkout_config->paymentMethods)->toBe([PaymentMethod::CashOnDelivery])
        ->and($page->checkout_config->hideLoneCashChoice)->toBeTrue()
        ->and($page->checkout_config->showPaymentLogos)->toBeTrue()
        ->and($page->checkout_config->paymentChooser()->isDrawn())->toBeFalse();
});

it('asks about payment on its own nested tab and lists the methods the installation has switched on', function () {
    $payment = checkoutTabsOf(Page::factory()->create())['Payment'];

    /** The list, the logos switch and the lone-cash switch, together and nowhere else. */
    expect(statePathsInside($payment))
        ->toContain('checkout_config.payment_methods')
        ->toContain('checkout_config.show_payment_logos')
        ->toContain('checkout_config.hide_lone_cash_choice');

    $offered = fieldInside($payment, 'checkout_config.payment_methods');

    /** Cash on delivery alone, which is all an installation with no Payment settings saved has switched on. */
    expect($offered)->toBeInstanceOf(Select::class)
        ->and($offered->getOptions())->toBe(['cash_on_delivery' => 'Cash on Delivery'])
        ->and($offered->isMultiple())->toBeTrue()
        /** Dragging into the order the checkout draws them is the whole of what "ordered" means here. */
        ->and($offered->isReorderable())->toBeTrue()
        /** So the wording beneath it follows the list as it is edited. */
        ->and($offered->isLive())->toBeTrue();

    /** Switched on in Settings, and the box offers it — usable or not, because the refusal is worded rather than hidden. */
    app(PaymentSettings::class)->with(['sslcommerzEnabled' => true])->save();

    $offered = fieldInside(checkoutTabsOf(Page::factory()->create())['Payment'], 'checkout_config.payment_methods');

    expect($offered->getOptions())->toBe(['cash_on_delivery' => 'Cash on Delivery', 'sslcommerz' => 'SSLCommerz']);
});

it('words a refusal beside a listed method the installation cannot offer, and keeps the method listed', function () {
    $page = payingBy(Page::factory()->create(), ['sslcommerz', 'cash_on_delivery']);

    /**
     * Listed by the page and switched off for the installation: still in the
     * box where the merchant put it, so switching it back on in Settings
     * restores the page's order, and worded so the merchant knows the
     * checkout is not drawing it.
     */
    $edit = Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->assertSee('SSLCommerz is switched off in Settings. It is not drawn on this page until that changes.');

    $offered = fieldInside($edit->instance()->getSchema('form'), 'checkout_config.payment_methods');

    expect($offered->getOptions())->toBe(['cash_on_delivery' => 'Cash on Delivery', 'sslcommerz' => 'SSLCommerz']);

    $edit->call('save')->assertHasNoFormErrors();

    expect(PaymentMethod::names($page->refresh()->checkout_config->paymentMethods))->toBe(['sslcommerz', 'cash_on_delivery'])
        ->and($page->checkout_config->offeredMethods())->toBe([PaymentMethod::CashOnDelivery]);

    /** On, but with a credential blank: the wording changes with the reason. */
    app(PaymentSettings::class)->with(['sslcommerzEnabled' => true])->save();

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->assertSee('SSLCommerz has blank credentials in Settings.');

    /** Usable at last: no refusal, and the checkout draws it first, as the page lists it. */
    app(PaymentSettings::class)->with(['sslcommerzStoreId' => 'store_live_1', 'sslcommerzStorePassword' => 'pw_live_1'])->save();

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->assertDontSee('It is not drawn on this page');

    expect($page->refresh()->checkout_config->offeredMethods())->toBe([PaymentMethod::SslCommerz, PaymentMethod::CashOnDelivery]);
});

it('lets a merchant unhide the lone cash on delivery choice and switch its logo off', function () {
    $product = Product::factory()->create();
    $page = Page::factory()->published()->withBody('<main>[checkout]</main>')->create(['slug' => 'promo']);
    $page->products()->attach($product);

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['checkout_config' => [
            'payment_methods' => [PaymentMethod::CashOnDelivery->value],
            'show_payment_logos' => false,
            'hide_lone_cash_choice' => false,
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    $config = $page->refresh()->checkout_config;

    expect($config->paymentMethods)->toBe([PaymentMethod::CashOnDelivery])
        ->and($config->showPaymentLogos)->toBeFalse()
        ->and($config->hideLoneCashChoice)->toBeFalse();

    $html = $this->get('/promo')->assertOk()->getContent();

    expect($html)->toContain('name="payment_method"')
        ->and($html)->toContain('gio-checkout-choice-label">Cash on Delivery<')
        ->and($html)->not->toContain('gio-checkout-logo');
});

it('keeps offering cash on delivery when the merchant empties the list', function () {
    $page = Page::factory()->create();

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['checkout_config' => ['payment_methods' => []]])
        ->call('save')
        ->assertHasNoFormErrors();

    $config = $page->refresh()->checkout_config;

    /**
     * The list is stored as the merchant left it, and the offered reading is
     * what falls back: a page must never be left with no way to order.
     */
    expect($config->paymentMethods)->toBe([])
        ->and($config->offeredMethods())->toBe([PaymentMethod::CashOnDelivery]);
});

/**
 * The outer tab the Order Complete Page is configured on.
 */
function orderCompleteTabOf(Page $page): Tab
{
    return collect(tabsInside(editFormOf($page)))
        ->sole(fn (Tab $tab): bool => (string) $tab->getLabel() === 'Order complete');
}

it('gives a new page a complete order complete config nobody had to configure', function () {
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

    $config = Page::where('slug', 'winter-promo')->sole()->order_complete_config;

    /**
     * Seeded on create and never partial: nothing was typed, so every word is
     * the built-in one and the page is drawn as Centered classic.
     */
    expect($config->layout)->toBe(OrderCompleteLayout::CenteredClassic)
        ->and($config->headline)->toBeNull()
        ->and($config->headline())->toBe('Your order is confirmed')
        ->and($config->primaryButton->label)->toBeNull()
        ->and($config->secondaryButton->label)->toBeNull();
});

it('reads an order complete config a page had before the column, or one holding what nothing draws, as the default', function (string $stored) {
    $page = sellingPage(Product::factory()->create());

    DB::table('pages')->where('id', $page->getKey())->update(['order_complete_config' => $stored]);

    $this->postJson(route('checkout.store'), capturePayload($page))->assertCreated();

    /** Read totally: a missing key or an unknown value is the default for that key, and never a 500 in front of a Customer. */
    $this->get(route('checkout.order-complete', Order::sole()->order_number))
        ->assertOk()
        ->assertSee('class="gio-order-complete gio-order-complete-centered-classic"', false)
        ->assertSee('Your order is confirmed')
        ->assertSee('Continue shopping');

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->assertOk()
        ->assertFormSet(['order_complete_config.headline' => null]);
})->with([
    'nothing stored' => ['{}'],
    'a layout nothing draws and a headline that is not text' => [json_encode(['layout' => 'hero_video', 'headline' => ['not' => 'text'], 'primary_button' => 'nope'])],
]);

it('asks about the order complete page on its own tab beside checkout, in five sections', function () {
    $tab = orderCompleteTabOf(Page::factory()->create());

    /** Layout first, because it decides which words the later sections will ask for; the slots a layout may draw come last. */
    expect(array_values(array_map(
        fn (Component $section): string => (string) $section->getHeading(),
        array_filter($tab->getChildSchema()->getComponents(withHidden: true), fn (Component $component): bool => $component instanceof Section),
    )))->toBe(['Layout', 'Words', 'Buttons', 'Steps', 'Tiles']);

    expect(statePathsInside($tab))->toBe([
        'order_complete_config.layout',
        'order_complete_config.status_line',
        'order_complete_config.headline',
        'order_complete_config.message',
        'order_complete_config.note',
        'order_complete_config.primary_button.label',
        'order_complete_config.primary_button.link',
        'order_complete_config.secondary_button.label',
        'order_complete_config.secondary_button.link',
        'order_complete_config.steps.0.label',
        'order_complete_config.steps.0.timing',
        'order_complete_config.steps.1.label',
        'order_complete_config.steps.1.timing',
        'order_complete_config.steps.2.label',
        'order_complete_config.steps.2.timing',
        'order_complete_config.steps.3.label',
        'order_complete_config.steps.3.timing',
        'order_complete_config.tiles.0.title',
        'order_complete_config.tiles.0.line',
        'order_complete_config.tiles.1.title',
        'order_complete_config.tiles.1.line',
        'order_complete_config.tiles.2.title',
        'order_complete_config.tiles.2.line',
    ]);

    /** The layout describes itself, so what it draws reads without saving to find out. */
    $layout = fieldInside($tab, 'order_complete_config.layout');

    /** @var Text $helperText */
    $helperText = $layout->getChildSchema(Field::BELOW_CONTENT_SCHEMA_KEY)->getComponents()[0];

    expect($layout)->toBeInstanceOf(Select::class)
        ->and($layout->isLive())->toBeTrue()
        ->and($layout->getOptions())->toBe([
            'centered_classic' => 'Centered classic',
            'split' => 'Split',
            'timeline' => 'Timeline',
            'cards_grid' => 'Cards grid',
            'editorial' => 'Editorial',
            'accent_band' => 'Accent band',
        ])
        ->and((string) $helperText->getContent())->toBe(OrderCompleteLayout::CenteredClassic->helperText());
});

it('lets a merchant word the order complete page and serves it in their words', function () {
    $page = sellingPage(Product::factory()->create(['price' => 99000]));

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['order_complete_config' => [
            'headline' => 'Thanks for order {order_number}',
            'message' => "We have your {total}.\nExpect a call.",
            'note' => '',
            'primary_button' => ['label' => 'Back to the shop', 'link' => 'https://shop.example/thanks?order={order_number}'],
            'secondary_button' => ['label' => 'Track {order_number}', 'link' => '/track'],
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    $config = $page->refresh()->order_complete_config;

    expect($config->headline)->toBe('Thanks for order {order_number}')
        ->and($config->primaryButton->link)->toBe('https://shop.example/thanks?order={order_number}')
        ->and($config->secondaryButton->label)->toBe('Track {order_number}');

    $this->postJson(route('checkout.store'), capturePayload($page))->assertCreated();

    $order = Order::sole();

    $html = $this->get(route('checkout.order-complete', $order->order_number))->assertOk()->getContent();

    /** Every customizable string carries the Order's own facts in place of the placeholders, and the one box left empty keeps the built-in words. */
    expect($html)->toContain('Thanks for order '.$order->order_number)
        ->and($html)->toContain('We have your ৳1050.00.')
        ->and($html)->toContain('We will call you shortly to confirm your order.')
        ->and($html)->toContain('href="https://shop.example/thanks?order='.$order->order_number.'"')
        ->and($html)->toContain('>Back to the shop<')
        ->and($html)->toContain('href="/track"')
        ->and($html)->toContain('>Track '.$order->order_number.'<')
        ->and($html)->not->toContain('{order_number}')
        ->and($html)->not->toContain('{total}');
});

it('keeps order complete copy the merchant left alone out of the stored config', function () {
    $page = Page::factory()->create();

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['order_complete_config' => [
            'headline' => '  ',
            'message' => '',
            'note' => null,
            'primary_button' => ['label' => '', 'link' => ' '],
            'secondary_button' => ['label' => '', 'link' => ''],
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    $config = $page->refresh()->order_complete_config;

    /** Blank is the merchant saying nothing, so the page keeps its own words rather than storing a blank. */
    expect($config->headline)->toBeNull()
        ->and($config->message)->toBeNull()
        ->and($config->note)->toBeNull()
        ->and($config->primaryButton->label)->toBeNull()
        ->and($config->primaryButton->link)->toBeNull()
        ->and($config->secondaryButton->label)->toBeNull()
        ->and($config->secondaryButton->link)->toBeNull();
});

it('refuses order complete copy on the box that holds it', function (array $typed, string $box) {
    $page = Page::factory()->create();

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['order_complete_config' => $typed])
        ->call('save')
        ->assertHasFormErrors([$box]);
})->with([
    'a status line too long' => [['layout' => 'split', 'status_line' => str_repeat('s', 61)], 'order_complete_config.status_line'],
    'a status line broken over two lines' => [['layout' => 'split', 'status_line' => "Order\nplaced"], 'order_complete_config.status_line'],
    'a headline too long for one line' => [['headline' => str_repeat('x', 121)], 'order_complete_config.headline'],
    'a headline broken over two lines' => [['headline' => "Thank\nyou"], 'order_complete_config.headline'],
    'a message too long' => [['message' => str_repeat('y', 301)], 'order_complete_config.message'],
    'a note too long' => [['note' => str_repeat('z', 301)], 'order_complete_config.note'],
    'a button label too long' => [['primary_button' => ['label' => str_repeat('b', 61)]], 'order_complete_config.primary_button.label'],
    'a link that is not a web address' => [['primary_button' => ['link' => 'javascript:alert(1)']], 'order_complete_config.primary_button.link'],
    'a second link that is not a web address' => [['secondary_button' => ['label' => 'Track', 'link' => 'track it']], 'order_complete_config.secondary_button.link'],
    'a step label too long' => [['layout' => 'timeline', 'steps' => [1 => ['label' => str_repeat('s', 41)]]], 'order_complete_config.steps.1.label'],
    'a step timing broken over two lines' => [['layout' => 'timeline', 'steps' => [3 => ['timing' => "Two\ndays"]]], 'order_complete_config.steps.3.timing'],
    'a tile title too long' => [['layout' => 'cards_grid', 'tiles' => [0 => ['title' => str_repeat('t', 61)]]], 'order_complete_config.tiles.0.title'],
    'a tile line too long' => [['layout' => 'cards_grid', 'tiles' => [2 => ['line' => str_repeat('l', 161)]]], 'order_complete_config.tiles.2.line'],
]);

it('asks for the steps only on timeline and the tiles only on cards grid, and keeps both whichever is chosen', function (string $stored, bool $stepsAsked, bool $tilesAsked) {
    $page = Page::factory()->create(['order_complete_config' => [
        'layout' => $stored,
        'steps' => [['label' => 'Booked', 'timing' => 'Done']],
        'tiles' => [['title' => 'Booked', 'line' => 'We have it.']],
    ]]);

    $tab = orderCompleteTabOf($page);

    $sections = collect($tab->getChildSchema()->getComponents(withHidden: true))
        ->filter(fn (Component $component): bool => $component instanceof Section)
        ->keyBy(fn (Section $section): string => (string) $section->getHeading());

    /** A layout that draws no steps is not asked for them, and likewise the tiles; an unknown stored layout reads as Centered classic, which draws neither. */
    expect($sections['Steps']->isHidden())->toBe(! $stepsAsked)
        ->and($sections['Tiles']->isHidden())->toBe(! $tilesAsked);

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['order_complete_config' => ['headline' => 'Thanks']])
        ->call('save')
        ->assertHasNoFormErrors();

    /** Both ride along for the day the Page is moved to a layout that draws them. */
    $config = $page->refresh()->order_complete_config;

    expect($config->steps[0]->label)->toBe('Booked')
        ->and($config->steps[0]->timing)->toBe('Done')
        ->and($config->tiles[0]->title)->toBe('Booked')
        ->and($config->tiles[0]->line)->toBe('We have it.');
})->with([
    'centered classic' => ['centered_classic', false, false],
    'timeline' => ['timeline', true, false],
    'cards grid' => ['cards_grid', false, true],
    'editorial' => ['editorial', false, false],
    'a layout nothing draws' => ['hero_video', false, false],
]);

it('lets a merchant word the steps and the tiles, keeps the boxes left alone out of the stored config, and serves the page so', function () {
    $page = sellingPage(Product::factory()->create());

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['order_complete_config' => [
            'layout' => 'timeline',
            'steps' => [
                0 => ['label' => 'Order {order_number} booked', 'timing' => '  '],
                1 => ['label' => '', 'timing' => 'Within the hour'],
                2 => ['label' => '', 'timing' => ''],
                3 => ['label' => 'At your door', 'timing' => 'Two days'],
            ],
            'tiles' => [
                0 => ['title' => 'Booked', 'line' => 'We have order {order_number}.'],
                1 => ['title' => '', 'line' => ''],
                2 => ['title' => ' ', 'line' => 'Cash, please.'],
            ],
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    $config = $page->refresh()->order_complete_config;

    /** Every box round-trips by position, and a blank one is the merchant saying nothing. */
    expect($config->layout)->toBe(OrderCompleteLayout::Timeline)
        ->and($config->steps[0]->label)->toBe('Order {order_number} booked')
        ->and($config->steps[0]->timing)->toBeNull()
        ->and($config->steps[1]->label)->toBeNull()
        ->and($config->steps[1]->timing)->toBe('Within the hour')
        ->and($config->steps[2]->label)->toBeNull()
        ->and($config->steps[2]->timing)->toBeNull()
        ->and($config->steps[3]->label)->toBe('At your door')
        ->and($config->tiles[0]->title)->toBe('Booked')
        ->and($config->tiles[1]->title)->toBeNull()
        ->and($config->tiles[1]->line)->toBeNull()
        ->and($config->tiles[2]->title)->toBeNull()
        ->and($config->tiles[2]->line)->toBe('Cash, please.');

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->assertFormSet([
            'order_complete_config.steps.0.label' => 'Order {order_number} booked',
            'order_complete_config.steps.0.timing' => null,
            'order_complete_config.steps.3.timing' => 'Two days',
            'order_complete_config.tiles.2.line' => 'Cash, please.',
        ]);

    $this->postJson(route('checkout.store'), capturePayload($page))->assertCreated();

    $order = Order::sole();

    $html = $this->get(route('checkout.order-complete', $order->order_number))->assertOk()->getContent();

    expect($html)->toContain('class="gio-order-complete gio-order-complete-timeline"')
        ->and($html)->toContain('Order '.$order->order_number.' booked')
        ->and($html)->toContain('>Just now<')
        ->and($html)->toContain('>Confirmation call<')
        ->and($html)->toContain('>Within the hour<')
        ->and($html)->toContain('>At your door<')
        ->and($html)->not->toContain('Cash, please.');

    /** Moved to Cards grid, the tiles it kept are what the page draws, and the steps wait. */
    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['order_complete_config' => ['layout' => 'cards_grid']])
        ->call('save')
        ->assertHasNoFormErrors();

    $html = $this->get(route('checkout.order-complete', $order->order_number))->assertOk()->getContent();

    expect($html)->toContain('class="gio-order-complete gio-order-complete-cards-grid"')
        ->and($html)->toContain('>Booked<')
        ->and($html)->toContain('We have order '.$order->order_number.'.')
        ->and($html)->toContain('Cash, please.')
        ->and($html)->not->toContain('At your door')
        ->and($page->refresh()->order_complete_config->steps[3]->label)->toBe('At your door');
});

it('asks for the status line only on a layout that draws one, and keeps it whichever is chosen', function (string $stored, bool $asked) {
    $page = Page::factory()->create(['order_complete_config' => ['layout' => $stored, 'status_line' => 'Placed']]);

    $tab = orderCompleteTabOf($page);

    /** A layout that draws no status line is not asked for one; an unknown stored layout reads as Centered classic, which draws none. */
    expect(fieldInside($tab, 'order_complete_config.status_line')?->isHidden())->toBe(! $asked);

    /** And the select describes whichever layout the Page is read as. */
    $layout = fieldInside($tab, 'order_complete_config.layout');

    /** @var Text $helperText */
    $helperText = $layout->getChildSchema(Field::BELOW_CONTENT_SCHEMA_KEY)->getComponents()[0];

    expect((string) $helperText->getContent())->toBe(OrderCompleteLayout::fromStored($stored)->helperText());

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['order_complete_config' => ['headline' => 'Thanks']])
        ->call('save')
        ->assertHasNoFormErrors();

    /** The words ride along for the day the Page is moved to a layout that draws them. */
    expect($page->refresh()->order_complete_config->statusLine)->toBe('Placed');
})->with([
    'centered classic' => ['centered_classic', false],
    'split' => ['split', true],
    'editorial' => ['editorial', true],
    'accent band' => ['accent_band', false],
    'a layout nothing draws' => ['hero_video', false],
]);

it('lets a merchant choose an order complete layout and word its status line, and serves the page so', function () {
    $page = sellingPage(Product::factory()->create());

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['order_complete_config' => ['layout' => 'editorial', 'status_line' => 'Order {order_number} placed']])
        ->call('save')
        ->assertHasNoFormErrors();

    $config = $page->refresh()->order_complete_config;

    expect($config->layout)->toBe(OrderCompleteLayout::Editorial)
        ->and($config->statusLine)->toBe('Order {order_number} placed');

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->assertFormSet(['order_complete_config.layout' => 'editorial', 'order_complete_config.status_line' => 'Order {order_number} placed']);

    $this->postJson(route('checkout.store'), capturePayload($page))->assertCreated();

    $order = Order::sole();

    $html = $this->get(route('checkout.order-complete', $order->order_number))->assertOk()->getContent();

    expect($html)->toContain('class="gio-order-complete gio-order-complete-editorial"')
        ->and($html)->toContain('Order '.$order->order_number.' placed');

    /** Emptied again, the page keeps the words it has always had. */
    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['order_complete_config' => ['status_line' => '  ']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($page->refresh()->order_complete_config->statusLine)->toBeNull();

    $this->get(route('checkout.order-complete', $order->order_number))
        ->assertOk()
        ->assertSee('Order confirmed');
});
