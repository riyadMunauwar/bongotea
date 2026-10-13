<?php

use App\Enums\CategoryTheme;
use App\Enums\ProductStatus;
use App\Enums\Strength;
use App\Filament\Resources\Brands\BrandResource;
use App\Filament\Resources\Brands\Pages\ManageBrands;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Tags\Pages\ManageTags;
use App\Filament\Resources\Tags\TagResource;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use App\Support\Pricing\ProductPrices;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * The screens a Merchant builds the catalogue on: Categories wearing a
 * Category Theme, Brands, Tags, and Products with every field the storefront
 * draws. Driven through Livewire against the real admin screens, as
 * `SupplyScreensTest` is.
 */
beforeEach(function (): void {
    $this->actingAs(User::factory()->managingCatalogue()->create());

    $this->category = Category::factory()->create(['name' => 'Premium']);
    $this->brand = Brand::factory()->create(['name' => 'Bongotea']);
});

/*
|--------------------------------------------------------------------------
| Categories and their themes
|--------------------------------------------------------------------------
*/

it('creates a Category with its copy, three why points, a theme and SEO fields', function () {
    Livewire::test(CreateCategory::class)
        ->fillForm([
            'name' => 'Gold',
            'slug' => 'gold',
            'note' => 'মান ও দামের ভারসাম্য',
            'quality_headline' => 'মান ও দামের সেরা ভারসাম্য',
            'about' => 'প্রতিদিনের বিক্রিতে সবচেয়ে বেশি চলে।',
            'why_points' => [['point' => 'দুধ চা ও রং চা দুটোতেই ভালো'], ['point' => 'সারা বছর একই স্বাদ'], ['point' => 'মাঝারি দামে বেশি কাপ']],
            'theme_key' => CategoryTheme::Gold->value,
            'sort_order' => 2,
            'seo_title' => 'Gold tea',
            'seo_description' => 'Balanced tea for every shop.',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $category = Category::query()->where('slug', 'gold')->sole();

    expect($category->theme())->toBe(CategoryTheme::Gold)
        ->and($category->why_points)->toBe(['দুধ চা ও রং চা দুটোতেই ভালো', 'সারা বছর একই স্বাদ', 'মাঝারি দামে বেশি কাপ'])
        ->and($category->quality_headline)->toBe('মান ও দামের সেরা ভারসাম্য')
        ->and($category->sort_order)->toBe(2)
        ->and($category->seo_title)->toBe('Gold tea');
});

it('offers every theme as a swatch and refuses a key that names none', function () {
    Livewire::test(CreateCategory::class)
        ->assertSee('Premium')
        ->assertSee('Monsoon')
        ->fillForm(['name' => 'Odd', 'slug' => 'odd', 'theme_key' => 'tartan'])
        ->call('create')
        ->assertHasFormErrors(['theme_key']);
});

it('makes the Merchant pick a theme rather than defaulting to the fallback', function () {
    Livewire::test(CreateCategory::class)
        ->fillForm(['name' => 'Unthemed', 'slug' => 'unthemed'])
        ->call('create')
        ->assertHasFormErrors(['theme_key' => 'required']);
});

it('keeps every why point the Merchant writes, past three', function () {
    Livewire::test(CreateCategory::class)
        ->fillForm([
            'name' => 'Silver',
            'slug' => 'silver',
            'theme_key' => CategoryTheme::Silver->value,
        ])
        ->set('data.why_points', [['point' => 'one'], ['point' => 'two'], ['point' => 'three'], ['point' => 'four']])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Category::query()->where('slug', 'silver')->sole()->why_points)->toBe(['one', 'two', 'three', 'four']);
});

it('reproduces the mockup tokens for Premium, Gold and Silver exactly', function () {
    expect(CategoryTheme::Premium->tokens())->toBe([
        'text' => '#8A5A1F', 'tint' => '#F6EEDF',
        'panel' => '#1F3A2B', 'panelText' => '#FFFFFF', 'panelSubtext' => '#C5D3C9',
        'accent' => '#D4AE76', 'pillBackground' => '#D4AE76', 'pillText' => '#12231A',
        'tickBackground' => 'rgba(212,174,118,.18)', 'line' => 'rgba(255,255,255,.14)',
    ])->and(CategoryTheme::Gold->tokens())->toBe([
        'text' => '#7A6410', 'tint' => '#F4EFD9',
        'panel' => '#FAF5EA', 'panelText' => '#16261D', 'panelSubtext' => '#5C6660',
        'accent' => '#9A6A2F', 'pillBackground' => '#B07A3B', 'pillText' => '#FFFFFF',
        'tickBackground' => '#FFFFFF', 'line' => '#EADFC6',
    ])->and(CategoryTheme::Silver->tokens())->toBe([
        'text' => '#4D5A55', 'tint' => '#ECEFEE',
        'panel' => '#FFFFFF', 'panelText' => '#16261D', 'panelSubtext' => '#5C6660',
        'accent' => '#5C6A66', 'pillBackground' => '#6B7772', 'pillText' => '#FFFFFF',
        'tickBackground' => '#F3F5F4', 'line' => '#E2E6E2',
    ]);
});

it('gives every theme all ten tokens', function (CategoryTheme $theme) {
    expect(array_keys($theme->tokens()))->toBe([
        'text', 'tint', 'panel', 'panelText', 'panelSubtext', 'accent', 'pillBackground', 'pillText', 'tickBackground', 'line',
    ]);
})->with(CategoryTheme::cases());

it('falls back to the neutral theme when a stored key names a theme that no longer ships', function () {
    $category = Category::factory()->create(['theme_key' => 'retired-theme']);

    expect($category->theme())->toBe(CategoryTheme::Neutral);
});

it('offers deleting a Category only while no Product names it, those taken out included', function () {
    $empty = Category::factory()->create();

    Livewire::test(EditCategory::class, ['record' => $empty->getKey()])
        ->assertActionVisible('delete');

    Product::factory()->trashed()->for($this->category)->create();

    Livewire::test(EditCategory::class, ['record' => $this->category->getKey()])
        ->assertActionHidden('delete');
});

/*
|--------------------------------------------------------------------------
| Brands and Tags
|--------------------------------------------------------------------------
*/

it('creates a Brand with a logo on the public disk, in a dialog over the list', function () {
    Storage::fake(Brand::LOGO_DISK, config('filesystems.disks.'.Brand::LOGO_DISK));

    Livewire::test(ManageBrands::class)
        ->callAction('create', [
            'name' => 'Ispahani',
            'slug' => 'ispahani',
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])
        ->assertHasNoActionErrors();

    $brand = Brand::query()->where('slug', 'ispahani')->sole();

    Storage::disk(Brand::LOGO_DISK)->assertExists($brand->logo);
});

it('renames a Brand in a dialog over the list', function () {
    Livewire::test(ManageBrands::class)
        ->callTableAction('edit', $this->brand, ['name' => 'Bongo Tea'])
        ->assertHasNoTableActionErrors();

    expect($this->brand->refresh()->name)->toBe('Bongo Tea');
});

it('offers deleting a Brand only while no Product carries it', function () {
    $unused = Brand::factory()->create();

    Product::factory()->for($this->brand)->create();

    Livewire::test(ManageBrands::class)
        ->assertTableActionHidden('delete', $this->brand)
        ->assertTableActionVisible('delete', $unused);
});

it('creates a Tag with a sort order, in a dialog over the list', function () {
    Livewire::test(ManageTags::class)
        ->callAction('create', ['name' => 'দুধ চা', 'slug' => 'milk', 'sort_order' => 1])
        ->assertHasNoActionErrors();

    expect(Tag::query()->where('slug', 'milk')->sole()->sort_order)->toBe(1);
});

it('refuses a slug already claimed by another Tag', function () {
    Tag::factory()->create(['slug' => 'milk']);

    Livewire::test(ManageTags::class)
        ->callAction('create', ['name' => 'Milk again', 'slug' => 'milk'])
        ->assertHasActionErrors(['slug' => 'unique']);
});

it('deletes a Tag from the list', function () {
    $milk = Tag::factory()->create();

    Livewire::test(ManageTags::class)
        ->callTableAction('delete', $milk);

    expect(Tag::query()->find($milk->getKey()))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Products
|--------------------------------------------------------------------------
*/

it('creates a Product with every characteristic, its Tags and SEO fields', function () {
    $milk = Tag::factory()->create();
    $hotel = Tag::factory()->create();

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'বঙ্গোটি প্রিমিয়াম',
            'slug' => 'premium',
            'category_id' => $this->category->getKey(),
            'brand_id' => $this->brand->getKey(),
            'tags' => [$milk->getKey(), $hotel->getKey()],
            'short_description' => 'দুধ চায়ের জন্য ঘন ও সুগন্ধি চা।',
            'description' => '<p>বাগানের কচি পাতা।</p>',
            'strength' => Strength::FourMinus->value,
            'tea_type' => 'সিটিসি',
            'grade' => 'বিওপি',
            'particle_size' => 'মাঝারি',
            'liquor_colour' => 'গাঢ় লালচে বাদামি',
            'liquor_body' => 'ঘন লিকার',
            'aroma' => 'তীব্র সুগন্ধ',
            'infusion' => '৩–৪ মিনিট',
            'cups_per_kilogram' => 'প্রায় ৪০০–৪৫০ কাপ',
            'package_weight' => '১ কেজি প্যাকেট',
            'best_for' => ['দুধ চা', 'মালাই চা'],
            'not_best_for' => 'হালকা রং চা পছন্দ করলে গোল্ড বেছে নিন।',
            'badge' => 'সবচেয়ে জনপ্রিয়',
            'search_keywords' => ['premium', 'kodom'],
            'status' => ProductStatus::Published->value,
            'is_available' => false,
            'seo_title' => 'Premium tea',
        ])
        ->set('data.mrp', '650')
        ->set('data.tiers', [['min' => '1', 'max' => '', 'price' => '620']])
        ->set('data.custom_characteristics', [['label' => 'বাগান', 'value' => 'সিলেট'], ['label' => 'সংগ্রহ', 'value' => 'দ্বিতীয় ফ্লাশ']])
        ->set('data.brewing_steps', [['step' => 'পানি ফুটিয়ে নিন।'], ['step' => 'প্রতি কাপে ২ গ্রাম দিন।'], ['step' => '৩ মিনিট রাখুন।']])
        ->set('data.business_tips', [['tip' => 'এক'], ['tip' => 'দুই'], ['tip' => 'তিন'], ['tip' => 'চার']])
        ->set('data.storage_tips', [['heading' => 'শুষ্ক স্থানে রাখুন', 'explanation' => 'ঠান্ডা স্থানে রাখুন।']])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::query()->where('slug', 'premium')->sole();

    expect($product->category->is($this->category))->toBeTrue()
        ->and($product->brand->is($this->brand))->toBeTrue()
        ->and($product->tags->modelKeys())->toEqualCanonicalizing([$milk->getKey(), $hotel->getKey()])
        ->and($product->strength)->toBe(Strength::FourMinus)
        ->and($product->grade)->toBe('বিওপি')
        ->and($product->particle_size)->toBe('মাঝারি')
        ->and($product->liquor_body)->toBe('ঘন লিকার')
        ->and($product->package_weight)->toBe('১ কেজি প্যাকেট')
        ->and($product->custom_characteristics)->toBe([['label' => 'বাগান', 'value' => 'সিলেট'], ['label' => 'সংগ্রহ', 'value' => 'দ্বিতীয় ফ্লাশ']])
        ->and($product->best_for)->toBe(['দুধ চা', 'মালাই চা'])
        ->and($product->brewing_steps)->toBe(['পানি ফুটিয়ে নিন।', 'প্রতি কাপে ২ গ্রাম দিন।', '৩ মিনিট রাখুন।'])
        ->and($product->business_tips)->toBe(['এক', 'দুই', 'তিন', 'চার'])
        ->and($product->storage_tips)->toBe([['heading' => 'শুষ্ক স্থানে রাখুন', 'explanation' => 'ঠান্ডা স্থানে রাখুন।']])
        ->and($product->search_keywords)->toBe('premium,kodom')
        ->and($product->is_available)->toBeFalse()
        ->and($product->status)->toBe(ProductStatus::Published)
        ->and($product->first_published_at)->not->toBeNull()
        ->and($product->seo_title)->toBe('Premium tea');
});

it('publishes a Product with nothing but its name, slug, Category, Brand, MRP and one Tier', function () {
    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'Bare',
            'slug' => 'bare',
            'category_id' => $this->category->getKey(),
            'brand_id' => $this->brand->getKey(),
            'status' => ProductStatus::Published->value,
            'mrp' => '500',
        ])
        ->set('data.tiers', [['min' => '1', 'max' => '', 'price' => '500']])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Product::query()->where('slug', 'bare')->sole()->strength)->toBeNull();
});

