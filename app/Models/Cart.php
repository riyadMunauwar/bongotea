<?php

namespace App\Models;

use App\Enums\OrderKind;
use App\Support\Pages\CartPlacements;
use Database\Factories\CartFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * A place to order the Merchant sets up: bulk or retail, settled when it is
 * made and never changed, under a name of its own, with the Products it lays
 * out ready to order.
 *
 * Assignment is per Product only. Picking a Category on the form ticks what
 * the Category holds then and stores nothing of the Category, so a tea added
 * to it later joins no Cart until it is assigned itself. A Product is
 * assigned only to a Cart of a way it is sold (`CartProduct` refuses the
 * rest), and may be assigned to any number of them.
 *
 * It is drawn by a Block on one Page at most, so its link is that Page's
 * path. Switched off, on no published Page, or deleted, it is closed: no one
 * is offered it or checks out from it.
 *
 * Deleting is soft: past Orders and Customers' Contents keep resolving its
 * name. `starting_kind` marks the two Carts the store opens with, one of each
 * kind, so install finds them again and never makes a second.
 */
#[Fillable(['name', 'kind', 'is_switched_on', 'sort_order'])]
class Cart extends Model
{
    /** @use HasFactory<CartFactory> */
    use HasFactory, SoftDeletes;

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_switched_on' => true,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'kind' => OrderKind::class,
            'starting_kind' => OrderKind::class,
            'is_switched_on' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The kind is locked once the Cart exists: Contents already held in
     * browsers, and Orders already captured from it, are of that kind.
     */
    protected static function booted(): void
    {
        static::updating(function (Cart $cart): void {
            if ($cart->isDirty('kind')) {
                throw new LogicException('A Cart\'s kind cannot be changed once it is created.');
            }
        });
    }

    /**
     * The Cart the store opened with for that kind, deleted or not.
     */
    public static function startingFor(OrderKind $kind): ?self
    {
        return static::withTrashed()->where('starting_kind', $kind)->first();
    }

    /**
     * The Products this Cart's page offers, picked by the Merchant.
     *
     * @return BelongsToMany<Product, $this, CartProduct>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->using(CartProduct::class);
    }

    /** The Page this Cart is drawn on, published or not; null while it stands on none. */
    public function page(): ?Page
    {
        return CartPlacements::pageOf($this->getKey());
    }

    /** Where a Customer reaches this Cart: its Page's path, or null while that Page is not published or there is none. */
    public function link(): ?string
    {
        $page = $this->page();

        return $page?->isPublished() ? $page->path() : null;
    }

    /** Whether a Customer may be offered this Cart and check out from it: switched on, not deleted, and on a published Page. */
    public function isOpen(): bool
    {
        return $this->is_switched_on && ! $this->trashed() && $this->link() !== null;
    }
}
