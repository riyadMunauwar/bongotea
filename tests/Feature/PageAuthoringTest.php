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
use App\Support\Checkout\CheckoutLayout;
use App\Support\Checkout\FieldOption;
use App\Support\Checkout\FieldWidth;
use App\Support\Checkout\StyleToken;
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
