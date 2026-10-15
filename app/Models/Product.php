<?php

namespace App\Models;

use App\Enums\OrderKind;
use App\Enums\ProductStatus;
use App\Enums\Strength;
use App\Support\Orders\OrderLimit;
use App\Support\Pricing\ProductTiers;
use App\Support\Pricing\Tier;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
 * answering. Every Characteristic is optional, so a Product can be published
 * bare and enriched later: ten fixed ones in columns of their own, and
 * `custom_characteristics`, the Merchant's own `{label, value}` rows for this
 * Product alone, drawn after them. `storage_tips` are the Product's own
 * `{heading, explanation}` rows on keeping its tea, which a new Product's form
 * starts with the common four of.
 *
 * `tiers` are the Product's own Tiers (ADR-0031): `{min, max|null, price}`
 * in minor units, covering every kilogram from one once and never rising in
 * price, refused on save otherwise because the pricing module assumes it.
 * `price` is the first Tier's, kept beside them because tracking and reports
 * read it; the model writes one from the other. `mrp` is what every Saving
 * is measured from, required, and no Tier Price may exceed it.
 * What a Kilogram costs in a Cart is only ever worked out by `PriceBook`.
 *
 * Two switches say how a Product is ever sold (ADR-0036): `sold_bulk`
 * (পাইকারি, by the Kilogram at its Tiers) and `sold_retail` (খুচরা, in its
 * Packs at their Pack Prices), at least one always on. Only a Product sold
 * পাইকারি has Tiers, a `price` and an `mrp`; the model clears all three from
 * one sold only খুচরা. Which ways it is sold is not whether it is Available:
 * that is for now, this is for good.
 *
 * `default_bulk_pack_id` is the পাইকারি Pack a bulk buyer starts from, always
 * one of this Product's Packs ticked পাইকারি, and cleared with the Tiers from
 * a Product no longer sold পাইকারি. That a Product sold পাইকারি has one is the
 * form's rule, as every rule across a Product and its Packs is.
 */