it('starts a new Product with the four common Storage tips, which the Merchant may change', function () {
    Livewire::test(CreateProduct::class)
        ->assertSet('data.storage_tips', fn (array $tips): bool => array_values($tips) === Product::commonStorageTips());
});

/*
|--------------------------------------------------------------------------
| The Product's own Tiers
|--------------------------------------------------------------------------
*/

it('stores the MRP and each Tier with its price in minor units', function () {
    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'Premium',
            'slug' => 'premium',
            'category_id' => $this->category->getKey(),
            'brand_id' => $this->brand->getKey(),
            'mrp' => '700',
        ])
        ->set('data.tiers', [
            ['min' => '1', 'max' => '3', 'price' => '620'],
            ['min' => '4', 'max' => '20', 'price' => '589'],
            ['min' => '21', 'max' => '', 'price' => '589'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::query()->where('slug', 'premium')->sole();

    expect($product->tiers)->toBe([
        ['min' => 1, 'max' => 3, 'price' => 62000],
        ['min' => 4, 'max' => 20, 'price' => 58900],
        ['min' => 21, 'max' => null, 'price' => 58900],
    ])
        ->and($product->mrp)->toBe(70000)
        ->and(ProductPrices::of($product)->mrp)->toBe(70000)
        ->and(ProductPrices::of($product)->tierFor(7)->price)->toBe(58900);
});

it('opens a Product\'s MRP in whole taka again', function () {
    $product = Product::factory()->create(['mrp' => 70000, 'price' => 65000]);

    expect(Livewire::test(EditProduct::class, ['record' => $product->getKey()])->get('data.mrp'))->toEqual(700);
});

it('requires an MRP on every Product', function () {
    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'No MRP',
            'slug' => 'no-mrp',
            'category_id' => $this->category->getKey(),
            'brand_id' => $this->brand->getKey(),
        ])
        ->set('data.tiers', [['min' => '1', 'max' => '', 'price' => '500']])
        ->call('create')
        ->assertHasFormErrors(['mrp' => 'required']);
});

