<?php

use App\Enums\OrderKind;
use App\Models\Cart;
use App\Models\Page;

/**
 * The demo store `bongotea:install --demo` opens is demo content a Merchant
 * would otherwise build by hand, so what matters is that it holds together:
 * every Page draws, and every link it carries — a button, a chip, a card —
 * reaches a Page, Category or Product that is there, and an anchor that is
 * on it.
 */
function installDemo(): void
{
    test()->artisan('bongotea:install', installOptions())->assertSuccessful();
}

/**
 * Every `href` a Page's Blocks carry, wherever in their props it sits.
 *
 * @param  array<mixed>  $props
 * @return list<string>
 */
function hrefsIn(array $props): array
{
    $hrefs = [];

    array_walk_recursive($props, function (mixed $value, int|string $key) use (&$hrefs): void {
        if ($key === 'href' && is_string($value)) {
            $hrefs[] = $value;
        }
    });

    return $hrefs;
}

/**
 * The anchors a drawn Page offers: its Blocks', its cards' and recipes', and
 * each Category panel's.
 *
 * @param  list<array<string, mixed>>  $blocks
 * @return list<string>
 */
function anchorsIn(array $blocks): array
{
    $anchors = [];

    foreach ($blocks as $block) {
        $anchors[] = $block['anchor'];

        foreach ([...($block['props']['cards'] ?? []), ...($block['props']['recipes'] ?? [])] as $item) {
            $anchors[] = $item['anchor'] ?? null;
        }

        foreach ($block['props']['categories'] ?? [] as $category) {
            $anchors[] = 'category-'.$category['slug'];
        }
    }

    return array_values(array_filter($anchors));
}

it('opens every demo Page, each drawing its Blocks', function () {
    installDemo();

    /** The starting Carts' own Pages are the store's, not the demo's. */
    $cartPages = [Cart::startingFor(OrderKind::Bulk)->page()->id, Cart::startingFor(OrderKind::Retail)->page()->id];
    $slugs = Page::query()->whereNull('system_role')->whereKeyNot($cartPages)->pluck('slug')->sort()->values()->all();

    expect($slugs)->toBe(collect([
        'about', 'contact', 'faq', 'how-to-brew', 'our-goals', 'our-promise', 'our-purpose', 'pricing',
        'tea-grades', 'tea-guide', 'tea-recipes', 'tea-storage', 'tea-types', 'which-tea', 'who-its-for', 'why-bongo-tea',
    ])->sort()->values()->all());

    foreach ($slugs as $slug) {
        $blocks = $this->get("/{$slug}")->assertOk()->viewData('page')['props']['page']['blocks'];

        expect(count($blocks))->toBeGreaterThan(1, "/{$slug} draws too few Blocks");
    }
});

it('links only to Pages, Categories, Products and anchors that are there', function () {
    installDemo();

    $paths = ['/', ...Page::query()->whereNull('system_role')->pluck('slug')->map(fn (string $slug): string => "/{$slug}")->all()];
    $anchors = [];
    $links = [];

    foreach ([...$paths, '/bulk-order', '/my-orders'] as $path) {
        $blocks = $this->get($path)->assertOk()->viewData('page')['props']['page']['blocks'];

        $anchors[$path] = anchorsIn($blocks);

        foreach (hrefsIn(array_column($blocks, 'props')) as $href) {
            $links[] = [$path, $href];
        }
    }

    expect($links)->not->toBeEmpty();

    foreach ($links as [$from, $href]) {
        if (! str_starts_with($href, '/')) {
            continue;
        }

        [$path, $anchor] = array_pad(explode('#', $href, 2), 2, null);
        $path = $path === '' ? $from : $path;

        $this->get($path)->assertOk();

        if ($anchor !== null) {
            $target = $anchors[$path] ?? anchorsIn($this->get($path)->viewData('page')['props']['page']['blocks'] ?? []);

            expect(in_array($anchor, $target, true))->toBeTrue("{$from} links to {$href}, which has no such anchor");
        }
    }
});

it('quotes no taka figure in any typed Block, since prices are read from the Tiers', function () {
    installDemo();

    foreach (Page::query()->get() as $page) {
        foreach ($page->blocks as $block) {
            expect(json_encode($block['data'], JSON_UNESCAPED_UNICODE))->not->toMatch('/৳\s*[০-৯0-9]/u', "/{$page->slug} quotes a price in a {$block['type']} Block");
        }
    }
});
