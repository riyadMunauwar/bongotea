<?php

namespace App\Support\OrderComplete;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores an Order Complete Config as JSON and hands back the object every time.
 *
 * @implements CastsAttributes<OrderCompleteConfig, OrderCompleteConfig|array<string, mixed>>
 */
class AsOrderCompleteConfig implements CastsAttributes
{
    /**
     * A read is never null and never partial.
     *
     * The column is non-nullable and a Page is seeded on create, so the only
     * null this can meet is an unsaved model — which reads back the Config it
     * is about to be saved with. Nothing downstream branches on whether a Page
     * has been configured yet.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): OrderCompleteConfig
    {
        $stored = is_string($value) ? json_decode($value, true) : $value;

        return OrderCompleteConfig::fromArray(is_array($stored) ? $stored : []);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $config = $value instanceof OrderCompleteConfig
            ? $value
            : OrderCompleteConfig::fromArray(is_array($value) ? $value : []);

        return [$key => json_encode($config->toArray())];
    }
}
