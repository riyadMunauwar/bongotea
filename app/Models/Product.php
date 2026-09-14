<?php

namespace App\Models;

use App\Observers\ProductObserver;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Something a Customer can order, at a single price and with no variations.
 * Products have no public address of their own; they are reached only through
 * a Page.
 */
#[ObservedBy(ProductObserver::class)]
#[Fillable(['name', 'description', 'price'])]
class Product extends Model implements HasMedia
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, InteractsWithMedia, SoftDeletes;

    public const IMAGE_COLLECTION = 'image';

    /**
     * The disk a Product's image is stored on, and the one thing that says so.
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
        ];
    }

    /**
     * @return BelongsToMany<Page, $this>
     */
    public function pages(): BelongsToMany
    {
        return $this->belongsToMany(Page::class);
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

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::IMAGE_COLLECTION)
            ->useDisk(self::IMAGE_DISK)
            ->singleFile();
    }

    public function imageUrl(): ?string
    {
        return $this->getFirstMedia(self::IMAGE_COLLECTION)?->getFullUrl();
    }
}
