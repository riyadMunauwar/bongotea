<?php

namespace App\Support\Storefront;

use App\Enums\MenuLinkType;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\Product;
use App\Support\Menus\MenuTree;
use App\Support\Settings\FooterSettings;
use App\Support\Settings\HeaderSettings;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The Menus as the storefront draws them: every stored link turned into an
 * address, and every link that leads nowhere left out.
 *
 * Each place draws the Menu its settings group assigns: the header up to
 * three levels, the footer's columns and bottom line one level. Every
 * assigned Menu is read in one query and built into its tree in memory. A
 * Page, Category or Product target is looked up by id, so the address
 * follows a renamed slug, and a link whose target the storefront would not
 * show — an unpublished Page or Product, a Product taken out of the
 * catalogue, anything deleted — disappears with anything beneath it instead
 * of linking to a 404. An All Categories item is given every Category as it
 * stands as its children, at the moment it is drawn. A Heading or All
 * Categories item left with no children disappears too, which at one level
 * deep is every one of them. A typed URL is
 * kept only when it is a path or an http(s), tel: or mailto: address, so a
 * hand-edited row cannot put `javascript:` into the storefront.
 *
 * @phpstan-type ResolvedLink array{label: string, href: string, external: bool, note: ?string}
 * @phpstan-type ResolvedNode array{label: string, href: ?string, external: bool, note: ?string, children: list<mixed>}
 */
class StorefrontMenus
{
    /** The addresses a typed URL may be: a path here, or a web, phone or mail address. `MenuTree` validates with it too. */
    public const ALLOWED_URL = '#^(/|https?://|tel:|mailto:)#i';

    /**
     * Every assigned Menu's items by Menu id, then by parent id (0 for the
     * top level).
     *
     * @var Collection<int, Collection<int, Collection<int, MenuItem>>>
     */
    private Collection $items;

    /**
     * The paths of the Pages and Products any link names and the storefront
     * shows, by morph class and then id.
     *
     * @var array<string, array<int, string>>
     */
    private array $paths;

    public function __construct(private HeaderSettings $header, private FooterSettings $footer, private string $host)
    {
        $menuIds = array_values(array_unique(array_filter([$header->menuId, ...$footer->menuIds()])));

        $this->items = $menuIds === []
            ? collect()
            : MenuItem::query()
                ->whereIn('menu_id', $menuIds)
                ->orderBy('position')
                ->orderBy('id')
                ->get()
                ->groupBy('menu_id')
                ->map(fn (Collection $items): Collection => $items->groupBy(fn (MenuItem $item): int => $item->parent_id ?? 0));

        $this->paths = $this->publishedPaths();
    }

    /**
     * @return list<ResolvedNode>
     */
    public function header(): array
    {
        return $this->branch($this->header->menuId, 0, 1, MenuTree::MAX_DEPTH);
    }

    /**
     * @return array{blurb: string, columns: list<array{heading: string, links: list<ResolvedLink>}>, bottomLinks: list<ResolvedLink>, paymentBanner: ?string}
     */
    public function footer(): array
    {
        $columns = [];

        foreach ($this->footer->columns as $column) {
            $links = $this->links($column['menuId']);

            if ($links !== []) {
                $columns[] = ['heading' => $column['heading'], 'links' => $links];
            }
        }

        return [
            'blurb' => $this->footer->blurb,
            'columns' => $columns,
            'bottomLinks' => $this->links($this->footer->bottomMenuId),
            'paymentBanner' => $this->footer->paymentBannerUrl(),
        ];
    }

    /**
     * A Menu drawn one level deep, as plain links.
     *
     * @return list<ResolvedLink>
     */
    private function links(?int $menuId): array
    {
        return array_map(
            fn (array $node): array => ['label' => $node['label'], 'href' => $node['href'], 'external' => $node['external'], 'note' => $node['note']],
            $this->branch($menuId, 0, 1, 1),
        );
    }

