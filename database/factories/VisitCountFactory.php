<?php

namespace Database\Factories;

use App\Models\VisitCount;
use App\Support\Settings\GeneralSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VisitCount>
 */
class VisitCountFactory extends Factory
{
    /**
     * A day's Visits across the whole store.
     *
     * The day is the Merchant's own, because that is what the aggregate is
     * keyed by and what a Reporting Window compares against — a factory
     * defaulting to the server's date would put a row a day out for half the
     * clock, and only sometimes.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'visited_on' => self::merchantDay(),
            'visits' => fake()->numberBetween(1, 500),
        ];
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
