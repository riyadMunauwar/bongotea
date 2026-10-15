<?php

use App\Enums\CategoryTheme;
use App\Models\Brand;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Models\Tag;
use App\Support\Storefront\CatalogueProps;
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

it('draws a panel per Category with its short header, its Products, the Tags they carry, and no Brand filter while one Brand sells', function () {
    $brand = Brand::factory()->create();
    $premium = Category::factory()->create(['sort_order' => 0, 'quality_headline' => 'সেরা পাতা', 'about' => 'হাতে বাছাই', 'why_points' => ['গাঢ় রং']]);
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
            ->where('page.blocks.0.props.categories.0.qualityHeadline', 'সেরা পাতা')
            ->missing('page.blocks.0.props.categories.0.about')
            ->missing('page.blocks.0.props.categories.0.whyPoints')
            ->where('page.blocks.0.props.categories.0.productCount', 1)
            ->where('page.blocks.0.props.categories.0.products.0.id', $royal->id)
            ->where('page.blocks.0.props.categories.0.products.0.tagIds', collect([$milk->id, $plain->id])->sort()->values()->all())
            ->where('page.blocks.0.props.categories.1.products.0.id', $strong->id)
        );
});

it('lays the panels out in the columns and batches the Merchant chose, falling back on four and eight', function (array $data, int $columns, int $batchSize) {
    Product::factory()->create();

    pageWithBlock('category_panels', $data);

    $this->get('/live')
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.blocks.0.props.columns', $columns)
            ->where('page.blocks.0.props.batchSize', $batchSize)
        );
})->with([
    'unset' => [[], 4, 8],
    'chosen' => [['columns' => '5', 'batch_size' => '12'], 5, 12],
    'out of range' => [['columns' => '7', 'batch_size' => '500'], 4, 48],
    'too few' => [['columns' => '3', 'batch_size' => '0'], 3, 1],
]);

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

it('lays out a bulk Cart\'s assigned Products by Category, with every other bulk Product kept aside for its Contents', function () {
    $premium = Category::factory()->create(['sort_order' => 0, 'note' => 'সেরা বাছাই পাতা']);
    $silver = Category::factory()->create(['sort_order' => 1]);
    $empty = Category::factory()->create(['sort_order' => 2]);

    $royal = Product::factory()->for($premium)->create();
    $strong = Product::factory()->for($silver)->unavailable()->create();
    $draft = Product::factory()->for($silver)->draft()->create();
    $unassigned = Product::factory()->for($empty)->create();
    Product::factory()->for($empty)->retailOnly()->create();

    $cart = Cart::factory()->bulk()->create(['name' => 'Hotel & Restaurant']);
    $cart->products()->attach([$royal->getKey(), $strong->getKey(), $draft->getKey()]);

    pageWithBlock('cart', ['cart_id' => $cart->getKey(), 'heading' => 'একসাথে চা অর্ডার করুন', 'text' => 'শুধু কত কেজি লাগবে সেটি দিন।']);

    $this->get('/live')
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.blocks.0.type', 'cart')
            ->where('page.blocks.0.props', [
                'cart' => ['id' => $cart->getKey(), 'name' => 'Hotel & Restaurant', 'kind' => 'bulk', 'isOpen' => true],
                'heading' => 'একসাথে চা অর্ডার করুন',
                'text' => 'শুধু কত কেজি লাগবে সেটি দিন।',
                'quickChips' => true,
                'showTierList' => true,
                'categories' => [
                    [...CatalogueProps::categoryReference($premium), 'note' => 'সেরা বাছাই পাতা', 'products' => [CatalogueProps::card($royal->fresh())]],
                    [...CatalogueProps::categoryReference($silver), 'note' => $silver->note, 'products' => [CatalogueProps::card($strong->fresh())]],
                ],
                'others' => [CatalogueProps::card($unassigned->fresh())],
            ])
        );
});

it('lays out a retail Cart\'s assigned Products sold খুচরা, leaving the bulk-only ones out', function () {
    $gold = Category::factory()->create();
    $both = Product::factory()->for($gold)->create();
    $retailOnly = Product::factory()->for($gold)->retailOnly()->create();
    $bulkOnly = Product::factory()->for($gold)->bulkOnly()->create();

    $cart = Cart::factory()->retail()->create();
    $cart->products()->attach($retailOnly->getKey());

    pageWithBlock('cart', ['cart_id' => $cart->getKey()]);

    $this->get('/live')
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.blocks.0.props.cart.kind', 'retail')
            ->where('page.blocks.0.props.categories', [
                [...CatalogueProps::categoryReference($gold), 'note' => $gold->note, 'products' => [CatalogueProps::card($retailOnly->fresh())]],
            ])
            ->where('page.blocks.0.props.others', [CatalogueProps::card($both->fresh())])
            ->where('page.blocks.0.props.others', fn ($others): bool => collect($others)->doesntContain('id', $bulkOnly->getKey()))
        );
});

it('draws a closed Cart closed, its Products left out', function (Closure $close) {
    $tea = Product::factory()->create();
    $cart = Cart::factory()->bulk()->create();
    $cart->products()->attach($tea->getKey());

    pageWithBlock('cart', ['cart_id' => $cart->getKey()]);
    $close($cart);

    $this->get('/live')
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.blocks.0.props.cart.isOpen', false)
            ->where('page.blocks.0.props.categories', [])
            ->where('page.blocks.0.props.others', [])
        );
})->with([
    'switched off' => [fn (Cart $cart) => $cart->update(['is_switched_on' => false])],
    'deleted' => [fn (Cart $cart) => $cart->delete()],
]);

