<?php

namespace App\Models;

use App\Enums\PageStatus;
use App\Enums\SystemPage;
use App\Support\Pages\CartPlacements;
use Database\Factories\PageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A storefront Page, known by its slug: Blocks arranged in the editor, what
 * it says to search engines, and whether it is live.
 *
 * A System Page carries a `system_role` and is found by it. The model, not
 * only the form, refuses to delete one or to change any Page's role, so no
 * path — a bulk action, a console command — can leave the store without its
 * Home. A slug the store's own routes answer at is refused here too.
 *
 * @property list<array{type: string, data: array<string, mixed>}>|null $blocks
 */
#[Fillable(['title', 'slug', 'status', 'blocks', 'system_role', 'seo_title', 'seo_description', 'seo_image'])]
class Page extends Model
{
    /** @use HasFactory<PageFactory> */
    use HasFactory;

    /**
     * A slug, and a Block's anchor: lowercase words joined by single
     * hyphens. Unanchored, so a route constraint can use it as it is.
     */
    public const SLUG = '[a-z0-9]+(?:-[a-z0-9]+)*';

    /**
     * The first path segments the store answers at itself — Products,
     * Categories, search, the checkout, Orders, payments, the admin — and
     * that a Page can therefore never claim.
     */
    public const RESERVED_SLUGS = [
        'tea', 'category', 'search', 'checkout', 'order', 'pay', 'admin',
        'courier', 'tracking', 'storage', 'build', 'livewire', 'filament',
        'sitemap', 'robots', 'up', 'api',
        'login', 'logout', 'account', 'cart', 'sign-in', 'sign-out', 'enquiries',
    ];

    protected function casts(): array
    {
        return [
            'status' => PageStatus::class,
            'system_role' => SystemPage::class,
            'blocks' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Page $page): void {
            if ($page->isDirty('slug') && in_array($page->slug, self::RESERVED_SLUGS, true)) {
                throw new LogicException("The slug \"{$page->slug}\" belongs to the store's own routes.");
            }

            if ($page->exists && $page->isDirty('system_role')) {
                throw new LogicException("Page {$page->getKey()}'s system role can never change.");
            }

            if ($page->isDirty('blocks')) {
                $page->refuseCartPlacedElsewhere();
            }
        });

        static::deleting(function (Page $page): void {
            if ($page->isSystemPage()) {
                throw new LogicException("Page {$page->getKey()} is the {$page->system_role->getLabel()} System Page and cannot be deleted.");
            }
        });
    }

    /**
     * A Cart stands on one Page at most (ADR-0038), so every link to it leads
     * to one place: a Page drawing a Cart twice, or one another Page already
     * draws, is refused, naming that Page.
     */
    private function refuseCartPlacedElsewhere(): void
    {
        $cartIds = CartPlacements::cartIdsIn($this->blocks);

        if (count($cartIds) !== count(array_unique($cartIds))) {
            throw new LogicException("Page \"{$this->title}\" draws the same Cart twice.");
        }

        $elsewhere = CartPlacements::pages($this->getKey());

        foreach ($cartIds as $cartId) {
            if ($other = $elsewhere->get($cartId)) {
                throw new LogicException("Cart {$cartId} already stands on the Page \"{$other->title}\".");
            }
        }
    }

    public function isPublished(): bool
    {
        return $this->status === PageStatus::Published;
    }

    public function isSystemPage(): bool
    {
        return $this->getOriginal('system_role') !== null;
    }

    public function isHome(): bool
    {
        return $this->system_role === SystemPage::Home;
    }

    /**
     * Where the Page is served: Home at the bare domain, every other Page at
     * its slug.
     */
    public function path(): string
    {
        return $this->isHome() ? '/' : '/'.$this->slug;
    }

    /**
     * @param  Builder<Page>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', PageStatus::Published);
    }

    /**
     * @param  Builder<Page>  $query
     */
    public function scopeSystem(Builder $query, SystemPage $role): void
    {
        $query->where('system_role', $role);
    }
}
