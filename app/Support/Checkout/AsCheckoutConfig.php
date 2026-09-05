<?php

namespace App\Support\Checkout;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores a Checkout Config as JSON and hands back the object every time.
 *
 * @implements CastsAttributes<CheckoutConfig, CheckoutConfig|array<string, mixed>>
 */
class AsCheckoutConfig implements CastsAttributes
{
    /**
     * A read is never null and never partial.
     *
     * The column is non-nullable and a Page is seeded on create, so the only
     * null this can meet is an unsaved model — which reads back the Config it
     * is about to be saved with. Nothing downstream branches on whether a Page
     * has been configured yet, which is the point of the backfill (ADR-0008).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): CheckoutConfig
    {
        $stored = is_string($value) ? json_decode($value, true) : $value;

        return CheckoutConfig::fromArray(is_array($stored) ? $stored : []);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $config = $value instanceof CheckoutConfig
            ? $value
            : CheckoutConfig::fromArray(is_array($value) ? $value : []);

        return [$key => json_encode($config->toArray())];
    }
}
