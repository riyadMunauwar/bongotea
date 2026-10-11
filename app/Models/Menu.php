<?php

namespace App\Models;

use App\Support\Menus\MenuIsAssigned;
use App\Support\Menus\MenuPlaces;
use Database\Factories\MenuFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named list of links the Merchant builds, nested up to three levels.
 *
 * A Menu is not its place: it appears wherever a place's settings group
 * names it (ADR-0033), and the model refuses to be deleted while one does,
 * so no path — a bulk action, a console command — can blank a live store's
 * navigation. Its items are written only by `MenuTree::save()`, which
 * validates and replaces the whole tree at once.
 */
#[Fillable(['name'])]
class Menu extends Model
{
    /** @use HasFactory<MenuFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (Menu $menu): void {
            $places = MenuPlaces::of($menu);

            if ($places !== []) {
                throw MenuIsAssigned::to($menu, $places);
            }
        });
    }

    /**
     * Every item at every level, in sibling order.
     *
     * @return HasMany<MenuItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class)->orderBy('position')->orderBy('id');
    }
}
