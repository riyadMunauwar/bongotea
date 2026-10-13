<?php

use App\Enums\CategoryTheme;
use App\Enums\Strength;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A Product at its own address, as a buyer reaches it: everything the
 * Merchant filled in and nothing they left blank, its own Tier ladder, and
 * other teas to go on to. A draft is nobody's to see; one taken out of the
 * catalogue still answers, saying it is no longer sold.
 */
it('shows a Product with its Brand, Category, characteristics and its own Tier ladder', function () {
    $category = Category::factory()->create(['name' => 'প্রিমিয়াম', 'slug' => 'premium', 'theme_key' => CategoryTheme::Premium->value]);
    $brand = Brand::factory()->create(['name' => 'Bongotea']);

    $product = Product::factory()->for($category)->for($brand)->create([
        'name' => 'প্রিমিয়াম চা',
        'slug' => 'premium-tea',
        'short_description' => 'গাঢ় লিকার, তীব্র ঘ্রাণ।',
        'description' => '<p>বাগানের <strong>কচি</strong> পাতা।</p><script>alert(1)</script>',
        'badge' => 'সবচেয়ে জনপ্রিয়',
        'mrp' => 65000,
        'tiers' => [
            ['min' => 1, 'max' => 5, 'price' => 62000],
            ['min' => 6, 'max' => null, 'price' => 55800],
        ],
        'strength' => Strength::FourPlus,
        'tea_type' => 'সিটিসি',
        'grade' => 'বিওপি',
        'particle_size' => 'মাঝারি',
        'liquor_colour' => 'গাঢ় লাল',
        'liquor_body' => 'ঘন লিকার',
        'aroma' => 'তীব্র',
        'infusion' => '৩–৪ মিনিট',
        'cups_per_kilogram' => '১ কেজিতে প্রায় ৪৫০ কাপ',
        'package_weight' => '১ কেজি প্যাকেট',
        'custom_characteristics' => [
            ['label' => 'বাগান', 'value' => 'সিলেট'],
            ['label' => 'মৌসুম', 'value' => '  '],
            ['label' => 'সংগ্রহ', 'value' => 'দ্বিতীয় ফ্লাশ'],
        ],
        'best_for' => ['হোটেল', 'রেস্টুরেন্ট'],
        'not_best_for' => 'হালকা চা যারা পছন্দ করেন',
        'brewing_steps' => ['পানি ফুটিয়ে নিন।', '', 'প্রতি কাপে ২ গ্রাম দিন।'],
        'business_tips' => ['এয়ারটাইট কৌটায় রাখুন।'],
        'storage_tips' => [
            ['heading' => 'শুষ্ক স্থানে রাখুন', 'explanation' => 'ঠান্ডা ও আর্দ্রতামুক্ত স্থানে রাখুন।'],
            ['heading' => '  ', 'explanation' => 'শিরোনাম নেই।'],
            ['heading' => 'রোদ থেকে দূরে রাখুন', 'explanation' => 'সূর্যালোক ও অতিরিক্ত তাপ এড়িয়ে চলুন।'],
        ],
        'search_keywords' => 'primium',
    ]);

    $this->get('/tea/premium-tea')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('product')
            ->where('product.id', $product->id)
            ->where('product.name', 'প্রিমিয়াম চা')
            ->where('product.href', '/tea/premium-tea')
            ->where('product.shortDescription', 'গাঢ় লিকার, তীব্র ঘ্রাণ।')
            ->where('product.descriptionHtml', fn (string $html): bool => str_contains($html, '<strong>কচি</strong>') && ! str_contains($html, '<script'))
            ->where('product.badge', 'সবচেয়ে জনপ্রিয়')
            ->where('product.isAvailable', true)
            ->where('product.brand', ['id' => $brand->id, 'name' => 'Bongotea'])
            ->where('product.category.name', 'প্রিমিয়াম')
            ->where('product.category.href', '/category/premium')
            ->where('product.category.theme', CategoryTheme::Premium->tokens())
            ->where('product.mrp', 65000)
            ->where('product.tiers', [
                ['min' => 1, 'max' => 5, 'price' => 62000],
                ['min' => 6, 'max' => null, 'price' => 55800],
            ])
            ->where('product.strength', ['grade' => '৪+', 'bars' => 4])
            ->where('product.teaType', 'সিটিসি')
            ->where('product.grade', 'বিওপি')
            ->where('product.particleSize', 'মাঝারি')
            ->where('product.liquorColour', 'গাঢ় লাল')
            ->where('product.liquorBody', 'ঘন লিকার')
            ->where('product.aroma', 'তীব্র')
            ->where('product.infusion', '৩–৪ মিনিট')
            ->where('product.cupsPerKilogram', '১ কেজিতে প্রায় ৪৫০ কাপ')
            ->where('product.packageWeight', '১ কেজি প্যাকেট')
            ->where('product.customCharacteristics', [
                ['label' => 'বাগান', 'value' => 'সিলেট'],
                ['label' => 'সংগ্রহ', 'value' => 'দ্বিতীয় ফ্লাশ'],
            ])
            ->where('product.bestFor', ['হোটেল', 'রেস্টুরেন্ট'])
            ->where('product.notBestFor', 'হালকা চা যারা পছন্দ করেন')
            ->where('product.brewingSteps', ['পানি ফুটিয়ে নিন।', 'প্রতি কাপে ২ গ্রাম দিন।'])
            ->where('product.businessTips', ['এয়ারটাইট কৌটায় রাখুন।'])
            ->where('product.storageTips', [
                ['heading' => 'শুষ্ক স্থানে রাখুন', 'explanation' => 'ঠান্ডা ও আর্দ্রতামুক্ত স্থানে রাখুন।'],
                ['heading' => 'রোদ থেকে দূরে রাখুন', 'explanation' => 'সূর্যালোক ও অতিরিক্ত তাপ এড়িয়ে চলুন।'],
            ])
            ->where('product.gallery', [])
            ->where('minorUnitsPerTaka', 100)
            ->where('seo.title', 'প্রিমিয়াম চা — Bongotea')
            ->where('seo.description', 'গাঢ় লিকার, তীব্র ঘ্রাণ।')
            ->where('seo.canonical', url('/tea/premium-tea'))
            ->where('seo.type', 'product')
        );
});