it('turns a bulk Cart\'s quick-set chips and price steps off', function () {
    pageWithBlock('cart', ['cart_id' => Cart::factory()->bulk()->create()->getKey(), 'quick_chips' => false, 'show_tier_list' => false]);

    $this->get('/live')
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.blocks.0.props.quickChips', false)
            ->where('page.blocks.0.props.showTierList', false)
        );
});

it('fills the price matrix with every published Product by Category', function (string $type) {
    $gold = Category::factory()->create();
    $classic = Product::factory()->for($gold)->create();
    Product::factory()->for($gold)->draft()->create();

    pageWithBlock($type, ['heading' => 'হিসাব করে দেখুন', 'text' => 'চা ও কেজি বেছে নিন']);

    $this->get('/live')
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.blocks.0.type', $type)
            ->where('page.blocks.0.props', [
                'heading' => 'হিসাব করে দেখুন',
                'text' => 'চা ও কেজি বেছে নিন',
                'categories' => [
                    [...CatalogueProps::categoryReference($gold), 'note' => $gold->note, 'products' => [CatalogueProps::card($classic->fresh())]],
                ],
            ])
        );
})->with(['price_matrix']);

it('lists no Product sold only খুচরা on a bulk surface', function (string $type) {
    $gold = Category::factory()->create();
    $bulk = Product::factory()->for($gold)->create();
    Product::factory()->for($gold)->retailOnly()->create();

    pageWithBlock($type);

    $this->get('/live')
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.blocks.0.props.categories', [
                [...CatalogueProps::categoryReference($gold), 'note' => $gold->note, 'products' => [CatalogueProps::card($bulk->fresh())]],
            ])
        );
})->with(['price_matrix']);

it('draws a Product sold only খুচরা on the other lists, saying it has no Tiers', function () {
    $retailOnly = Product::factory()->retailOnly()->create();

    pageWithBlock('product_rail', ['source' => 'picked', 'product_ids' => [$retailOnly->id]]);

    $this->get('/live')
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.blocks.0.props.products.0.id', $retailOnly->id)
            ->where('page.blocks.0.props.products.0.isSoldBulk', false)
            ->where('page.blocks.0.props.products.0.mrp', null)
            ->where('page.blocks.0.props.products.0.tiers', [])
        );
});

it('tiles a Category of Products sold only খুচরা with no lowest Tier price', function () {
    $family = Category::factory()->create();
    Product::factory()->for($family)->retailOnly()->create();

    pageWithBlock('category_tiles');

    $this->get('/live')
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.blocks.0.props.tiles.0.productCount', 1)
            ->where('page.blocks.0.props.tiles.0.lowestPrice', null)
        );
});

it('offers on a price calculator only the Products assigned to its bulk Cart, and opens that Cart\'s page', function () {
    $gold = Category::factory()->create();
    $assigned = Product::factory()->for($gold)->create();
    Product::factory()->for($gold)->create();

    $cart = Cart::factory()->bulk()->onAPage()->create(['name' => 'Hotel']);
    $cart->products()->attach($assigned->getKey());

    pageWithBlock('price_calculator', ['cart_id' => $cart->getKey(), 'heading' => 'হিসাব করে দেখুন']);

    $this->get('/live')
        ->assertInertia(fn (Assert $page) => $page->where('page.blocks.0.props', [
            'cart' => ['id' => $cart->getKey(), 'name' => 'Hotel', 'kind' => 'bulk', 'isOpen' => true, 'href' => $cart->link()],
            'heading' => 'হিসাব করে দেখুন',
            'text' => null,
            'categories' => [
                [...CatalogueProps::categoryReference($gold), 'note' => $gold->note, 'products' => [CatalogueProps::card($assigned->fresh())]],
            ],
        ]));
});

it('offers nothing on a price calculator whose Cart is closed, and leaves its own Cart where it stands', function () {
    $cart = Cart::factory()->bulk()->switchedOff()->onAPage()->create();
    $cart->products()->attach(Product::factory()->create()->getKey());
    $page = $cart->page();

    pageWithBlock('price_calculator', ['cart_id' => $cart->getKey()]);

    $this->get('/live')
        ->assertInertia(fn (Assert $page) => $page->where('page.blocks.0.props.cart.href', null)->where('page.blocks.0.props.categories', []));

    expect($cart->page()->is($page))->toBeTrue();
});

it('tells each card the Carts its tea is assigned to, so an add from it knows where to go', function () {
    $category = Category::factory()->create();
    $tea = Product::factory()->for($category)->create();
    $loose = Product::factory()->for($category)->create();
    $hotel = Cart::factory()->bulk()->create();
    $eid = Cart::factory()->retail()->create();
    $hotel->products()->attach($tea->id);
    $eid->products()->attach($tea->id);

    pageWithBlock('product_rail', ['source' => 'category', 'category_id' => $category->id]);

    $this->get('/live')
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.blocks.0.props.products.0.id', $tea->id)
            ->where('page.blocks.0.props.products.0.cartIds', [$hotel->id, $eid->id])
            ->where('page.blocks.0.props.products.1.id', $loose->id)
            ->where('page.blocks.0.props.products.1.cartIds', [])
        );
});
