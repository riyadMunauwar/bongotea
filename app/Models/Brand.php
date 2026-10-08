<?php

namespace App\Models;

use Database\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Whose tea a Product is — Bongo Tea's own or another company's.
 *
 * Required on every Product, so "no brand" never quietly means "ours". Not a
 * Supplier: a Brand is whose name is on the tea, a Supplier who it was bought
 * from.
 */
#[Fillable(['name', 'slug', 'logo'])]
class Brand extends Model
{
    /** @use HasFactory<BrandFactory> */
    use HasFactory;

    /** The public disk the logo is stored on (ADR-0011). */
    public const LOGO_DISK = 'local';

    public const LOGO_DIRECTORY = 'brands';

    /**
     * Every Product carrying this Brand, those taken out of the catalogue
     * included, for the reason `Category::products()` gives.
     *
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class)->withTrashed();
    }

    public function logoUrl(): ?string
    {
        return blank($this->logo) ? null : url(Storage::disk(self::LOGO_DISK)->url($this->logo));
    }
}