it('refuses an MRP below a Tier Price, and takes one equal to the first Tier\'s', function (string $mrp, bool $refused) {
    $component = Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'Above MRP',
            'slug' => 'above-mrp',
            'category_id' => $this->category->getKey(),
            'brand_id' => $this->brand->getKey(),
            'mrp' => $mrp,
        ])
        ->set('data.tiers', [
            ['min' => '1', 'max' => '5', 'price' => '500'],
            ['min' => '6', 'max' => '', 'price' => '480'],
        ])
        ->call('create');

    $refused ? $component->assertHasFormErrors(['mrp']) : $component->assertHasNoFormErrors();

    expect(Product::query()->where('slug', 'above-mrp')->exists())->toBe(! $refused);
})->with([
    'below the first Tier' => ['499', true],
    'at the first Tier' => ['500', false],
]);

it('refuses a Product saved from anywhere without an MRP, or with a Tier Price above it', function (array $attributes) {
    Product::factory()->create($attributes);
})->with([
    'no MRP' => [['mrp' => null]],
    'an MRP of nothing' => [['mrp' => 0, 'price' => 0]],
    'a Tier above it' => [['mrp' => 49900, 'tiers' => [['min' => 1, 'max' => 5, 'price' => 50000], ['min' => 6, 'max' => null, 'price' => 48000]]]],
])->throws(LogicException::class);

