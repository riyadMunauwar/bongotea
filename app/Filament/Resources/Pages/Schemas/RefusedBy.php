<?php

namespace App\Filament\Resources\Pages\Schemas;

use Closure;

/**
 * A validation rule that asks a domain object why a value cannot be what
 * was typed, so a refusal is worded once and the tab and the silent read
 * cannot disagree.
 *
 * Worded here, on the box that holds the value; silent in the Config's own
 * `fromArray()`, because a Config written by some other route has nobody to
 * tell. Blank is never refused: an empty box is the Merchant saying nothing,
 * and a page that says nothing keeps its own words.
 */
final class RefusedBy
{
    /**
     * @param  Closure(string): ?string  $refusal
     */
    public static function rule(Closure $refusal): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($refusal): void {
            $value = is_scalar($value) ? trim((string) $value) : '';

            if ($value !== '' && ($message = $refusal($value)) !== null) {
                $fail($message);
            }
        };
    }
}
