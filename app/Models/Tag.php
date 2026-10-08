<?php

namespace App\Models;

use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A use a Product is good for — milk tea, plain tea, strong liquor, hotels.
 *
 * It only narrows a list: a Tag says nothing about price, grouping or
 * appearance, and a Product may carry any number or none.
 */
#[Fillable(['name', 'slug', 'sort_order'])]
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }
}
