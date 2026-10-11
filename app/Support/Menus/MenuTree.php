<?php

namespace App\Support\Menus;

use App\Enums\MenuLinkType;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use App\Support\Storefront\StorefrontMenus;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The one way a Menu's items are written: a whole nested tree, validated as
 * a whole and then swapped in for the old one.
 *
 * The builder holds the tree in the browser and submits all of it on Save,
 * so this is the guard and the builder's own depth limit is a convenience.
 * A tree is refused — pointing at the first offending item, and writing
 * nothing — when it is deeper than three levels, when an item's target does
 * not fit its type, when a Page, Category or Product it names does not
 * exist, when a URL is not one the storefront would draw, or when an All
 * Categories item holds links of its own or sits where its Categories would
 * be a fourth level. Otherwise the old items are deleted
 * and the new ones inserted inside one transaction, and one activity entry
 * says the Menu was saved: the history reads as Saves, not as every drag.
 *
 * @phpstan-type SubmittedItem array{label?: mixed, type?: mixed, note?: mixed, target_id?: mixed, anchor?: mixed, url?: mixed, children?: mixed}
 * @phpstan-type CleanItem array{label: string, type: MenuLinkType, note: ?string, linkable_type: ?string, linkable_id: ?int, anchor: ?string, url: ?string, children: list<mixed>}
 * @phpstan-type Existing array<class-string<Model>, list<int>>
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
        $clean = self::clean($tree, self::existing(self::targetIds($tree)), []);

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
     * @param  Existing  $existing  which of the ids the tree names exist, by model
     * @param  list<int>  $path  where `$items` sits
     * @return list<CleanItem>
     */
    private static function clean(array $items, array $existing, array $path): array
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

            $children = is_array($item['children'] ?? null) ? $item['children'] : [];

            if ($type === MenuLinkType::AllCategories && $children !== []) {
                throw new MenuTreeRefused($here, $label, 'All Categories lists the Categories itself; move the links beneath it elsewhere.');
            }

            if ($type === MenuLinkType::AllCategories && count($here) >= self::MAX_DEPTH) {
                throw new MenuTreeRefused($here, $label, 'All Categories needs the level beneath it for its Categories, so it cannot sit on level '.self::MAX_DEPTH.'.');
            }

            $clean[] = [
                'label' => $label,
                'type' => $type,
                'note' => $note,
                ...self::target($type, $item, $existing, $here, $label),
                'children' => self::clean($children, $existing, $here),
            ];
        }

        return $clean;
    }

    /**
     * The one target field this type reads, the others blank.
     *
     * @param  array<string, mixed>  $item
     * @param  Existing  $existing
     * @param  list<int>  $path
     * @return array{linkable_type: ?string, linkable_id: ?int, anchor: ?string, url: ?string}
     */
    private static function target(MenuLinkType $type, array $item, array $existing, array $path, string $label): array
    {
        $none = ['linkable_type' => null, 'linkable_id' => null, 'anchor' => null, 'url' => null];
        $targetId = is_numeric($item['target_id'] ?? null) ? (int) $item['target_id'] : null;
        $anchor = is_scalar($item['anchor'] ?? null) ? trim((string) $item['anchor']) : '';
        $url = is_scalar($item['url'] ?? null) ? trim((string) $item['url']) : '';

        $exists = fn (string $model): bool => $targetId !== null && in_array($targetId, $existing[$model] ?? [], true);
        $linking = fn (string $model): array => [...$none, 'linkable_type' => (new $model)->getMorphClass(), 'linkable_id' => $targetId];

        return match ($type) {
            MenuLinkType::Page, MenuLinkType::Category, MenuLinkType::Product => $exists($type->linkable())
                ? $linking($type->linkable())
                : throw new MenuTreeRefused($path, $label, $targetId === null ? 'choose a '.$type->getLabel().'.' : 'that '.$type->getLabel().' no longer exists.'),
            MenuLinkType::Anchor => match (true) {
                preg_match(self::ANCHOR, $anchor) !== 1 => throw new MenuTreeRefused($path, $label, 'an anchor is letters, digits, hyphens and underscores.'),
                $targetId !== null && ! $exists(Page::class) => throw new MenuTreeRefused($path, $label, 'that Page no longer exists.'),
                default => [...($targetId === null ? $none : $linking(Page::class)), 'anchor' => ltrim($anchor, '#')],
            },
            MenuLinkType::Url => preg_match(StorefrontMenus::ALLOWED_URL, $url) === 1 && mb_strlen($url) <= self::MAX_URL
                ? [...$none, 'url' => $url]
                : throw new MenuTreeRefused($path, $label, 'an address starts with / for this site, or https://, tel: or mailto:.'),
            MenuLinkType::Heading, MenuLinkType::AllCategories => $none,
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
                'linkable_type' => $item['linkable_type'],
                'linkable_id' => $item['linkable_id'],
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
     * Which of the ids the tree names exist: one query per model the tree
     * names any of, whatever the tree's size. A Product taken out of the
     * catalogue is not among them.
     *
     * @param  Existing  $ids  the ids the tree names, by model
     * @return Existing
     */
    private static function existing(array $ids): array
    {
        $existing = [];

        foreach ($ids as $model => $modelIds) {
            $existing[$model] = $model::query()->whereKey($modelIds)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        }

        return $existing;
    }

    /**
     * Every target id the tree names, at any depth, by the model its type
     * links (an Anchor's optional target is a Page), so they are looked up
     * together.
     *
     * @param  array<mixed>  $items
     * @return Existing
     */
    private static function targetIds(array $items): array
    {
        $ids = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $type = MenuLinkType::tryFrom(is_scalar($item['type'] ?? null) ? (string) $item['type'] : '');
            $model = $type === MenuLinkType::Anchor ? Page::class : $type?->linkable();

            if ($model !== null && is_numeric($item['target_id'] ?? null)) {
                $ids[$model][] = (int) $item['target_id'];
            }

            if (is_array($item['children'] ?? null)) {
                $ids = array_merge_recursive($ids, self::targetIds($item['children']));
            }
        }

        return array_map(fn (array $modelIds): array => array_values(array_unique($modelIds)), $ids);
    }
}
