<?php

namespace App\Support\Pages;

use App\Models\Page;
use App\Support\Pages\Blocks\CallToActionBand;
use App\Support\Pages\Blocks\CartBlock;
use App\Support\Pages\Blocks\CategoryPanels;
use App\Support\Pages\Blocks\CategoryTiles;
use App\Support\Pages\Blocks\ChipsRow;
use App\Support\Pages\Blocks\ContactCards;
use App\Support\Pages\Blocks\ContactForm;
use App\Support\Pages\Blocks\DeliveryChart;
use App\Support\Pages\Blocks\Faq;
use App\Support\Pages\Blocks\HeroCarousel;
use App\Support\Pages\Blocks\HowToOrder;
use App\Support\Pages\Blocks\InfoCards;
use App\Support\Pages\Blocks\Journey;
use App\Support\Pages\Blocks\MapAndAddress;
use App\Support\Pages\Blocks\MyOrders;
use App\Support\Pages\Blocks\PriceCalculator;
use App\Support\Pages\Blocks\PriceMatrix;
use App\Support\Pages\Blocks\ProductRail;
use App\Support\Pages\Blocks\PromiseStrip;
use App\Support\Pages\Blocks\RecipeCards;
use App\Support\Pages\Blocks\Story;
use App\Support\Pages\Blocks\Table;
use App\Support\Pages\Blocks\TextReviews;
use App\Support\Pages\Blocks\ValueCards;
use App\Support\Pages\Blocks\VideoReviews;
use Filament\Forms\Components\Builder\Block;

/**
 * The fixed set of Block kinds, and a Page's Blocks as the storefront draws
 * them.
 *
 * The order here is the order the editor offers them in. A stored Block of a
 * kind not listed — one since removed — is left out of the page rather than
 * failing it.
 *
 * @phpstan-type StorefrontBlock array{type: string, anchor: ?string, hideOnMobile: bool, hideOnDesktop: bool, props: array<string, mixed>}
 */
class PageBlocks
{
    /** @var list<class-string<PageBlock>> */
    public const KINDS = [
        HeroCarousel::class,
        PromiseStrip::class,
        HowToOrder::class,
        VideoReviews::class,
        TextReviews::class,
        Story::class,
        Journey::class,
        ValueCards::class,
        InfoCards::class,
        Table::class,
        RecipeCards::class,
        ChipsRow::class,
        CallToActionBand::class,
        ContactCards::class,
        MapAndAddress::class,
        Faq::class,
        CategoryTiles::class,
        CategoryPanels::class,
        ProductRail::class,
        CartBlock::class,
        PriceCalculator::class,
        PriceMatrix::class,
        DeliveryChart::class,
        MyOrders::class,
        ContactForm::class,
    ];

    /**
     * @return list<Block>
     */
    public static function builderBlocks(): array
    {
        return array_map(fn (string $kind): Block => $kind::builderBlock(), self::KINDS);
    }

    /**
     * @return list<StorefrontBlock>
     */
    public static function forStorefront(Page $page): array
    {
        $kinds = collect(self::KINDS)->keyBy(fn (string $kind): string => $kind::type());
        $blocks = [];

        foreach ($page->blocks ?? [] as $block) {
            $kind = $kinds->get($block['type'] ?? '');
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];

            if ($kind === null) {
                continue;
            }

            $anchor = $data['anchor'] ?? null;

            $blocks[] = [
                'type' => $kind::type(),
                'anchor' => is_string($anchor) && preg_match('/^'.Page::SLUG.'$/', $anchor) === 1 ? $anchor : null,
                'hideOnMobile' => (bool) ($data['hide_on_mobile'] ?? false),
                'hideOnDesktop' => (bool) ($data['hide_on_desktop'] ?? false),
                'props' => $kind::props($data),
            ];
        }

        return $blocks;
    }
}
