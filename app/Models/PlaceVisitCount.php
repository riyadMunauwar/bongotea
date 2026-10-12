<?php

namespace App\Models;

use App\Enums\PlaceKind;
use Database\Factories\PlaceVisitCountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * How many times one place of the storefront was loaded on one day.
 *
 * The breakdown beside `VisitCount`, written by `VisitCounter` alone in the
 * same breath, and never what the store's figure is read from: a load that
 * resolves to no place is a Visit to the store and to no place, so the places
 * may add up to less than the store.
 *
 * Like the store's aggregate it holds nothing about anybody and is kept
 * indefinitely. The date is a plain `Y-m-d` string in the Merchant's
 * timezone, uncast, for the reason `VisitCount` gives.
 */
#[Fillable([
    'visited_on',
    'place_kind',
    'place_id',
    'visits',
])]
class PlaceVisitCount extends Model
{
    /** @use HasFactory<PlaceVisitCountFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'place_kind' => PlaceKind::class,
            'place_id' => 'integer',
            'visits' => 'integer',
        ];
    }
}
