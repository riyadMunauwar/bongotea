<?php

namespace App\Support\Orders;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores an Order's snapshot of the questions its Merchant added, and hands
 * back the list every time.
 *
 * @implements CastsAttributes<list<CapturedAnswer>, iterable<CapturedAnswer|array<string, mixed>>>
 */
class AsCapturedAnswers implements CastsAttributes, SerializesCastableAttributes
{
    /**
     * A read is never null and never partial.
     *
     * The column is nullable, because every Order taken before a Page could ask
     * a question of its own has nothing to put in it and inventing an empty
     * list for them is a write no migration needs to make. Nothing downstream
     * sees that: an absent snapshot reads as no answers, which is what it is.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<CapturedAnswer>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        $stored = is_string($value) ? json_decode($value, true) : $value;

        if (! is_array($stored)) {
            return [];
        }

        $answers = [];

        foreach ($stored as $answer) {
            if (! is_array($answer)) {
                continue;
            }

            $answer = CapturedAnswer::fromArray($answer);

            if ($answer !== null) {
                $answers[] = $answer;
            }
        }

        return $answers;
    }

    /**
     * The snapshot as anything reading the Order as an array sees it.
     *
     * Without this, `attributesToArray()` hands out the answers themselves, and
     * the Order edit screen hydrates its form from exactly that — writing back
     * into objects that are readonly by construction, as what was agreed at
     * Capture should be. The activity trail is filled the same way.
     *
     * @param  list<CapturedAnswer>  $value
     * @param  array<string, mixed>  $attributes
     * @return list<array<string, mixed>>
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): array
    {
        return array_map(fn (CapturedAnswer $answer): array => $answer->toArray(), $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $answers = [];

        foreach ((is_iterable($value) ? $value : []) as $answer) {
            if ($answer instanceof CapturedAnswer) {
                $answers[] = $answer->toArray();

                continue;
            }

            if (is_array($answer) && ($rebuilt = CapturedAnswer::fromArray($answer)) !== null) {
                $answers[] = $rebuilt->toArray();
            }
        }

        return [$key => json_encode($answers)];
    }
}
