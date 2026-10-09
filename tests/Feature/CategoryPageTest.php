<?php

use App\Enums\CategoryTheme;
use App\Models\Category;
use App\Models\Product;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A Category at its own address: what it says about itself and its
 * published Products, in its Category Theme.
 */
it('shows a Category with its description, why points, theme and published Products', function () {
    $category = Category::factory()->create([
        'name' => 'গোল্ড',
        'slug' => 'gold',
        'note' => 'মান ও দামের ভারসাম্য',
        'quality_headline' => 'মান ও দামের সেরা ভারসাম্য',
        'about' => 'প্রতিদিনের বিক্রিতে সবচেয়ে বেশি চলে।',
        'why_points' => ['দুধ চা ও রং চা দুটোতেই ভালো', '', 'মাঝারি দামে বেশি কাপ'],
        'theme_key' => CategoryTheme::Gold->value,
    ]);

    $gold = Product::factory()->for($category)->create(['tiers' => [
        ['min' => 1, 'max' => 5, 'price' => 55000],
        ['min' => 6, 'max' => null, 'price' => 43000],
    ]]);
    $resting = Product::factory()->for($category)->unavailable()->create(['price' => 51000]);
    Product::factory()->for($category)->draft()->create();
    Product::factory()->for($category)->create()->delete();
    Product::factory()->create();

    $this->get('/category/gold')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('category')
            ->where('category', [
                'id' => $category->id,
                'name' => 'গোল্ড',
                'slug' => 'gold',
                'href' => '/category/gold',
                'theme' => CategoryTheme::Gold->tokens(),
                'note' => 'মান ও দামের ভারসাম্য',
                'qualityHeadline' => 'মান ও দামের সেরা ভারসাম্য',
                'about' => 'প্রতিদিনের বিক্রিতে সবচেয়ে বেশি চলে।',
                'whyPoints' => ['দুধ চা ও রং চা দুটোতেই ভালো', 'মাঝারি দামে বেশি কাপ'],
                'productCount' => 2,
                'priceRange' => ['low' => 43000, 'high' => 55000],
            ])
            ->has('products', 2)
            ->where('products.0.id', $gold->id)
            ->where('products.1.id', $resting->id)
            ->where('products.1.isAvailable', false)
            ->where('seo.title', 'গোল্ড — Bongotea')
            ->where('seo.description', 'প্রতিদিনের বিক্রিতে সবচেয়ে বেশি চলে।')
            ->where('seo.canonical', url('/category/gold'))
        );
});

it('falls back to the Neutral theme for a theme the store no longer ships', function () {
    Category::factory()->create(['slug' => 'retired', 'theme_key' => 'sunset']);

    $this->get('/category/retired')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('category.theme', CategoryTheme::Neutral->tokens())
            ->where('category.productCount', 0)
            ->where('category.priceRange', null)
            ->where('products', [])
        );
});

it('answers 404 for a slug no Category holds', function () {
    $this->get('/category/nothing')->assertNotFound();
});
