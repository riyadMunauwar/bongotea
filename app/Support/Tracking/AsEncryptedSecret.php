<?php

namespace App\Support\Tracking;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * A column sealed with the application encrypter, which reads as blank rather
 * than throwing when it cannot be unsealed.
 *
 * The framework's own `encrypted` cast throws a `DecryptException` on a value
 * sealed under a key this installation no longer has, which would take down
 * every screen that lists the record — including the one a Merchant would
 * repair it from. `SettingsGroup` made the same call for the same reason
 * (ADR-0012): read blank, log it, and let the form say a value is stored.
 *
 * @implements CastsAttributes<string, string>
 */
class AsEncryptedSecret implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException $exception) {
            Log::error('An encrypted attribute could not be decrypted and is kept sealed as stored.', [
                'model' => $model::class,
                'id' => $model->getKey(),
                'attribute' => $key,
                'exception' => $exception,
            ]);

            return '';
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return blank($value) ? null : Crypt::encryptString((string) $value);
    }
}
