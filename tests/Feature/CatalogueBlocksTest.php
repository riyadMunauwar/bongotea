<?php

use App\Enums\CategoryTheme;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Models\Tag;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The live Blocks: they store only headings and switches, and the server
 * fills them with the catalogue as it is now — published Products only,
 * unavailable ones still shown — whenever their Page is drawn.
 *
 * @param  array<string, mixed>  $data
 */
function pageWithBlock(string $type, array $data = []): void
{
    Page::factory()->published()->withBlocks([['type' => $type, 'data' => $data]])->create(['slug' => 'live']);
}

/**
 * A Product with Tiers whose last price is the given one.
 *
 * @param  array<string, mixed>  $attributes
 */
function productEndingAt(Category $category, int $regularPrice, int $lastPrice, array $attributes = []): Product
{
    return Product::factory()->for($category)->create([
        ...$attributes,
        'tiers' => [
            ['min' => 1, 'max' => 50, 'price' => $regularPrice],
            ['min' => 51, 'max' => null, 'price' => $lastPrice],
        ],
    ]);
}

it('tiles each Category that has published Products with its count and lowest top-Tier price', function () {
    $premium = Category::factory()->create(['name' => 'প্রিমিয়াম', 'slug' => 'premium', 'note' => 'সেরা বাছাই পাতা', 'sort_order' => 0, 'theme_key' => CategoryTheme::Premium->value]);
    $gold = Category::factory()->create(['name' => 'গোল্ড', 'slug' => 'gold', 'sort_order' => 1]);
    Category::factory()->create(['name' => 'Empty', 'sort_order' => 2]);

    productEndingAt($premium, 62000, 49600);
    productEndingAt($premium, 65000, 53300);
    productEndingAt($premium, 50000, 30000, ['status' => 'draft']);
    productEndingAt($premium, 50000, 30000)->delete();
    productEndingAt($gold, 55000, 43000, ['is_available' => false]);

    pageWithBlock('category_tiles', ['heading' => 'মান অনুযায়ী কিনুন', 'all_label' => 'সব চা দেখুন →', 'all_link' => '/#products']);

    $this->get('/live')
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.blocks.0.type', 'category_tiles')
            ->where('page.blocks.0.props', [
                'heading' => 'মান অনুযায়ী কিনুন',
                'allLink' => ['label' => 'সব চা দেখুন →', 'href' => '/#products'],
                'tiles' => [
                    ['id' => $premium->id, 'name' => 'প্রিমিয়াম', 'slug' => 'premium', 'note' => 'সেরা বাছাই পাতা', 'href' => '/category/premium', 'theme' => CategoryTheme::Premium->tokens(), 'productCount' => 2, 'lowestPrice' => 49600],
                    ['id' => $gold->id, 'name' => 'গোল্ড', 'slug' => 'gold', 'note' => $gold->note, 'href' => '/category/gold', 'theme' => $gold->theme()->tokens(), 'productCount' => 1, 'lowestPrice' => 43000],
                ],
            ])
        );
});

it('draws a panel per Category with its Products, the Tags they carry, and no Brand filter while one Brand sells', function () {
    $brand = Brand::factory()->create();
    $premium = Category::factory()->create(['sort_order' => 0, 'why_points' => ['হাতে বাছাই করা কচি পাতা']]);
    $silver = Category::factory()->create(['sort_order' => 1]);
    $milk = Tag::factory()->create(['name' => 'দুধ চায়ের জন্য', 'sort_order' => 1]);
    $plain = Tag::factory()->create(['name' => 'রং চায়ের জন্য', 'sort_order' => 0]);
    Tag::factory()->create(['name' => 'Unused']);

    $royal = Product::factory()->for($premium)->for($brand)->hasAttached([$milk, $plain])->create();
    $strong = Product::factory()->for($silver)->for($brand)->hasAttached($milk)->create();
    Product::factory()->for($silver)->draft()->create();

    pageWithBlock('category_panels', ['eyebrow' => 'আমাদের চা', 'heading' => 'যে চা দরকার, বেছে নিন']);

    $this->get('/live')
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.blocks.0.type', 'category_panels')
            ->where('page.blocks.0.props.eyebrow', 'আমাদের চা')
            ->where('page.blocks.0.props.heading', 'যে চা দরকার, বেছে নিন')
            ->where('page.blocks.0.props.tags', [
                ['id' => $plain->id, 'name' => 'রং চায়ের জন্য'],
                ['id' => $milk->id, 'name' => 'দুধ চায়ের জন্য'],
            ])
            ->where('page.blocks.0.props.brands', [])
            ->has('page.blocks.0.props.categories', 2)
            ->where('page.blocks.0.props.categories.0.id', $premium->id)
            ->where('page.blocks.0.props.categories.0.whyPoints', ['হাতে বাছাই করা কচি পাতা'])
            ->where('page.blocks.0.props.categories.0.productCount', 1)
            ->where('page.blocks.0.props.categories.0.products.0.id', $royal->id)
            ->where('page.blocks.0.props.categories.0.products.0.tagIds', collect([$milk->id, $plain->id])->sort()->values()->all())
            ->where('page.blocks.0.props.categories.1.products.0.id', $strong->id)
        );
});