it('opens a new Product on one open-ended Tier, which is a flat price', function () {
    $tiers = Livewire::test(CreateProduct::class)->get('data.tiers');

    expect(array_values($tiers))->toBe([['min' => 1, 'max' => null, 'price' => null]]);
});

it('opens a Product\'s Tiers in whole taka again', function () {
    $product = Product::factory()->create(['tiers' => [
        ['min' => 1, 'max' => 5, 'price' => 46000],
        ['min' => 6, 'max' => null, 'price' => 44000],
    ]]);

    $tiers = Livewire::test(EditProduct::class, ['record' => $product->getKey()])->get('data.tiers');

    expect(array_values($tiers))->toEqual([
        ['min' => 1, 'max' => 5, 'price' => 460],
        ['min' => 6, 'max' => null, 'price' => 440],
    ]);
});

it('opens a Tier Price that is not whole taka with its decimals, so a save asks for it rather than dropping them', function () {
    $product = Product::factory()->create(['price' => 99050]);

    $component = Livewire::test(EditProduct::class, ['record' => $product->getKey()]);

    expect(array_values($component->get('data.tiers'))[0]['price'])->toEqual(990.5);

    $component->call('save')->assertHasFormErrors(['tiers']);

    expect($product->fresh()->price)->toBe(99050);
});

