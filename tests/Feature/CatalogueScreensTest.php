<?php

use App\Enums\CategoryTheme;
use App\Enums\DiscountType;
use App\Enums\ProductStatus;
use App\Filament\Resources\Brands\BrandResource;
use App\Filament\Resources\Brands\Pages\CreateBrand;
use App\Filament\Resources\Brands\Pages\EditBrand;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Tags\Pages\CreateTag;
use App\Filament\Resources\Tags\TagResource;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use App\Support\Pricing\PriceBook;
use App\Support\Pricing\ProductPrices;
use App\Support\Pricing\TierPrice;
use Filament\Facades\Filament;
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

it('refuses a fourth why point', function () {
    Livewire::test(CreateCategory::class)
        ->fillForm([
            'name' => 'Silver',
            'slug' => 'silver',
            'why_points' => [['point' => 'one'], ['point' => 'two'], ['point' => 'three'], ['point' => 'four']],
            'theme_key' => CategoryTheme::Silver->value,
        ])
        ->call('create')
        ->assertHasFormErrors(['why_points']);
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

it('creates a Brand with a logo on the public disk', function () {
    Storage::fake(Brand::LOGO_DISK, config('filesystems.disks.'.Brand::LOGO_DISK));

    Livewire::test(CreateBrand::class)
        ->fillForm([
            'name' => 'Ispahani',
            'slug' => 'ispahani',
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $brand = Brand::query()->where('slug', 'ispahani')->sole();

    Storage::disk(Brand::LOGO_DISK)->assertExists($brand->logo);
});

it('offers deleting a Brand only while no Product carries it', function () {
    Product::factory()->for($this->brand)->create();

    Livewire::test(EditBrand::class, ['record' => $this->brand->getKey()])
        ->assertActionHidden('delete');
});

it('creates a Tag with a sort order', function () {
    Livewire::test(CreateTag::class)
        ->fillForm(['name' => 'দুধ চা', 'slug' => 'milk', 'sort_order' => 1])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Tag::query()->where('slug', 'milk')->sole()->sort_order)->toBe(1);
});

it('refuses a slug already claimed by another Tag', function () {
    Tag::factory()->create(['slug' => 'milk']);

    Livewire::test(CreateTag::class)
        ->fillForm(['name' => 'Milk again', 'slug' => 'milk'])
        ->call('create')
        ->assertHasFormErrors(['slug' => 'unique']);
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
            'price' => '620',
            'strength' => 4,
            'liquor_colour' => 'গাঢ় লালচে বাদামি',
            'aroma' => 'তীব্র সুগন্ধ',
            'cups_per_kilogram' => 'প্রায় ৪০০–৪৫০ কাপ',
            'best_for' => ['দুধ চা', 'মালাই চা'],
            'not_best_for' => 'হালকা রং চা পছন্দ করলে গোল্ড বেছে নিন।',
            'brewing_guide' => 'প্রতি কাপে ২–২.৫ গ্রাম।',
            'business_tips' => [['tip' => 'এক'], ['tip' => 'দুই'], ['tip' => 'তিন']],
            'badge' => 'সবচেয়ে জনপ্রিয়',
            'search_keywords' => ['premium', 'kodom'],
            'status' => ProductStatus::Published->value,
            'is_available' => false,
            'seo_title' => 'Premium tea',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::query()->where('slug', 'premium')->sole();

    expect($product->category->is($this->category))->toBeTrue()
        ->and($product->brand->is($this->brand))->toBeTrue()
        ->and($product->tags->modelKeys())->toEqualCanonicalizing([$milk->getKey(), $hotel->getKey()])
        ->and($product->strength)->toBe(4)
        ->and($product->best_for)->toBe(['দুধ চা', 'মালাই চা'])
        ->and($product->business_tips)->toBe(['এক', 'দুই', 'তিন'])
        ->and($product->search_keywords)->toBe('premium,kodom')
        ->and($product->is_available)->toBeFalse()
        ->and($product->status)->toBe(ProductStatus::Published)
        ->and($product->first_published_at)->not->toBeNull()
        ->and($product->seo_title)->toBe('Premium tea');
});

it('publishes a Product with nothing but its name, slug, Category, Brand and price', function () {
    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'Bare',
            'slug' => 'bare',
            'category_id' => $this->category->getKey(),
            'brand_id' => $this->brand->getKey(),
            'price' => '500',
            'status' => ProductStatus::Published->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Product::query()->where('slug', 'bare')->sole()->strength)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Regular Price and Tier Discounts
|--------------------------------------------------------------------------
*/

it('stores a Regular Price in minor units and a percentage per Tier, leaving a blank Tier to charge the one below', function () {
    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'Premium',
            'slug' => 'premium',
            'category_id' => $this->category->getKey(),
            'brand_id' => $this->brand->getKey(),
            'price' => '620',
            'discount_type' => DiscountType::Percent->value,
            'tier_discounts' => ['tier-2' => '5', 'tier-3' => '10', 'tier-4' => '', 'tier-5' => '16', 'tier-6' => '20'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::query()->where('slug', 'premium')->sole();

    expect($product->price)->toBe(62000)
        ->and($product->discount_type)->toBe(DiscountType::Percent)
        ->and($product->tier_discounts)->toBe(['tier-2' => 5, 'tier-3' => 10, 'tier-4' => null, 'tier-5' => 16, 'tier-6' => 20])
        ->and(array_map(fn (TierPrice $step): int => $step->price, PriceBook::current()->ladder(ProductPrices::of($product))))
        ->toBe([62000, 58900, 55800, 55800, 52100, 49600]);
});

it('takes a fixed discount in whole taka, stores it in minor units and opens it in taka again', function () {
    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'Strong',
            'slug' => 'strong',
            'category_id' => $this->category->getKey(),
            'brand_id' => $this->brand->getKey(),
            'price' => '460',
            'discount_type' => DiscountType::Fixed->value,
            'tier_discounts' => ['tier-2' => '20', 'tier-3' => '40'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::query()->where('slug', 'strong')->sole();

    expect($product->tier_discounts)->toMatchArray(['tier-2' => 2000, 'tier-3' => 4000]);

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->assertSchemaStateSet(['price' => 460, 'tier_discounts.tier-2' => 20, 'tier_discounts.tier-3' => 40]);
});

it('opens a Regular Price that is not whole taka with its decimals, so a save asks for it rather than dropping them', function () {
    $product = Product::factory()->create(['price' => 99050]);

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->assertSchemaStateSet(['price' => '990.50'])
        ->call('save')
        ->assertHasFormErrors(['price']);

    expect($product->fresh()->price)->toBe(99050);
});

it('draws the Tier Prices the typed discounts come to', function () {
    Livewire::test(CreateProduct::class)
        ->fillForm([
            'price' => '650',
            'discount_type' => DiscountType::Percent->value,
            'tier_discounts' => ['tier-2' => '5', 'tier-3' => '8'],
        ])
        ->assertSee('1–5 kg: ৳650.00')
        ->assertSee('6–10 kg: ৳618.00')
        ->assertSee('11–15 kg: ৳598.00')
        ->assertSee('51+ kg: ৳598.00');
});

it('refuses a percentage over 100, a fixed discount above the Regular Price, and part of a taka', function () {
    $product = [
        'name' => 'Refused',
        'slug' => 'refused',
        'category_id' => $this->category->getKey(),
        'brand_id' => $this->brand->getKey(),
    ];

    Livewire::test(CreateProduct::class)
        ->fillForm([...$product, 'price' => '620.50', 'discount_type' => DiscountType::Percent->value, 'tier_discounts' => ['tier-2' => '101']])
        ->call('create')
        ->assertHasFormErrors(['price', 'tier_discounts.tier-2']);

    Livewire::test(CreateProduct::class)
        ->fillForm([...$product, 'price' => '460', 'discount_type' => DiscountType::Fixed->value, 'tier_discounts' => ['tier-2' => '461']])
        ->call('create')
        ->assertHasFormErrors(['tier_discounts.tier-2']);

    expect(Product::query()->where('slug', 'refused')->exists())->toBeFalse();
});

it('requires a Category and a Brand on every Product', function () {
    Livewire::test(CreateProduct::class)
        ->fillForm(['name' => 'Orphan', 'slug' => 'orphan', 'price' => '500', 'category_id' => null, 'brand_id' => null])
        ->call('create')
        ->assertHasFormErrors(['category_id' => 'required', 'brand_id' => 'required']);
});

it('refuses a strength outside 1 to 5 and a fourth business tip', function () {
    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'Too much',
            'slug' => 'too-much',
            'category_id' => $this->category->getKey(),
            'brand_id' => $this->brand->getKey(),
            'price' => '500',
            'strength' => 6,
            'business_tips' => [['tip' => 'one'], ['tip' => 'two'], ['tip' => 'three'], ['tip' => 'four']],
        ])
        ->call('create')
        ->assertHasFormErrors(['strength', 'business_tips']);
});

it('keeps the slug of a Product taken out of the catalogue claimed', function () {
    Product::factory()->trashed()->create(['slug' => 'old-tea']);

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'New tea',
            'slug' => 'old-tea',
            'category_id' => $this->category->getKey(),
            'brand_id' => $this->brand->getKey(),
            'price' => '500',
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
    $this->get(BrandResource::getUrl('create'))->assertForbidden();
    $this->get(TagResource::getUrl('index'))->assertForbidden();
});
