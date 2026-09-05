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
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)
            ->withPivot('position')
            ->orderByPivot('position');
    }

    /**
     * The single Product this Page sells, or null when the link is missing or
     * its Product has been soft-deleted.
     *
     * Never falls back to "the first of several": a Page with two linked
     * Products cannot be published (see PageBodyValidator), so more than one
     * here means the data changed underneath a published Page and the honest
     * answer is that nothing is unambiguously for sale.
     */
    public function soleProduct(): ?Product
    {
        $products = $this->products;

        return $products->count() === 1 ? $products->first() : null;
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
