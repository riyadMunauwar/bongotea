<?php

namespace Database\Seeders\Pages;

use App\Models\Category;
use App\Support\Pages\Blocks\CallToActionBand;
use App\Support\Pages\Blocks\HeroCarousel;
use App\Support\Pages\PageBlock;

/**
 * One of the ordinary Pages a demo install opens with: its title, its Slug
 * and its Blocks as the editor would store them.
 *
 * Demo content only — what a Merchant would otherwise build by hand in the
 * panel — so it is written once and never put back, and every link in it is
 * one of the addresses below rather than a Page's id, because the Pages it
 * points at are created alongside it.
 */
abstract class DemoPage
{
    public const BULK_ORDER = '/bulk-order';

    public const PRICING = '/pricing';

    public const CONTACT = '/contact';

    public const ABOUT = '/about';

    public const WHO_ITS_FOR = '/who-its-for';

    public const TEA_GUIDE = '/tea-guide';

    public const TEA_TYPES = '/tea-types';

    public const TEA_GRADES = '/tea-grades';

    public const WHICH_TEA = '/which-tea';

    public const HOW_TO_BREW = '/how-to-brew';

    public const TEA_STORAGE = '/tea-storage';

    public const TEA_RECIPES = '/tea-recipes';

    public const FAQ = '/faq';

    public const TEA_STALL_PREMIUM = '/category/tea-stall-premium';

    public const TEA_STALL_CLASSIC = '/category/tea-stall-classic';

    public const FAMILY_BLEND = '/category/family-blend';

    abstract public static function title(): string;

    abstract public static function slug(): string;

    /**
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    abstract public static function blocks(): array;

    /**
     * A demo Category's id for a live Block, read when the Page is created —
     * after the catalogue — or null on a store that has no such Category.
     */
    protected static function categoryId(string $slug): ?int
    {
        return Category::query()->where('slug', $slug)->value('id');
    }

    /**
     * @param  class-string<PageBlock>  $kind
     * @param  array<string, mixed>  $data
     * @return array{type: string, data: array<string, mixed>}
     */
    protected static function block(string $kind, array $data): array
    {
        return ['type' => $kind::type(), 'data' => $data];
    }

    /**
     * A dark page header: the first slide of a hero, drawn alone, with up to
     * four buttons given as label => link.
     *
     * @param  array<string, string>  $buttons
     * @return array{type: string, data: array<string, mixed>}
     */
    protected static function banner(string $eyebrow, string $title, string $text, array $buttons = []): array
    {
        $labels = array_keys($buttons);
        $links = array_values($buttons);

        return self::block(HeroCarousel::class, [
            'layout' => 'banner',
            'slides' => [[
                'eyebrow' => $eyebrow,
                'title' => $title,
                'text' => $text,
                'primary_label' => $labels[0] ?? null,
                'primary_link' => $links[0] ?? null,
                'secondary_label' => $labels[1] ?? null,
                'secondary_link' => $links[1] ?? null,
                'more_buttons' => array_map(
                    fn (string $label, string $link): array => ['label' => $label, 'link' => $link],
                    array_slice($labels, 2),
                    array_slice($links, 2),
                ),
            ]],
        ]);
    }

    /**
     * The dark band that closes a Page, with up to four buttons given as
     * label => link.
     *
     * @param  array<string, string>  $buttons
     * @return array{type: string, data: array<string, mixed>}
     */
    protected static function callToAction(string $heading, ?string $text, array $buttons): array
    {
        $labels = array_keys($buttons);
        $links = array_values($buttons);

        return self::block(CallToActionBand::class, [
            'heading' => $heading,
            'text' => $text,
            'primary_label' => $labels[0] ?? null,
            'primary_link' => $links[0] ?? null,
            'secondary_label' => $labels[1] ?? null,
            'secondary_link' => $links[1] ?? null,
            'more_buttons' => array_map(
                fn (string $label, string $link): array => ['label' => $label, 'link' => $link],
                array_slice($labels, 2),
                array_slice($links, 2),
            ),
        ]);
    }
}
