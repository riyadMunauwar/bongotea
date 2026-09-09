<?php

namespace App\Models;

use App\Enums\PageStatus;
use App\Observers\PageObserver;
use App\Support\Checkout\AsCheckoutConfig;
use App\Support\OrderComplete\AsOrderCompleteConfig;
use Database\Factories\PageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A publicly reachable landing page, authored as a complete HTML document and
 * served at its own flat slug.
 */
#[ObservedBy(PageObserver::class)]
#[Fillable(['title', 'slug', 'body', 'status', 'published_at', 'checkout_config', 'order_complete_config'])]
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

            /**
             * Likewise: what the Page says once the Order is placed, seeded
             * with the Config it is created with and backfilled for every
             * Page from before the column.
             */
            'order_complete_config' => AsOrderCompleteConfig::class,
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
     * Whether this Page, as it stands now, asks the Customer which of its
     * Products to buy.
     *
     * What the Config alone can say — the Merchant's switch and their layout,
     * which is `CheckoutConfig::picksProducts()` — and the Page's own Products
     * on top of it: a picker is only a question where there is more than one
     * thing to pick between. A Page down to its last Product — linked that way,
     * or left that way by a soft delete — sells it implicitly rather than
     * putting a box the Customer has to find between them and the order.
     *
     * This is the rendering side of the question, and only that. A Capture
     * arrives from a form that may have been written to disk before any of this
     * was true, so it asks what *that* form drew rather than what this Page
     * would draw today — see `StoreOrderRequest::wasAskedToChoose()`.
     */
    public function offersProductChoice(): bool
    {
        return $this->checkout_config->picksProducts() && $this->products->count() > 1;
    }

    /**
     * The Products this Page's Picker opens ticked, by id.
     *
     * What the Config alone can say — the Merchant's list, gated on the layout
     * that would have to draw the boxes, which is `CheckoutConfig::opensTicked()`
     * — resolved against the Products this Page currently sells. A tick naming
     * a Product it never sold, or one soft-deleted since the Merchant made it,
     * is dropped: a form cannot open ticked against something it cannot offer,
     * and the Merchant's list is kept whole meanwhile for the day the Product
     * comes back.
     *
     * Empty wherever there is no choice to open, so a Page down to its last
     * Product sells it implicitly rather than drawing a tick beside the only
     * thing on offer.
     *
     * A starting position and never an agreement: what an Order costs is
     * settled by what was ticked at Capture. Resolved through the very method
     * that settles it, so a Merchant's ticks and a Customer's are read against
     * the Page in one place and cannot come to mean different things.
     *
     * @return list<int>
     */
    public function defaultSelection(): array
    {
        if (! $this->offersProductChoice()) {
            return [];
        }

        return $this->productsChosen($this->checkout_config->opensTicked())
            ->map(fn (Product $product): int => $product->getKey())
            ->all();
    }

    /**
     * The Products of this Page a Customer's selection names — or everything it
     * sells, where they were never asked to choose.
     *
     * Resolved against the Page in both cases, which is what keeps a selection
     * a list of names rather than a description of the parcel: a tick for a
     * Product this Page does not sell, or no longer sells, adds no line and no
     * charge. The form, the rules and the Capture all read the answer here, so
     * they cannot disagree about what is being bought.
     *
     * @param  list<int>|null  $selection  Product ids the Customer ticked, or null where they were never asked
     * @return Collection<int, Product>
     */
    public function productsChosen(?array $selection): Collection
    {
        if ($selection === null) {
            return $this->products;
        }

        return $this->products
            ->filter(fn (Product $product): bool => in_array($product->getKey(), $selection, true))
            ->values();
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
