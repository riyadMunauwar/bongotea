<?php

namespace App\Support\Menus;

use App\Enums\MenuLinkType;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use App\Support\Storefront\StorefrontMenus;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * The one way a Menu's items are written: a whole nested tree, validated as
 * a whole and then swapped in for the old one.
 *
 * The builder holds the tree in the browser and submits all of it on Save,
 * so this is the guard and the builder's own depth limit is a convenience.
 * A tree is refused — pointing at the first offending item, and writing
 * nothing — when it is deeper than three levels, when an item's target does
 * not fit its type, when a Page it names does not exist, or when a URL is
 * not one the storefront would draw. Otherwise the old items are deleted
 * and the new ones inserted inside one transaction, and one activity entry
 * says the Menu was saved: the history reads as Saves, not as every drag.
 *
 * @phpstan-type SubmittedItem array{label?: mixed, type?: mixed, note?: mixed, target_id?: mixed, anchor?: mixed, url?: mixed, children?: mixed}
 * @phpstan-type CleanItem array{label: string, type: MenuLinkType, note: ?string, page_id: ?int, anchor: ?string, url: ?string, children: list<mixed>}
 */
class MenuTree
{
    /** The levels a Menu may have: the bar, its dropdown, and a flyout. */
    public const MAX_DEPTH = 3;

    public const MAX_LABEL = 40;

    public const MAX_NOTE = 60;

    public const MAX_URL = 500;

    /** A Block's anchor as typed, with or without its `#`. */
    public const ANCHOR = '/^#?[A-Za-z0-9_-]+$/';

    /**
     * @param  list<SubmittedItem>  $tree
     *
     * @throws MenuTreeRefused
     */
    public static function save(Menu $menu, array $tree, ?Authenticatable $by = null): void
    {
        $pages = Page::query()->whereKey(self::pageIds($tree))->pluck('id')->all();
        $clean = self::clean($tree, $pages, []);

        DB::transaction(function () use ($menu, $clean, $by): void {
            MenuItem::query()->where('menu_id', $menu->id)->delete();

            $count = self::insert($menu, $clean, null);

            /** Saves a name the caller filled along with the tree, and marks the Menu changed either way. */
            $menu->touch();

            activity()
                ->performedOn($menu)
                ->causedBy($by)
                ->event('menu_saved')
                ->withProperties(['items' => $count])
                ->log("Saved the “{$menu->name}” Menu ({$count} links)");
        });
    }

    /**
     * Every item in the shape the builder edits, nested, in order. What
     * `save()` takes back.
     *
     * @return list<array{label: string, type: string, note: ?string, target_id: ?int, anchor: ?string, url: ?string, children: list<mixed>}>
     */
    public static function of(Menu $menu): array
    {
        $byParent = $menu->items()->get()->groupBy(fn (MenuItem $item): int => $item->parent_id ?? 0);

        $build = function (int $parentId) use (&$build, $byParent): array {
            return $byParent->get($parentId, collect())
                ->map(fn (MenuItem $item): array => [
                    'label' => $item->label,
                    'type' => $item->type->value,
                    'note' => $item->note,
                    'target_id' => $item->linkable_id,
                    'anchor' => $item->anchor,
                    'url' => $item->url,
                    'children' => $build($item->id),
                ])
                ->values()
                ->all();
        };

        return $build(0);
    }

