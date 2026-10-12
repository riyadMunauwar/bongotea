<?php

namespace Database\Factories;

use App\Enums\PlaceKind;
use App\Models\PlaceVisitCount;
use App\Support\Settings\GeneralSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<PlaceVisitCount>
 */
class PlaceVisitCountFactory extends Factory
{
    /**
     * A day's Visits at Search, keyed by the Merchant's own day for the
     * reason `VisitCountFactory` gives.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'visited_on' => self::merchantDay(),
            'place_kind' => PlaceKind::Search,
            'place_id' => 0,
            'visits' => fake()->numberBetween(1, 500),
        ];
    }

    /**
     * The Visits of a record of the store: a Page, a Category or a Product.
     */
    public function of(Model $record): self
    {
        return $this->state([
            'place_kind' => PlaceKind::from(strtolower(class_basename($record))),
            'place_id' => $record->getKey(),
        ]);
    }

    /**
     * The Visits of one day, counted back from today in the Merchant's own
     * calendar: `on(0)` is today, `on(3)` three days ago.
     */
    public function on(int $daysAgo, ?int $visits = null): self
    {
        return $this->state(array_filter([
            'visited_on' => self::merchantDay($daysAgo),
            'visits' => $visits,
        ], fn (mixed $value): bool => $value !== null));
    }

    private static function merchantDay(int $daysAgo = 0): string
    {
        return CarbonImmutable::now(app(GeneralSettings::class)->timezone)->subDays($daysAgo)->toDateString();
    }
}
