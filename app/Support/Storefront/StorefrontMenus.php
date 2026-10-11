<?php

namespace App\Support\Storefront;

use App\Enums\MenuLinkType;
use App\Models\MenuItem;
use App\Models\Page;
use App\Support\Menus\MenuTree;
use App\Support\Settings\HeaderSettings;
use App\Support\Settings\MenuSettings;
use Illuminate\Support\Collection;

/**
 * The Menus as the storefront draws them: every stored link turned into an
 * address, and every link that leads nowhere left out.
 *
 * The header is the Menu `HeaderSettings` assigns, read in one query and
 * built into its tree in memory, up to three levels. A Page target is looked
 * up by id, so the address follows a renamed slug, and a link whose Page is
 * unpublished or gone disappears — with anything beneath it — instead of
 * linking to a 404. A Heading left with no children disappears too. A typed
 * URL is kept only when it is a path or an http(s), tel: or mailto: address,
 * so a hand-edited row cannot put `javascript:` into the header.
 *
 * The footer is still read from `MenuSettings` until it becomes a place of
 * its own.
 *
 * @phpstan-import-type MenuLink from MenuSettings
 *
 * @phpstan-type ResolvedLink array{label: string, href: string, external: bool, note: ?string}
 * @phpstan-type ResolvedNode array{label: string, href: ?string, external: bool, note: ?string, children: list<mixed>}
 */
class StorefrontMenus
{
    /** The addresses a typed URL may be: a path here, or a web, phone or mail address. `MenuTree` validates with it too. */
    public const ALLOWED_URL = '#^(/|https?://|tel:|mailto:)#i';

    /**
     * The header Menu's items by parent id, 0 for the top level.
     *
     * @var Collection<int, Collection<int, MenuItem>>
     */
    private Collection $headerItems;

    /**
     * Published Pages' paths by id, for every Page any link names.
     *
     * @var Collection<int, string>
     */
    private Collection $paths;

    public function __construct(private MenuSettings $menus, HeaderSettings $header, private string $host)
    {
        $this->headerItems = $header->menuId === null
            ? collect()
            : MenuItem::query()
                ->where('menu_id', $header->menuId)
                ->orderBy('position')
                ->orderBy('id')
                ->get()
                ->groupBy(fn (MenuItem $item): int => $item->parent_id ?? 0);

        $this->paths = $this->publishedPaths();
    }

    /**
     * @return list<ResolvedNode>
     */
    public function header(): array
    {
        return $this->branch(0, 1);
    }

    /**
     * @return array{blurb: string, columns: list<array{heading: string, links: list<ResolvedLink>}>, bottomLinks: list<ResolvedLink>}
     */
    public function footer(): array
    {
        $columns = [];

        foreach ($this->menus->footerColumns as $column) {
            $links = $this->resolveAll($column['links']);

            if ($links !== [] || filled($column['heading'])) {
                $columns[] = ['heading' => $column['heading'], 'links' => $links];
            }
        }

        return [
            'blurb' => $this->menus->footerBlurb,
            'columns' => $columns,
            'bottomLinks' => $this->resolveAll($this->menus->footerBottomLinks),
        ];
    }

    /**
     * The drawable children of one header item, in order.
     *
     * @return list<ResolvedNode>
     */
    private function branch(int $parentId, int $depth): array
    {
        if ($depth > MenuTree::MAX_DEPTH) {
            return [];
        }

        $nodes = [];

        foreach ($this->headerItems->get($parentId, collect()) as $item) {
            $children = $this->branch($item->id, $depth + 1);

            if ($item->type === MenuLinkType::Heading) {
                if ($children !== []) {
                    $nodes[] = ['label' => $item->label, 'href' => null, 'external' => false, 'note' => $item->note, 'children' => $children];
                }

                continue;
            }

            $href = $this->href($item->type, $this->pageIdOf($item), $item->anchor, $item->url);

            if ($href !== null) {
                $nodes[] = ['label' => $item->label, 'href' => $href, 'external' => $this->isExternal($href), 'note' => $item->note, 'children' => $children];
            }
        }

        return $nodes;
    }

    /**
     * @param  list<MenuLink>  $links
     * @return list<ResolvedLink>
     */
    private function resolveAll(array $links): array
    {
        $resolved = [];

        foreach ($links as $link) {
            $type = MenuLinkType::tryFrom($link['type']);
            $href = $type === null ? null : $this->href($type, $link['page_id'], $link['anchor'], $link['url']);

            if ($href !== null) {
                $resolved[] = ['label' => $link['label'], 'href' => $href, 'external' => $this->isExternal($href), 'note' => $link['note'] ?? null];
            }
        }

        return $resolved;
    }

    private function href(MenuLinkType $type, ?int $pageId, ?string $anchor, ?string $url): ?string
    {
        return match ($type) {
            MenuLinkType::Page => $this->pagePath($pageId),
            MenuLinkType::Anchor => $this->anchorPath($pageId, $anchor),
            MenuLinkType::Url => filled($url) && preg_match(self::ALLOWED_URL, $url) ? $url : null,
            MenuLinkType::Heading => null,
        };
    }

    private function pagePath(?int $pageId): ?string
    {
        return $pageId === null ? null : $this->paths->get($pageId);
    }

    /**
     * A section on the named Page, or on Home when no Page is named.
     */
    private function anchorPath(?int $pageId, ?string $anchor): ?string
    {
        if (blank($anchor)) {
            return null;
        }

        $path = $pageId === null ? '/' : $this->pagePath($pageId);

        return $path === null ? null : $path.'#'.$anchor;
    }

    private function isExternal(string $href): bool
    {
        $host = parse_url($href, PHP_URL_HOST);

        return preg_match('#^https?://#i', $href) === 1 && is_string($host) && strcasecmp($host, $this->host) !== 0;
    }

    private function pageIdOf(MenuItem $item): ?int
    {
        return $item->linkable_type === (new Page)->getMorphClass() ? $item->linkable_id : null;
    }

    /**
     * @return Collection<int, string>
     */
    private function publishedPaths(): Collection
    {
        $entries = $this->menus->footerBottomLinks;

        foreach ($this->menus->footerColumns as $column) {
            $entries = [...$entries, ...$column['links']];
        }

        $ids = [
            ...array_column($entries, 'page_id'),
            ...$this->headerItems->flatten(1)->map($this->pageIdOf(...))->all(),
        ];

        $ids = array_values(array_unique(array_filter($ids)));

        if ($ids === []) {
            return collect();
        }

        return Page::query()->published()->whereKey($ids)->get(['id', 'slug', 'system_role'])
            ->mapWithKeys(fn (Page $page): array => [$page->id => $page->path()]);
    }
}