it('refuses Tiers with a gap, a closed last range, or a heavier Tier dearer than the one before', function (array $tiers) {
    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'Refused',
            'slug' => 'refused',
            'category_id' => $this->category->getKey(),
            'brand_id' => $this->brand->getKey(),
            'mrp' => '600',
        ])
        ->set('data.tiers', $tiers)
        ->call('create')
        ->assertHasFormErrors(['tiers']);

    expect(Product::query()->where('slug', 'refused')->exists())->toBeFalse();
})->with([
    'a gap' => [[['min' => '1', 'max' => '5', 'price' => '500'], ['min' => '7', 'max' => '', 'price' => '480']]],
    'a closed last range' => [[['min' => '1', 'max' => '5', 'price' => '500']]],
    'a dearer heavier Tier' => [[['min' => '1', 'max' => '5', 'price' => '500'], ['min' => '6', 'max' => '', 'price' => '520']]],
]);

it('refuses a Product saved from anywhere with Tiers that do not cover every kilogram once or that rise', function (array $tiers) {
    Product::factory()->create(['tiers' => $tiers]);
})->with([
    'a gap' => [[['min' => 1, 'max' => 5, 'price' => 500], ['min' => 7, 'max' => null, 'price' => 480]]],
    'a rise' => [[['min' => 1, 'max' => 5, 'price' => 500], ['min' => 6, 'max' => null, 'price' => 520]]],
])->throws(LogicException::class);

it('keeps price the first Tier\'s, and refuses a price set over Tiers it would contradict', function () {
    $flat = Product::factory()->create(['price' => 50000, 'mrp' => 60000]);

    expect($flat->tiers)->toBe([['min' => 1, 'max' => null, 'price' => 50000]]);

    $flat->update(['price' => 52000]);

    expect($flat->fresh()->tiers)->toBe([['min' => 1, 'max' => null, 'price' => 52000]]);

    $laddered = Product::factory()->create(['tiers' => [
        ['min' => 1, 'max' => 5, 'price' => 50000],
        ['min' => 6, 'max' => null, 'price' => 48000],
    ]]);

    expect($laddered->price)->toBe(50000)
        ->and(fn () => $laddered->update(['price' => 1]))->toThrow(LogicException::class);
});

it('says on the Product form what shape a photo draws well at', function () {
    $this->get(ProductResource::getUrl('create'))
        ->assertOk()
        ->assertSee('1200 × 1200 px');
});

it('lets the Category, Brand and Tags of a Product be searched for, since each list grows', function () {
    $form = Livewire::test(CreateProduct::class)->instance()->getSchema('form');

    foreach (['category_id', 'brand_id', 'tags'] as $name) {
        expect($form->getComponent(fn ($component): bool => $component instanceof Select && $component->getName() === $name)->isSearchable())
            ->toBeTrue();
    }
});

it('requires a Category and a Brand on every Product', function () {
    Livewire::test(CreateProduct::class)
        ->fillForm(['name' => 'Orphan', 'slug' => 'orphan', 'category_id' => null, 'brand_id' => null])
        ->call('create')
        ->assertHasFormErrors(['category_id' => 'required', 'brand_id' => 'required']);
});

it('refuses a strength that is not one of the grades, and a custom Characteristic or Storage tip missing half of itself', function () {
    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'Too much',
            'slug' => 'too-much',
            'category_id' => $this->category->getKey(),
            'brand_id' => $this->brand->getKey(),
            'strength' => 11,
        ])
        ->set('data.custom_characteristics', [['label' => 'বাগান', 'value' => ''], ['label' => '', 'value' => 'সিলেট']])
        ->set('data.storage_tips', [['heading' => '', 'explanation' => 'ঠান্ডা স্থানে রাখুন।'], ['heading' => 'শুষ্ক স্থানে রাখুন', 'explanation' => '']])
        ->call('create')
        ->assertHasFormErrors(['strength', 'custom_characteristics.0.value', 'custom_characteristics.1.label', 'storage_tips.0.heading', 'storage_tips.1.explanation']);
});