it('sends every characteristic left blank as empty, so none is drawn', function () {
    Product::factory()->create([
        'slug' => 'bare-tea',
        'short_description' => null,
        'badge' => '  ',
        'strength' => null,
        'best_for' => [],
        'custom_characteristics' => null,
        'brewing_steps' => null,
        'business_tips' => null,
        'storage_tips' => null,
    ]);

    $this->get('/tea/bare-tea')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('product.shortDescription', null)
            ->where('product.descriptionHtml', null)
            ->where('product.badge', null)
            ->where('product.strength', null)
            ->where('product.teaType', null)
            ->where('product.grade', null)
            ->where('product.particleSize', null)
            ->where('product.liquorColour', null)
            ->where('product.liquorBody', null)
            ->where('product.aroma', null)
            ->where('product.infusion', null)
            ->where('product.cupsPerKilogram', null)
            ->where('product.packageWeight', null)
            ->where('product.customCharacteristics', [])
            ->where('product.bestFor', [])
            ->where('product.notBestFor', null)
            ->where('product.brewingSteps', [])
            ->where('product.businessTips', [])
            ->where('product.storageTips', [])
        );
});

it('shows the gallery in order, the cover first and as the share image', function () {
    Storage::fake(Product::IMAGE_DISK);

    $product = Product::factory()->create(['slug' => 'pictured-tea']);
    $product->addMedia(UploadedFile::fake()->image('cover.jpg'))->toMediaCollection(Product::GALLERY_COLLECTION);
    $product->addMedia(UploadedFile::fake()->image('leaf.jpg'))->toMediaCollection(Product::GALLERY_COLLECTION);

    $this->get('/tea/pictured-tea')
        ->assertInertia(fn (Assert $page) => $page
            ->has('product.gallery', 2)
            ->where('product.gallery.0', fn (string $url): bool => str_ends_with($url, 'cover.jpg'))
            ->where('product.imageUrl', fn (string $url): bool => str_ends_with($url, 'cover.jpg'))
            ->where('seo.image', fn (string $url): bool => str_ends_with($url, 'cover.jpg'))
        );
});

it('suggests up to three other published teas, its own Category first', function () {
    $premium = Category::factory()->create();
    $silver = Category::factory()->create();

    $product = Product::factory()->for($premium)->create(['slug' => 'this-tea']);
    $sibling = Product::factory()->for($premium)->create();
    $others = Product::factory()->for($silver)->count(3)->create();
    Product::factory()->for($premium)->draft()->create();
    Product::factory()->for($premium)->create()->delete();

    $this->get('/tea/this-tea')
        ->assertInertia(fn (Assert $page) => $page
            ->has('others', 3)
            ->where('others.0.id', $sibling->id)
            ->where('others.1.id', $others[0]->id)
            ->where('others.2.id', $others[1]->id)
            ->whereNot('others.0.id', $product->id)
        );
});

it('shows an unavailable Product, saying it cannot be had for now', function () {
    Product::factory()->unavailable()->create(['slug' => 'resting-tea']);

    $this->get('/tea/resting-tea')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('product')->where('product.isAvailable', false));
});

it('answers a Product taken out of the catalogue with a page saying it is no longer sold', function () {
    $product = Product::factory()->create(['name' => 'পুরোনো চা', 'slug' => 'old-tea']);
    $product->delete();

    $this->get('/tea/old-tea')
        ->assertStatus(410)
        ->assertInertia(fn (Assert $page) => $page
            ->component('product-gone')
            ->where('product', ['name' => 'পুরোনো চা'])
            ->has('others')
        );
});

it('answers 404 for a draft Product and for a slug nobody holds', function () {
    Product::factory()->draft()->create(['slug' => 'secret-tea']);

    $this->get('/tea/secret-tea')->assertNotFound();
    $this->get('/tea/no-such-tea')->assertNotFound();
});
