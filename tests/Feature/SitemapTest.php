<?php

use App\Enums\SystemPage;
use App\Models\Category;
use App\Models\Page;
use App\Models\Product;

/**
 * What search engines are given: every public address in the sitemap and
 * nothing else, and a robots.txt pointing at it.
 */
it('lists Home, published Pages, Categories and published Products, and nothing unreachable', function () {
    Page::factory()->system(SystemPage::Home)->create();
    Page::factory()->published()->create(['slug' => 'pricing']);
    Page::factory()->create(['slug' => 'draft-page']);
    $category = Category::factory()->create(['slug' => 'premium']);
    Product::factory()->for($category)->create(['slug' => 'royal']);
    Product::factory()->for($category)->unavailable()->create(['slug' => 'resting']);
    Product::factory()->for($category)->draft()->create(['slug' => 'secret']);
    Product::factory()->for($category)->create(['slug' => 'gone'])->delete();

    $response = $this->get('/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

    $locations = [];

    foreach (simplexml_load_string($response->getContent())->url as $entry) {
        $locations[] = (string) $entry->loc;
    }

    sort($locations);

    expect($locations)->toBe(collect([
        url('/'),
        url('/pricing'),
        url('/category/premium'),
        url('/tea/royal'),
        url('/tea/resting'),
    ])->sort()->values()->all());
});

it('serves a robots.txt that keeps crawlers out of private addresses and names the sitemap', function () {
    $this->get('/robots.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSeeText('Disallow: /admin')
        ->assertSeeText('Disallow: /order/')
        ->assertSeeText('Sitemap: '.url('/sitemap.xml'));
});
