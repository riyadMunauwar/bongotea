<?php

namespace App\Support\Settings;

use App\Enums\MenuLinkType;
use App\Support\Storefront\StorefrontShell;

/**
 * The storefront's header and footer Menus.
 *
 * Every entry is stored flat: a `label`, a `type` (`MenuLinkType`) and the
 * one target field that type reads — `page_id`, `anchor` (with an optional
 * `page_id`) or `url`. A header entry of type Dropdown carries `children`,
 * each of which may add a `note` drawn under its label. The footer is a blurb,
 * columns of links under a heading, and the short line of links at the very
 * bottom. Turning a stored entry into an address is `StorefrontMenus`' job,
 * not this group's.
 *
 * The defaults are the mockup's Menus, typed as URLs because no Page exists
 * on a fresh install to point at.
 *
 * @phpstan-type MenuLink array{label: string, type: string, page_id: ?int, anchor: ?string, url: ?string, note?: ?string}
 * @phpstan-type HeaderItem array{label: string, type: string, page_id: ?int, anchor: ?string, url: ?string, children: list<MenuLink>}
 * @phpstan-type FooterColumn array{heading: string, links: list<MenuLink>}
 */
class MenuSettings extends SettingsGroup
{
    /**
     * @var list<HeaderItem>
     */
    public array $header = [];

    public string $footerBlurb = 'চায়ের দোকান, রেস্টুরেন্ট ও ব্যবসার জন্য বাছাই করা চা। বেশি নিলে প্রতি কেজির দাম কম।';

    /**
     * @var list<FooterColumn>
     */
    public array $footerColumns = [];

    /**
     * @var list<MenuLink>
     */
    public array $footerBottomLinks = [];

    public function __construct()
    {
        $this->header = self::headerItems([
            self::url('হোম', '/'),
            self::dropdown('আমাদের চা', [
                self::anchor('প্রিমিয়াম', 'seg-premium', 'সেরা বাছাই পাতা'),
                self::anchor('গোল্ড', 'seg-gold', 'মান ও দামের ভারসাম্য'),
                self::anchor('সিলভার', 'seg-silver', 'সাশ্রয়ী দৈনিক চা'),
            ]),
            self::url('দাম হিসাব', '/pricing'),
            self::dropdown('কীভাবে অর্ডার করবেন', [
                self::anchor('অর্ডারের ধাপ', 'how', '৩ ধাপে সহজ অর্ডার'),
                self::url('দাম ও বাল্ক ছাড়', '/pricing', 'বেশি নিলে কেজিপ্রতি দাম কম'),
                self::url('বাল্ক অর্ডার করুন', StorefrontShell::BULK_ORDER_PATH),
                self::url('আমার অর্ডার ও ডেলিভারি', StorefrontShell::MY_ORDERS_PATH),
            ]),
            self::url('যোগাযোগ', '/contact'),
        ]);

        $this->footerColumns = [
            ['heading' => 'অর্ডার', 'links' => [
                self::url('হোম', '/'),
                self::anchor('আমাদের চা', 'products'),
                self::url('দাম হিসাব', '/pricing'),
                self::url('বাল্ক অর্ডার', StorefrontShell::BULK_ORDER_PATH),
                self::url('আমার অর্ডার', StorefrontShell::MY_ORDERS_PATH),
            ]],
            ['heading' => 'Bongotea', 'links' => [
                self::url('আমাদের সম্পর্কে', '/about'),
                self::url('যোগাযোগ', '/contact'),
                self::anchor('কীভাবে অর্ডার করবেন', 'how'),
            ]],
        ];

        $this->footerBottomLinks = [
            self::url('আমাদের সম্পর্কে', '/about'),
            self::url('যোগাযোগ', '/contact'),
        ];
    }

    public static function key(): string
    {
        return 'menus';
    }