    /**
     * The drawable children of one item of a Menu, in order.
     *
     * @return list<ResolvedNode>
     */
    private function branch(?int $menuId, int $parentId, int $depth, int $maxDepth): array
    {
        if ($menuId === null || $depth > $maxDepth) {
            return [];
        }

        $nodes = [];

        foreach ($this->items->get($menuId, collect())->get($parentId, collect()) as $item) {
            $children = $this->branch($menuId, $item->id, $depth + 1, $maxDepth);

            if ($item->type === MenuLinkType::AllCategories) {
                $children = $depth < $maxDepth ? $this->everyCategory() : [];
            }

            if ($item->type === MenuLinkType::Heading || $item->type === MenuLinkType::AllCategories) {
                if ($children !== []) {
                    $nodes[] = ['label' => $item->label, 'href' => null, 'external' => false, 'note' => $item->note, 'children' => $children];
                }

                continue;
            }

            $href = $this->href($item);

            if ($href !== null) {
                $nodes[] = ['label' => $item->label, 'href' => $href, 'external' => $this->isExternal($href), 'note' => $item->note, 'children' => $children];
            }
        }

        return $nodes;
    }

    private function href(MenuItem $item): ?string
    {
        return match ($item->type) {
            MenuLinkType::Page => $this->pagePath($this->idOf($item, Page::class)),
            MenuLinkType::Category => $this->categoryPath($this->idOf($item, Category::class)),
            MenuLinkType::Product => $this->productPath($this->idOf($item, Product::class)),
            MenuLinkType::Anchor => $this->anchorPath($this->idOf($item, Page::class), $item->anchor),
            MenuLinkType::Url => filled($item->url) && preg_match(self::ALLOWED_URL, $item->url) ? $item->url : null,
            MenuLinkType::Heading, MenuLinkType::AllCategories => null,
        };
    }

    private function pagePath(?int $pageId): ?string
    {
        return $pageId === null ? null : $this->paths[(new Page)->getMorphClass()][$pageId] ?? null;
    }

    private function productPath(?int $productId): ?string
    {
        return $productId === null ? null : $this->paths[(new Product)->getMorphClass()][$productId] ?? null;
    }

    private function categoryPath(?int $categoryId): ?string
    {
        $category = $categoryId === null ? null : $this->categories()->firstWhere('id', $categoryId);

        return $category === null ? null : CatalogueProps::categoryPath($category);
    }

    /**
     * An All Categories item's children: every Category as it stands, in the
     * Merchant's order.
     *
     * @return list<ResolvedNode>
     */
    private function everyCategory(): array
    {
        return $this->categories()
            ->map(fn (Category $category): array => ['label' => $category->name, 'href' => CatalogueProps::categoryPath($category), 'external' => false, 'note' => null, 'children' => []])
            ->values()
            ->all();
    }

    /**
     * The Categories the storefront shows, read through the request's
     * Catalogue so a Page's Blocks and the Menus share one query.
     *
     * @return EloquentCollection<int, Category>
     */
    private function categories(): EloquentCollection
    {
        return app(Catalogue::class)->categories();
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
     * The id of the item's target when it is of the given model, else null.
     *
     * @param  class-string<Model>  $model
     */
    private function idOf(MenuItem $item, string $model): ?int
    {
        return $item->linkable_type === (new $model)->getMorphClass() ? $item->linkable_id : null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function publishedPaths(): array
    {
        $items = $this->items->flatten(2);
        $ids = fn (string $model): array => $items->map(fn (MenuItem $item): ?int => $this->idOf($item, $model))->filter()->unique()->values()->all();

        $pageIds = $ids(Page::class);
        $productIds = $ids(Product::class);

        return [
            (new Page)->getMorphClass() => $pageIds === [] ? [] : Page::query()->published()->whereKey($pageIds)->get(['id', 'slug', 'system_role'])
                ->mapWithKeys(fn (Page $page): array => [$page->id => $page->path()])
                ->all(),
            (new Product)->getMorphClass() => $productIds === [] ? [] : Product::query()->published()->whereKey($productIds)->get(['id', 'slug'])
                ->mapWithKeys(fn (Product $product): array => [$product->id => CatalogueProps::productPath($product)])
                ->all(),
        ];
    }
}
