<?php

namespace App\Support\Storefront;

use App\Enums\MenuLinkType;
use App\Models\Page;
use App\Support\Settings\MenuSettings;
use Illuminate\Support\Collection;

/**
 * The Menus as the storefront draws them: every stored entry turned into an
 * address, and every entry that leads nowhere left out.
 *
 * A Page target is looked up by id, so the address follows a renamed slug,
 * and an entry whose Page is unpublished or gone disappears instead of
 * linking to a 404. A Dropdown left with no children disappears too. A typed
 * URL is kept only when it is a path or an http(s), tel: or mailto: address,
 * so a hand-edited row cannot put `javascript:` into the header.
 *
 * @phpstan-import-type MenuLink from MenuSettings
 * @phpstan-import-type HeaderItem from MenuSettings
 *
 * @phpstan-type ResolvedLink array{label: string, href: string, external: bool, note: ?string}
 * @phpstan-type ResolvedHeaderItem array{label: string, href: ?string, external: bool, children: list<ResolvedLink>}
 */
class StorefrontMenus
{
    /** The addresses a typed URL may be: a path here, or a web, phone or mail address. The Menus page validates with it too. */
    public const ALLOWED_URL = '#^(/|https?://|tel:|mailto:)#i';

    /**
     * Published Pages' slugs by id, for every Page any entry names.
     *
     * @var Collection<int, string>
     */
    private Collection $slugs;

    public function __construct(private MenuSettings $menus, private string $host)
    {
        $this->slugs = $this->publishedSlugs();
    }

    /**
     * @return list<ResolvedHeaderItem>
     */
    public function header(): array
    {
        $items = [];

        foreach ($this->menus->header as $item) {
            if ($item['type'] === MenuLinkType::Dropdown->value) {
                $children = $this->resolveAll($item['children']);

                if ($children !== []) {
                    $items[] = ['label' => $item['label'], 'href' => null, 'external' => false, 'children' => $children];
                }

                continue;
            }

            $link = $this->resolve($item);

            if ($link !== null) {
                $items[] = ['label' => $link['label'], 'href' => $link['href'], 'external' => $link['external'], 'children' => []];
            }
        }

        return $items;
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
     * @param  list<MenuLink>  $links
     * @return list<ResolvedLink>
     */
    private function resolveAll(array $links): array
    {
        return array_values(array_filter(array_map($this->resolve(...), $links)));
    }

    /**
     * @param  MenuLink|HeaderItem  $link
     * @return ResolvedLink|null
     */
    private function resolve(array $link): ?array
    {
        $href = match (MenuLinkType::tryFrom($link['type'])) {
            MenuLinkType::Page => $this->pagePath($link['page_id']),
            MenuLinkType::Anchor => $this->anchorPath($link['page_id'], $link['anchor']),
            MenuLinkType::Url => filled($link['url']) && preg_match(self::ALLOWED_URL, $link['url']) ? $link['url'] : null,
            default => null,
        };

        if ($href === null) {
            return null;
        }

        return [
            'label' => $link['label'],
            'href' => $href,
            'external' => $this->isExternal($href),
            'note' => $link['note'] ?? null,
        ];
    }

    private function pagePath(?int $pageId): ?string
    {
        $slug = $pageId === null ? null : $this->slugs->get($pageId);

        return $slug === null ? null : '/'.$slug;
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

    /**
     * @return Collection<int, string>
     */
    private function publishedSlugs(): Collection
    {
        $entries = [...$this->menus->header, ...$this->menus->footerBottomLinks];

        foreach ($this->menus->header as $item) {
            $entries = [...$entries, ...$item['children']];
        }

        foreach ($this->menus->footerColumns as $column) {
            $entries = [...$entries, ...$column['links']];
        }

        $ids = array_values(array_unique(array_filter(array_column($entries, 'page_id'))));

        if ($ids === []) {
            return collect();
        }

        return Page::query()->published()->whereKey($ids)->pluck('slug', 'id');
    }
}