    /**
     * The base coerces scalars and leaves arrays alone, so a hand-edited or
     * older row could hand a Menu over as a string, or an entry missing its
     * label, and `load()` would throw on every request. Each list is reduced
     * to the shape this group promises before the assignment, dropping what
     * cannot be read rather than refusing the row.
     *
     * @param  array<string, mixed>  $values
     */
    public function with(array $values): static
    {
        if (array_key_exists('header', $values)) {
            $values['header'] = self::headerItems($values['header']);
        }

        if (array_key_exists('footerColumns', $values)) {
            $values['footerColumns'] = self::footerColumns($values['footerColumns']);
        }

        if (array_key_exists('footerBottomLinks', $values)) {
            $values['footerBottomLinks'] = self::links($values['footerBottomLinks'], MenuLinkType::targets());
        }

        return parent::with($values);
    }

    /**
     * @return list<HeaderItem>
     */
    private static function headerItems(mixed $items): array
    {
        $header = [];

        foreach (is_array($items) ? $items : [] as $item) {
            $entry = self::link($item, MenuLinkType::cases());

            if ($entry === null) {
                continue;
            }

            $header[] = [
                ...$entry,
                'children' => $entry['type'] === MenuLinkType::Dropdown->value
                    ? self::links($item['children'] ?? [], MenuLinkType::targets())
                    : [],
            ];
        }

        return $header;
    }

    /**
     * @return list<FooterColumn>
     */
    private static function footerColumns(mixed $columns): array
    {
        $footer = [];

        foreach (is_array($columns) ? $columns : [] as $column) {
            if (! is_array($column)) {
                continue;
            }

            $footer[] = [
                'heading' => is_scalar($column['heading'] ?? null) ? (string) $column['heading'] : '',
                'links' => self::links($column['links'] ?? [], MenuLinkType::targets()),
            ];
        }

        return $footer;
    }

    /**
     * The readable entries of a list, as a plain list: a Repeater's
     * uuid-keyed state saves without its keys.
     *
     * @param  list<MenuLinkType>  $allowed
     * @return list<MenuLink>
     */
    private static function links(mixed $links, array $allowed): array
    {
        $kept = [];

        foreach (is_array($links) ? $links : [] as $link) {
            $entry = self::link($link, $allowed);

            if ($entry !== null) {
                $kept[] = $entry;
            }
        }

        return $kept;
    }

    /**
     * One entry in exactly the stored shape, or null for one that cannot be
     * read: not an array, no label, or a type not allowed where it sits.
     *
     * @param  list<MenuLinkType>  $allowed
     * @return MenuLink|null
     */
    private static function link(mixed $link, array $allowed): ?array
    {
        if (! is_array($link)) {
            return null;
        }

        $type = MenuLinkType::tryFrom(is_scalar($link['type'] ?? null) ? (string) $link['type'] : '');
        $label = is_scalar($link['label'] ?? null) ? trim((string) $link['label']) : '';

        if ($type === null || ! in_array($type, $allowed, true) || $label === '') {
            return null;
        }

        return [
            'label' => $label,
            'type' => $type->value,
            'page_id' => is_numeric($link['page_id'] ?? null) ? (int) $link['page_id'] : null,
            'anchor' => filled($link['anchor'] ?? null) ? ltrim((string) $link['anchor'], '#') : null,
            'url' => filled($link['url'] ?? null) ? trim((string) $link['url']) : null,
            'note' => filled($link['note'] ?? null) ? (string) $link['note'] : null,
        ];
    }

    /**
     * @param  list<MenuLink>  $children
     * @return HeaderItem
     */
    private static function dropdown(string $label, array $children): array
    {
        return [...self::entry($label, MenuLinkType::Dropdown), 'children' => $children];
    }

    /**
     * @return MenuLink
     */
    private static function url(string $label, string $url, ?string $note = null): array
    {
        return [...self::entry($label, MenuLinkType::Url, note: $note), 'url' => $url];
    }

    /**
     * @return MenuLink
     */
    private static function anchor(string $label, string $anchor, ?string $note = null): array
    {
        return [...self::entry($label, MenuLinkType::Anchor, note: $note), 'anchor' => $anchor];
    }

    /**
     * @return MenuLink
     */
    private static function entry(string $label, MenuLinkType $type, ?string $note = null): array
    {
        return ['label' => $label, 'type' => $type->value, 'page_id' => null, 'anchor' => null, 'url' => null, 'note' => $note];
    }
}