it('keeps the slug of a Product taken out of the catalogue claimed', function () {
    Product::factory()->trashed()->create(['slug' => 'old-tea']);

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'New tea',
            'slug' => 'old-tea',
            'category_id' => $this->category->getKey(),
            'brand_id' => $this->brand->getKey(),
        ])
        ->call('create')
        ->assertHasFormErrors(['slug' => 'unique']);
});

it('stores the gallery in order, the first image being the cover', function () {
    Storage::fake(Product::IMAGE_DISK, config('filesystems.disks.'.Product::IMAGE_DISK));

    $product = Product::factory()->create();
    $product->addMedia(UploadedFile::fake()->image('front.jpg'))->toMediaCollection(Product::GALLERY_COLLECTION);
    $product->addMedia(UploadedFile::fake()->image('back.jpg'))->toMediaCollection(Product::GALLERY_COLLECTION);

    expect($product->getMedia(Product::GALLERY_COLLECTION))->toHaveCount(2)
        ->and($product->coverUrl())->toContain('front.jpg');
});

/*
|--------------------------------------------------------------------------
| The slug is fixed once the Product has gone live
|--------------------------------------------------------------------------
*/

it('lets the slug of a never-published draft be corrected', function () {
    $draft = Product::factory()->draft()->create(['slug' => 'typo']);

    Livewire::test(EditProduct::class, ['record' => $draft->getKey()])
        ->assertFormFieldEnabled('slug')
        ->fillForm(['slug' => 'fixed'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($draft->refresh()->slug)->toBe('fixed');
});

it('locks the slug once the Product is published, while it can still be renamed', function () {
    $draft = Product::factory()->draft()->create(['slug' => 'premium', 'name' => 'Premium']);

    Livewire::test(EditProduct::class, ['record' => $draft->getKey()])
        ->fillForm(['status' => ProductStatus::Published->value])
        ->call('save')
        ->assertHasNoFormErrors();

    Livewire::test(EditProduct::class, ['record' => $draft->getKey()])
        ->assertFormFieldDisabled('slug')
        ->fillForm(['name' => 'Premium Gold Leaf', 'slug' => 'moved'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($draft->refresh())
        ->slug->toBe('premium')
        ->name->toBe('Premium Gold Leaf');
});

it('keeps the slug locked after a published Product is pulled back to draft', function () {
    $product = Product::factory()->create();

    $product->update(['status' => ProductStatus::Draft]);

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->assertFormFieldDisabled('slug');
});

it('refuses a slug change on a Product that has gone live from anywhere, not only the form', function () {
    $product = Product::factory()->create(['slug' => 'premium']);

    expect(fn () => $product->update(['slug' => 'moved']))->toThrow(LogicException::class);

    expect($product->refresh()->slug)->toBe('premium');
});

/*
|--------------------------------------------------------------------------
| Taken out of the catalogue
|--------------------------------------------------------------------------
*/

it('takes a Product out of the catalogue with a soft delete and puts it back', function () {
    $product = Product::factory()->create();

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->callAction('delete');

    expect($product->refresh()->trashed())->toBeTrue();

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->callAction('restore');

    expect($product->refresh()->trashed())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| One Permission opens the whole catalogue
|--------------------------------------------------------------------------
*/

it('opens Categories, Brands and Tags under manage catalogue and draws them under Products', function () {
    $this->get(CategoryResource::getUrl('index'))->assertOk();
    $this->get(BrandResource::getUrl('index'))->assertOk();
    $this->get(TagResource::getUrl('index'))->assertOk();

    $products = collect(Filament::getCurrentOrDefaultPanel()->getNavigation())
        ->flatMap(fn (NavigationGroup $group): array|Arrayable => $group->getItems())
        ->first(fn (NavigationItem $item): bool => $item->getLabel() === 'Products');

    expect(collect($products->getChildItems())->map(fn (NavigationItem $item): string => $item->getLabel())->all())
        ->toBe(['Categories', 'Brands', 'Tags']);
});

it('refuses Categories, Brands and Tags to a panel User without manage catalogue', function () {
    $this->actingAs(User::factory()->managingSettings()->create());

    $this->get(CategoryResource::getUrl('index'))->assertForbidden();
    $this->get(CategoryResource::getUrl('edit', ['record' => $this->category]))->assertForbidden();
    $this->get(BrandResource::getUrl('index'))->assertForbidden();
    $this->get(TagResource::getUrl('index'))->assertForbidden();
});
