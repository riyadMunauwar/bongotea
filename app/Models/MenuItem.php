<?php

namespace App\Models;

use App\Enums\MenuLinkType;
use Database\Factories\MenuItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One link in a Menu: a label, what it points at, and where it sits — its
 * parent (none at the top level) and its position among its siblings.
 *
 * The target is the one field its `type` reads: the `linkable` Page, an
 * `anchor` (on the `linkable` Page, or Home), or a `url`. A Heading has none.
 * Turning a target into an address is `StorefrontMenus`' job.
 */
#[Fillable(['menu_id', 'parent_id', 'position', 'label', 'type', 'note', 'linkable_type', 'linkable_id', 'anchor', 'url'])]
class MenuItem extends Model
{
    /** @use HasFactory<MenuItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => MenuLinkType::class,
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Menu, $this>
     */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    /**
     * @return BelongsTo<MenuItem, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<MenuItem, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position')->orderBy('id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }
}
