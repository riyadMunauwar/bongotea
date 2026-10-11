<?php

namespace App\Models;

use App\Enums\ProductStatus;
use App\Support\Pricing\ProductTiers;
use App\Support\Pricing\Tier;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Something a Customer can order, sold by the Kilogram under one Category and
 * one Brand.
 *
 * Three separate answers about whether a buyer can have it: its status
 * (a draft is seen by nobody), the Available flag (unavailable is for now,
 * and the Product is still shown greyed), and the soft delete, which is
 * "taken out of the catalogue" — for good, while its address keeps
 * answering. Every characteristic is optional, so a Product can be published
 * bare and enriched later.
 *
 * `tiers` are the Product's own Tiers (ADR-0031): `{min, max|null, price}`
 * in minor units, covering every kilogram from one once and never rising in
 * price, refused on save otherwise because the pricing module assumes it.
 * `price` is the first Tier's, kept beside them because tracking and reports
 * read it; the model writes one from the other. `mrp` is what every Saving
 * is measured from, required, and no Tier Price may exceed it.
 * What a Kilogram costs in a Cart is only ever worked out by `PriceBook`.
 */
#[Fillable([
    'name', 'slug', 'category_id', 'brand_id', 'short_description', 'description', 'price', 'mrp',
    'tiers', 'strength', 'liquor_colour', 'aroma', 'cups_per_kilogram', 'best_for', 'not_best_for',
    'brewing_guide', 'business_tips', 'badge', 'search_keywords', 'status', 'is_available',
    'seo_title', 'seo_description', 'seo_image',
])]
class Product extends Model implements HasMedia
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, InteractsWithMedia, SoftDeletes;

    /** The gallery, in order; the first image is the cover. */
    public const GALLERY_COLLECTION = 'gallery';

    /**
     * The disk a Product's gallery is stored on, and the one thing that says so.
     *
     * Named here rather than left to a default because two defaults were
     * agreeing by accident: the panel's upload falls back to the framework's
     * default disk, while the media library's own falls back to `public`. They
     * happened to land on the same disk on this installation, and an
     * environment variable was all that stood between them landing on two.
     * The collection declares it, and the panel's upload reads the
     * collection's declaration, so there is one answer.
     */
    public const IMAGE_DISK = 'local';

    public const STRENGTH_MAX = 5;

    public const BUSINESS_TIPS_MAX = 3;

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'mrp' => 'integer',
            'tiers' => 'array',
            'strength' => 'integer',
            'best_for' => 'array',
            'business_tips' => 'array',
            'status' => ProductStatus::class,
            'first_published_at' => 'datetime',
            'is_available' => 'boolean',
        ];
    }

    /**
     * The slug is claimed for good the first time the Product goes live,
     * because a link to it may already be in a post or a chat. Renaming stays
     * open; moving the address does not, and a draft never yet published can
     * still have its slug corrected.
     */
    protected static function booted(): void
    {
        static::saving(function (Product $product): void {
            if ($product->isDirty('slug') && $product->isSlugLocked()) {
                throw new LogicException("Product {$product->getKey()} has gone live; its slug can no longer change.");
            }

            if ($product->isPublished() && $product->first_published_at === null) {
                $product->first_published_at = now();
            }

            $product->settlePrice();
            $product->guardMrp();
        });
    }

    /**
     * Keeps `price` the first Tier's. Tiers given decide the price; a price
     * given alone is a flat Product's single Tier, and is refused over a
     * ladder of Tiers it would silently flatten.
     */
    private function settlePrice(): void
    {
        $tiers = $this->tiers;

        if ($this->isDirty('tiers') || $tiers === null) {
            $tiers ??= ProductTiers::flat((int) $this->price);
            $problem = ProductTiers::problem($tiers);

            if ($problem !== null) {
                throw new LogicException("Product {$this->getKey()}'s Tiers cannot be saved: {$problem}");
            }

            $this->tiers = array_map(fn (Tier $tier): array => $tier->toArray(), ProductTiers::from($tiers));
            $this->price = $this->tiers[0]['price'];

            return;
        }

        if ($this->isDirty('price')) {
            if (count($tiers) > 1) {
                throw new LogicException("Product {$this->getKey()} has a ladder of Tiers; set its Tiers, not its price.");
            }

            $this->tiers = ProductTiers::flat((int) $this->price);
        }
    }

    /**
     * Refuses a Product with no MRP, or with a Tier Price above it, because
     * every Saving is measured from it and nothing is sold above it.
     */
    private function guardMrp(): void
    {
        $problem = ProductTiers::mrpProblem($this->tiers, $this->mrp);

        if ($problem !== null) {
            throw new LogicException("Product {$this->getKey()} cannot be saved: {$problem}");
        }
    }

    /**
     * The Product's Tiers as the pricing module reads them.
     *
     * @return non-empty-list<Tier>
     */
    public function priceTiers(): array
    {
        return ProductTiers::from($this->tiers ?? ProductTiers::flat((int) $this->price));
    }

    public function isSlugLocked(): bool
    {
        return $this->getOriginal('first_published_at') !== null;
    }

    public function isPublished(): bool
    {
        return $this->status === ProductStatus::Published;
    }

    /**
     * @param  Builder<Product>  $query
     */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('status', ProductStatus::Published);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * Everything that ever happened to this Product's stock.
     *
     * The Product carries no quantity column and never will: On Hand is the
     * sum of these rows and is asked for through `StockLedger` (ADR-0021).
     *
     * Unordered on purpose. The screen that draws these sorts them itself,
     * and an `orderBy` baked in here would ride into the aggregate
     * `withOnHand()` builds — an `ORDER BY` inside a summing subquery, which
     * SQLite tolerates and MySQL under `ONLY_FULL_GROUP_BY` does not.
     *
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Every Order Line ever written for this Product.
     *
     * A reference and never a figure: what was sold, at what price, is the
     * line's own snapshot (`OrderLine`), and nothing here reads back through
     * it to price anything. It exists so that Committed — what is on
     * Confirmed Orders nobody has sent yet — can be summed into the one query
     * a list of Products already runs (`CommittedStock`).
     *
     * @return HasMany<OrderLine, $this>
     */
    public function orderLines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::GALLERY_COLLECTION)
            ->useDisk(self::IMAGE_DISK);
    }

    /** The gallery's first image, which is what a card and a shared link show. */
    public function coverUrl(): ?string
    {
        return $this->getFirstMedia(self::GALLERY_COLLECTION)?->getFullUrl();
    }
}