#[Fillable([
    'name', 'slug', 'category_id', 'brand_id', 'short_description', 'description', 'price', 'mrp',
    'tiers', 'strength', 'tea_type', 'grade', 'particle_size', 'liquor_colour', 'liquor_body', 'aroma', 'infusion',
    'cups_per_kilogram', 'package_weight', 'custom_characteristics', 'best_for', 'not_best_for',
    'brewing_steps', 'business_tips', 'storage_tips', 'badge', 'search_keywords', 'status', 'is_available',
    'sold_bulk', 'sold_retail', 'default_bulk_pack_id', 'bulk_min_kilograms', 'bulk_max_kilograms',
    'retail_min_grams', 'retail_max_grams', 'seo_title', 'seo_description', 'seo_image',
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

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'mrp' => 'integer',
            'tiers' => 'array',
            'strength' => Strength::class,
            'custom_characteristics' => 'array',
            'best_for' => 'array',
            'brewing_steps' => 'array',
            'business_tips' => 'array',
            'storage_tips' => 'array',
            'status' => ProductStatus::class,
            'first_published_at' => 'datetime',
            'is_available' => 'boolean',
            'sold_bulk' => 'boolean',
            'sold_retail' => 'boolean',
            'default_bulk_pack_id' => 'integer',
            'bulk_min_kilograms' => 'integer',
            'bulk_max_kilograms' => 'integer',
            'retail_min_grams' => 'integer',
            'retail_max_grams' => 'integer',
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

            if (! $product->sold_bulk && ! $product->sold_retail) {
                throw new LogicException("Product {$product->getKey()} cannot be saved: it must be sold পাইকারি, খুচরা or both.");
            }

            $product->guardOrderLimits();

            if (! $product->sold_bulk) {
                $product->forgetTiers();

                return;
            }

            $product->settlePrice();
            $product->guardMrp();
            $product->guardDefaultBulkPack();
        });

        /**
         * A Product no longer sold a way leaves every Cart of that kind, so
         * no Cart is left offering a tea it cannot sell.
         */
        static::saved(function (Product $product): void {
            foreach (['sold_bulk' => OrderKind::Bulk, 'sold_retail' => OrderKind::Retail] as $switch => $kind) {
                if ($product->wasChanged($switch) && ! $product->isSoldAs($kind)) {
                    $product->carts()->detach(Cart::withTrashed()->where('kind', $kind)->pluck('id'));
                }
            }
        });
    }

    /**
     * The Carts this Product is assigned to.
     *
     * @return BelongsToMany<Cart, $this, CartProduct>
     */
    public function carts(): BelongsToMany
    {
        return $this->belongsToMany(Cart::class)->using(CartProduct::class);
    }

    /** A Product sold only খুচরা has no Tiers, so nothing priced by the Kilogram, and no পাইকারি Pack to start from. */
    private function forgetTiers(): void
    {
        $this->tiers = null;
        $this->price = null;
        $this->mrp = null;
        $this->default_bulk_pack_id = null;
    }

    /**
     * Forgets the Order Limit of a way the Product is no longer sold, then
     * refuses one whose maximum is below its minimum. That a পাইকারি minimum is a weight some পাইকারি Pack
     * makes is the form's rule, as every rule across a Product and its Packs is.
     */
    private function guardOrderLimits(): void
    {
        if (! $this->sold_bulk) {
            $this->bulk_min_kilograms = null;
            $this->bulk_max_kilograms = null;
        }

        if (! $this->sold_retail) {
            $this->retail_min_grams = null;
            $this->retail_max_grams = null;
        }

        $problem = OrderLimit::problem($this->bulk_min_kilograms, $this->bulk_max_kilograms)
            ?? OrderLimit::problem($this->retail_min_grams, $this->retail_max_grams);

        if ($problem !== null) {
            throw new LogicException("Product {$this->getKey()} cannot be saved: {$problem}");
        }
    }

    /** Refuses a default পাইকারি Pack that is not one of this Product's Packs ticked পাইকারি. */
    private function guardDefaultBulkPack(): void
    {
        if ($this->default_bulk_pack_id === null || ! $this->isDirty('default_bulk_pack_id')) {
            return;
        }

        $isOwnBulkPack = $this->exists && Pack::query()
            ->whereKey($this->default_bulk_pack_id)
            ->where('product_id', $this->getKey())
            ->where('serves_bulk', true)
            ->exists();

        if (! $isOwnBulkPack) {
            throw new LogicException("Product {$this->getKey()} cannot be saved: its default পাইকারি Pack must be one of its own Packs ticked পাইকারি.");
        }
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
     * The Product's Tiers as the pricing module reads them. A Product sold
     * only খুচরা has none, so asking is a mistake upstream: the bulk side
     * should never have listed it.
     *
     * @return non-empty-list<Tier>
     */
    public function priceTiers(): array
    {
        if (! $this->sold_bulk) {
            throw new LogicException("Product {$this->getKey()} is not sold পাইকারি; it has no Tiers.");
        }

        return ProductTiers::from($this->tiers ?? ProductTiers::flat((int) $this->price));
    }

    /**
     * The four Storage tips nearly every tea shares, which a new Product
     * starts with and the Merchant changes only for a tea kept differently.
     *
     * @return list<array{heading: string, explanation: string}>
     */
    public static function commonStorageTips(): array
    {
        return [
            ['heading' => 'শুষ্ক স্থানে রাখুন', 'explanation' => 'ঠান্ডা ও আর্দ্রতামুক্ত স্থানে রাখুন।'],
            ['heading' => 'বায়ুরোধী পাত্রে রাখুন', 'explanation' => 'প্যাকেট খোলার পর মুখ বন্ধ রাখুন।'],
            ['heading' => 'রোদ থেকে দূরে রাখুন', 'explanation' => 'সূর্যালোক ও অতিরিক্ত তাপ এড়িয়ে চলুন।'],
            ['heading' => 'তীব্র গন্ধ থেকে দূরে রাখুন', 'explanation' => 'মসলা, সাবান ও সুগন্ধি থেকে আলাদা রাখুন।'],
        ];
    }

    public function isSlugLocked(): bool
    {
        return $this->getOriginal('first_published_at') !== null;
    }

    public function isPublished(): bool
    {
        return $this->status === ProductStatus::Published;
    }

    /** Whether the Merchant sells this Product that way at all: its পাইকারি or খুচরা switch. */
    public function isSoldAs(OrderKind $kind): bool
    {
        return match ($kind) {
            OrderKind::Bulk => $this->sold_bulk,
            OrderKind::Retail => $this->sold_retail,
        };
    }

    /**
     * The Products sold that way: the one rule the bulk side lists by, and
     * the retail side too.
     *
     * @param  Builder<Product>  $query
     */
    #[Scope]
    protected function soldAs(Builder $query, OrderKind $kind): void
    {
        $query->where($kind === OrderKind::Bulk ? 'sold_bulk' : 'sold_retail', true);
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
     * The Product's Packs (ADR-0036), in the Merchant's order and smallest
     * first where none was set, each ticked for the ways it serves.
     *
     * @return HasMany<Pack, $this>
     */
    public function packs(): HasMany
    {
        return $this->hasMany(Pack::class)->orderBy('sort')->orderBy('grams');
    }

    /**
     * The পাইকারি Pack a bulk buyer starts from until they choose another.
     *
     * @return BelongsTo<Pack, $this>
     */
    public function defaultBulkPack(): BelongsTo
    {
        return $this->belongsTo(Pack::class, 'default_bulk_pack_id');
    }

    /**
     * The Packs a bulk line may be supplied in now: ticked পাইকারি and
     * switched on, in the Merchant's order. Read off the loaded Packs.
     *
     * @return Collection<int, Pack>
     */
    public function bulkPacks(): Collection
    {
        return $this->packs->filter(fn (Pack $pack): bool => $pack->serves_bulk && $pack->is_available)->values();
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
