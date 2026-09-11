<?php

namespace App\Support\Tracking;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores what was being bought as JSON, and hands back the Selection.
 *
 * Null all the way through, unlike the Config casts: most rows have an Order
 * holding the same answer, and a column standing empty for them is what keeps
 * the outbox one row per event rather than one row plus a copy of a basket.
 *
 * @implements CastsAttributes<?Selection, Selection|array<string, mixed>|null>
 */
class AsSelection implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Selection
    {
        return Selection::fromArray(is_string($value) ? json_decode($value, true) : $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, ?string>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $selection = $value instanceof Selection ? $value : Selection::fromArray($value);

        return [$key => $selection === null ? null : json_encode($selection->toArray())];
    }
}