    /**
     * @param  list<mixed>  $items
     * @param  list<int>  $pages  the ids of the Pages that exist
     * @param  list<int>  $path  where `$items` sits
     * @return list<CleanItem>
     */
    private static function clean(array $items, array $pages, array $path): array
    {
        $clean = [];

        foreach (array_values($items) as $index => $item) {
            $here = [...$path, $index];

            if (! is_array($item)) {
                throw new MenuTreeRefused($here, null, 'this link cannot be read.');
            }

            $label = is_scalar($item['label'] ?? null) ? trim((string) $item['label']) : '';

            if ($label === '') {
                throw new MenuTreeRefused($here, null, 'a link needs a label.');
            }

            if (mb_strlen($label) > self::MAX_LABEL) {
                throw new MenuTreeRefused($here, $label, 'a label may be at most '.self::MAX_LABEL.' characters.');
            }

            if (count($here) > self::MAX_DEPTH) {
                throw new MenuTreeRefused($here, $label, 'a Menu may be at most '.self::MAX_DEPTH.' levels deep.');
            }

            $type = MenuLinkType::tryFrom(is_scalar($item['type'] ?? null) ? (string) $item['type'] : '');

            if ($type === null) {
                throw new MenuTreeRefused($here, $label, 'choose what this link points at.');
            }

            $note = is_scalar($item['note'] ?? null) && filled($item['note']) ? trim((string) $item['note']) : null;

            if ($note !== null && mb_strlen($note) > self::MAX_NOTE) {
                throw new MenuTreeRefused($here, $label, 'a note may be at most '.self::MAX_NOTE.' characters.');
            }

            $children = $item['children'] ?? [];

            $clean[] = [
                'label' => $label,
                'type' => $type,
                'note' => $note,
                ...self::target($type, $item, $pages, $here, $label),
                'children' => self::clean(is_array($children) ? $children : [], $pages, $here),
            ];
        }

        return $clean;
    }

    /**
     * The one target field this type reads, the others blank.
     *
     * @param  array<string, mixed>  $item
     * @param  list<int>  $pages
     * @param  list<int>  $path
     * @return array{page_id: ?int, anchor: ?string, url: ?string}
     */
    private static function target(MenuLinkType $type, array $item, array $pages, array $path, string $label): array
    {
        $none = ['page_id' => null, 'anchor' => null, 'url' => null];
        $pageId = is_numeric($item['target_id'] ?? null) ? (int) $item['target_id'] : null;
        $anchor = is_scalar($item['anchor'] ?? null) ? trim((string) $item['anchor']) : '';
        $url = is_scalar($item['url'] ?? null) ? trim((string) $item['url']) : '';

        $pageExists = fn (): bool => $pageId !== null && in_array($pageId, $pages, true);

        return match ($type) {
            MenuLinkType::Page => $pageExists()
                ? [...$none, 'page_id' => $pageId]
                : throw new MenuTreeRefused($path, $label, $pageId === null ? 'choose a Page.' : 'that Page no longer exists.'),
            MenuLinkType::Anchor => match (true) {
                preg_match(self::ANCHOR, $anchor) !== 1 => throw new MenuTreeRefused($path, $label, 'an anchor is letters, digits, hyphens and underscores.'),
                $pageId !== null && ! $pageExists() => throw new MenuTreeRefused($path, $label, 'that Page no longer exists.'),
                default => [...$none, 'page_id' => $pageId, 'anchor' => ltrim($anchor, '#')],
            },
            MenuLinkType::Url => preg_match(StorefrontMenus::ALLOWED_URL, $url) === 1 && mb_strlen($url) <= self::MAX_URL
                ? [...$none, 'url' => $url]
                : throw new MenuTreeRefused($path, $label, 'an address starts with / for this site, or https://, tel: or mailto:.'),
            MenuLinkType::Heading => $none,
        };
    }

    /**
     * @param  list<CleanItem>  $items
     */
    private static function insert(Menu $menu, array $items, ?int $parentId): int
    {
        $count = 0;

        foreach ($items as $position => $item) {
            $row = MenuItem::query()->create([
                'menu_id' => $menu->id,
                'parent_id' => $parentId,
                'position' => $position,
                'label' => $item['label'],
                'type' => $item['type'],
                'note' => $item['note'],
                'linkable_type' => $item['page_id'] === null ? null : (new Page)->getMorphClass(),
                'linkable_id' => $item['page_id'],
                'anchor' => $item['anchor'],
                'url' => $item['url'],
            ]);

            /** @var list<CleanItem> $children */
            $children = $item['children'];

            $count += 1 + self::insert($menu, $children, $row->id);
        }

        return $count;
    }

    /**
     * Every Page id the tree names, at any depth, so they are looked up in
     * one query.
     *
     * @param  array<mixed>  $items
     * @return list<int>
     */
    private static function pageIds(array $items): array
    {
        $ids = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (is_numeric($item['target_id'] ?? null)) {
                $ids[] = (int) $item['target_id'];
            }

            if (is_array($item['children'] ?? null)) {
                $ids = [...$ids, ...self::pageIds($item['children'])];
            }
        }

        return array_values(array_unique($ids));
    }
}