it('offers a Brand filter once more than one Brand has published Products', function () {
    $ours = Brand::factory()->create(['name' => 'Bongotea']);
    $theirs = Brand::factory()->create(['name' => 'Ispahani']);
    $draftOnly = Brand::factory()->create(['name' => 'Draft Only']);

    Product::factory()->for($ours)->create();
    Product::factory()->for($theirs)->unavailable()->create();
    Product::factory()->for($draftOnly)->draft()->create();

    pageWithBlock('category_panels');

    $this->get('/live')
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.blocks.0.props.brands', [
                ['id' => $ours->id, 'name' => 'Bongotea'],
                ['id' => $theirs->id, 'name' => 'Ispahani'],
            ])
        );
});

it('fills a product rail from a Category, a Tag, a Brand or a hand-picked list', function (string $source, Closure $setUp) {
    [$data, $expectedIds] = $setUp();

    pageWithBlock('product_rail', ['heading' => 'জনপ্রিয় চা', 'source' => $source, ...$data]);

    $this->get('/live')
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.blocks.0.type', 'product_rail')
            ->where('page.blocks.0.props.heading', 'জনপ্রিয় চা')
            ->where('page.blocks.0.props.products', fn ($products): bool => collect($products)->pluck('id')->all() === $expectedIds)
        );
})->with([
    'Category' => ['category', function (): array {
        $category = Category::factory()->create();
        $inside = Product::factory()->for($category)->count(2)->create();
        Product::factory()->create();

        return [['category_id' => $category->id], $inside->pluck('id')->all()];
    }],
    'Tag' => ['tag', function (): array {
        $tag = Tag::factory()->create();
        $tagged = Product::factory()->hasAttached($tag)->create();
        Product::factory()->create();

        return [['tag_id' => $tag->id], [$tagged->id]];
    }],
    'Brand' => ['brand', function (): array {
        $brand = Brand::factory()->create();
        $branded = Product::factory()->for($brand)->create();
        Product::factory()->create();

        return [['brand_id' => $brand->id], [$branded->id]];
    }],
    'hand-picked, in the order picked, skipping drafts and removed teas' => ['picked', function (): array {
        $first = Product::factory()->create();
        $second = Product::factory()->create();
        $draft = Product::factory()->draft()->create();
        $gone = Product::factory()->create();
        $gone->delete();

        return [['product_ids' => [(string) $second->id, $draft->id, $gone->id, $first->id]], [$second->id, $first->id]];
    }],
]);

it('caps a product rail at its limit', function () {
    $category = Category::factory()->create();
    Product::factory()->for($category)->count(5)->create();

    pageWithBlock('product_rail', ['source' => 'category', 'category_id' => $category->id, 'limit' => 3]);

    $this->get('/live')->assertInertia(fn (Assert $page) => $page->has('page.blocks.0.props.products', 3));
});

it('leaves a product rail empty when what it names is gone', function () {
    Product::factory()->create();

    pageWithBlock('product_rail', ['source' => 'category', 'category_id' => 999]);

    $this->get('/live')->assertInertia(fn (Assert $page) => $page->where('page.blocks.0.props.products', []));
});
