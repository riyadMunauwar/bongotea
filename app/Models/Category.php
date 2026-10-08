<?php

namespace App\Models;

use App\Enums\CategoryTheme;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The one grade a Product is sold under — Premium, Gold, Silver.
 *
 * Flat: Categories do not nest, and every Product is in exactly one. Its
 * colours are a Category Theme stored by key, never colours of its own.
 */
#[Fillable([
    'name', 'slug', 'note', 'quality_headline', 'about', 'why_points', 'theme_key', 'sort_order',
    'seo_title', 'seo_description', 'seo_image',
])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'why_points' => 'array',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The theme this Category wears, falling back to Neutral when its key
     * names a theme the code no longer ships.
     */
    public function theme(): CategoryTheme
    {
        return CategoryTheme::fromKey($this->theme_key);
    }

    /**
     * Every Product sold under this Category, those taken out of the
     * catalogue included: they still name it, and the database will not let
     * it go from under them.
     *
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class)->withTrashed();
    }
}
