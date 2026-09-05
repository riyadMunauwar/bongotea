<?php

namespace App\Models;

use App\Enums\PageStatus;
use App\Observers\PageObserver;
use App\Support\Checkout\AsCheckoutConfig;
use Database\Factories\PageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A publicly reachable landing page, authored as a complete HTML document and
 * served at its own flat slug.
 */
#[ObservedBy(PageObserver::class)]
#[Fillable(['title', 'slug', 'body', 'status', 'published_at', 'checkout_config'])]
class Page extends Model
{
    /** @use HasFactory<PageFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => PageStatus::class,
            'published_at' => 'datetime',

            /**
             * Never null and never partial: the column is non-nullable and a
             * Page is seeded with a complete Config as it is created.
             */
            'checkout_config' => AsCheckoutConfig::class,
        ];
    }

    /**
     * Everything this Page still sells, in the order the admin arranged them.
     *
     * A soft-deleted Product falls out of here on its own, which is what makes
     * a merchandising slip cost one item rather than a live campaign. The
     * ordering is for the admin and the eye only — nothing resolves a Product
     * by the position it sits at, because a reorder would then retarget a Page
     * that is spending money, silently.
     *
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)
            ->withPivot('position')
            ->orderByPivot('position');
    }

    /**
     * The links whose Product has since been soft-deleted.
     *
     * A Checkout drops these and keeps selling the rest, which is the right
     * outcome and a silent one — so the Pages list reads this to say out loud
     * that a published Page now sells fewer Products than it was linked with.
     *
     * @return BelongsToMany<Product, $this>
     */
    public function deletedProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->onlyTrashed();
    }

    /**
     * How many Products this Page has lost since they were linked.
     *
     * Prefers a count the query already loaded, because the one screen that
     * asks this asks it for every row it shows.
     */
    public function lostProductCount(): int
    {
        return (int) ($this->deleted_products_count ?? $this->deletedProducts()->count());
    }

    /**
     * Whether this Page is quietly selling less than it was linked with.
     *
     * Published only. A draft is not in front of anybody and not spending
     * anything, so there is nothing there to warn about.
     */
    public function hasLostProducts(): bool
    {
        return $this->isPublished() && $this->lostProductCount() > 0;
    }

    public function isPublished(): bool
    {
        return $this->status === PageStatus::Published;
    }

    /**
     * @param  Builder<Page>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', PageStatus::Published);
    }
}
