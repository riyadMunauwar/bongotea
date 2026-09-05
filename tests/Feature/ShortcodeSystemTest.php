<?php

use App\Models\Page;
use App\Models\Product;
use App\Shortcodes\AttributeDefinition;
use App\Shortcodes\AttributeSchema;
use App\Shortcodes\AttributeType;
use App\Shortcodes\Definitions\CheckoutShortcode;
use App\Shortcodes\Exceptions\InvalidShortcodeAttributes;
use App\Shortcodes\Node;
use App\Shortcodes\PageBodyValidator;
use App\Shortcodes\ShortcodeExpander;
use App\Shortcodes\ShortcodeParser;

describe('the parser', function () {
    it('leaves every byte that is not a registered shortcode untouched', function () {
        /**
         * Input is arbitrary designer HTML. A DOM round-trip would reformat it,
         * and a merchant seeing their markup silently rewritten reads that as
         * "your product broke my page".
         */
        $body = '<div   class = "hero"  ><!-- a comment -->'."\n"
            .'<script>const a = arr[0]; const b = {"key":[1,2]};</script>'."\n"
            .'<style>a[href^="#"] { color: red }</style>'."\n"
            .'[checkout]'."\n"
            .'</div>';

        $expanded = app(ShortcodeParser::class)->replace($body, fn (): string => 'ISLAND');

        expect($expanded)->toBe(str_replace('[checkout]', 'ISLAND', $body));
    });

    it('ignores bracketed text that is not a registered name', function () {
        /**
         * Matching anything shaped like `[word]` and then looking it up would
         * make our syntax a claim over the merchant's whole document.
         */
        $body = '<p>arr[0] and a[href] and [chekout] and [not-a-shortcode]</p>';

        expect(app(ShortcodeParser::class)->replace($body, fn (): string => 'X'))->toBe($body);
    });

    it('does not match a name that merely starts with a registered one', function () {
        expect(app(ShortcodeParser::class)->parse('[checkoutform]'))->toBe([]);
    });

    it('parses quoted attributes into the node', function () {
        registerShortcode('banner', fn (): string => '');

        $nodes = app(ShortcodeParser::class)->parse('[banner headline="Winter sale" tone=\'loud\']');

        expect($nodes)->toHaveCount(1)
            ->and($nodes[0]->name)->toBe('banner')
            ->and($nodes[0]->attributes)->toBe(['headline' => 'Winter sale', 'tone' => 'loud']);
    });

    it('finds every occurrence in document order', function () {
        registerShortcode('banner', fn (): string => '');

        $names = array_map(
            fn (Node $node): string => $node->name,
            app(ShortcodeParser::class)->parse('[banner] middle [checkout] end [banner]'),
        );

        expect($names)->toBe(['banner', 'checkout', 'banner']);
    });
});

describe('the attribute schema', function () {
    it('applies defaults and casts declared types', function () {
        $schema = new AttributeSchema(
            new AttributeDefinition('quantity', AttributeType::Integer, default: 1),
            new AttributeDefinition('compact', AttributeType::Boolean, default: false),
            new AttributeDefinition('headline'),
        );

        expect($schema->coerce('banner', ['quantity' => '3', 'compact' => 'true']))
            ->toBe(['quantity' => 3, 'compact' => true, 'headline' => null]);
    });

    it('rejects an attribute the definition never declared', function () {
        expect(fn () => AttributeSchema::none()->coerce('checkout', ['product' => '4']))
            ->toThrow(InvalidShortcodeAttributes::class);
    });

    it('rejects a missing required attribute', function () {
        $schema = new AttributeSchema(new AttributeDefinition('headline', required: true));

        expect(fn () => $schema->coerce('banner', []))
            ->toThrow(InvalidShortcodeAttributes::class);
    });

    it('ships checkout with no attributes, so nothing references a product by key', function () {
        expect(app(CheckoutShortcode::class)->attributes()->all())->toBe([]);
    });
});

describe('expansion', function () {
    it('never rewrites what the merchant stored', function () {
        $product = Product::factory()->create();
        $page = Page::factory()->withBody('<main>[checkout]</main>')->create();
        $page->products()->attach($product);

        app(ShortcodeExpander::class)->expand($page);

        /**
         * Expanding on save would make a price change a data migration over the
         * stored HTML of every linked page. See ADR-0001.
         */
        expect($page->fresh()->body)->toContain('[checkout]');
    });

    it('marks a page cacheable when every shortcode rendered', function () {
        registerShortcode('greeting', fn (): string => 'hi');

        $result = app(ShortcodeExpander::class)->expand(
            Page::factory()->withBody('[greeting]')->create(),
        );

        expect($result->cacheable)->toBeTrue()
            ->and($result->html)->toContain('hi');
    });

    it('marks a page uncacheable when a definition throws', function () {
        registerShortcode('boom', fn () => throw new RuntimeException('nope'));

        $result = app(ShortcodeExpander::class)->expand(
            Page::factory()->withBody('[boom]')->create(),
        );

        expect($result->cacheable)->toBeFalse();
    });
});

describe('the save-time linter', function () {
    it('blocks a second checkout on one page', function () {
        $errors = app(PageBodyValidator::class)->errors('[checkout] and [checkout]', linkedProductCount: 1);

        /** Two forms means two Orders and Delivery Charge collected twice. */
        expect($errors)->toHaveCount(1)
            ->and($errors[0])->toContain('at most one');
    });

    it('blocks a checkout with nothing linked to sell', function () {
        expect(app(PageBodyValidator::class)->errors('[checkout]', linkedProductCount: 0))
            ->toHaveCount(1);
    });

    it('passes a checkout with more than one product linked', function () {
        /**
         * One linked Product is the floor, not the ceiling: a page may sell a
         * combo or an upsell, and each Product carries its own quantity and its
         * own line.
         */
        expect(app(PageBodyValidator::class)->errors('[checkout]', linkedProductCount: 3))
            ->toBe([]);
    });

    it('passes a page with one checkout and one product', function () {
        expect(app(PageBodyValidator::class)->errors('<main>[checkout]</main>', linkedProductCount: 1))
            ->toBe([]);
    });

    it('passes a page with no checkout and no product', function () {
        expect(app(PageBodyValidator::class)->errors('<h1>Just content</h1>', linkedProductCount: 0))
            ->toBe([]);
    });

    it('warns about a likely typo without blocking the save', function () {
        $warnings = app(PageBodyValidator::class)->warnings('<main>[chekout]</main>');

        expect($warnings)->toHaveCount(1)
            ->and($warnings[0])->toContain('[checkout]');
    });

    it('stays quiet about bracketed text that is nothing like a shortcode', function () {
        /**
         * Generosity here is affordable precisely because it only ever warns —
         * but noise would train the merchant to ignore it.
         */
        expect(app(PageBodyValidator::class)->warnings('<style>a[href] {}</style><script>x[0]</script>'))
            ->toBe([]);
    });
});
