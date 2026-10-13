<?php

namespace App\Support\Reporting;

use App\Enums\OrderKind;
use App\Support\Settings\GeneralSettings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use InvalidArgumentException;

/**
 * The span of days every report figure is counted over: a start instant,
 * an exclusive end instant, and the preset that produced them.
 *
 * A day begins at midnight in the Merchant's timezone — the General group's
 * Setting — never the server's, because a Merchant judges a day's spend
 * against a day's Orders and both happen in the same place. Both bounds are
 * computed as local midnights and handed out in UTC, which is what stored
 * capture times are compared against; storage never moves. Every window is
 * a whole number of days, ends at the midnight after its last day, and is
 * immutable.
 */
final class ReportingWindow
{
    /**
     * The keys a screen's filter form stores the window under, read back by
     * `fromFilters()`: the preset's value, and the two dates when it is
     * Custom.
     */
    public const string PRESET_FILTER = 'preset';

    public const string FROM_FILTER = 'from';

    public const string UNTIL_FILTER = 'until';

    /** Bulk, Retail, or nothing for All (ADR-0034). */
    public const string KIND_FILTER = 'kind';

    private function __construct(
        /** The first instant inside the window, in UTC. */
        public readonly CarbonImmutable $start,
        /** The first instant after the window, in UTC. */
        public readonly CarbonImmutable $end,
        public readonly ReportingPreset $preset,
        /** The PHP timezone identifier the days were cut in. */
        public readonly string $timezone,

        /**
         * Which kind of Order the window counts, or null for both (ADR-0034).
         * A report counts bulk and retail together unless narrowed, because
         * Sales is the business's whole takings.
         */
        public readonly ?OrderKind $kind = null,
    ) {}

    /** The same days, counting only Orders of this kind, or both where null. */
    public function forKind(?OrderKind $kind): self
    {
        return new self($this->start, $this->end, $this->preset, $this->timezone, $kind);
    }

    /**
     * What a screen shows before a Merchant picks anything: today and the
     * six days before it, so a quiet morning with no Orders yet still has
     * something to say.
     */
    public static function default(?CarbonInterface $now = null): self
    {
        return self::of(ReportingPreset::Last7Days, $now);
    }

    /**
     * A window for a preset, cut from "now" as the Merchant sees it. "Now"
     * defaults to the clock and may be handed in from any timezone; it is
     * the Merchant's calendar that decides which day it is. This month is
     * month to date, since a window never reaches into the future.
     */
    public static function of(ReportingPreset $preset, ?CarbonInterface $now = null): self
    {
        if ($preset === ReportingPreset::Custom) {
            throw new InvalidArgumentException('A custom Reporting Window is built from its two dates with between().');
        }

        $timezone = self::merchantTimezone();
        $today = CarbonImmutable::instance($now ?? CarbonImmutable::now())->setTimezone($timezone)->startOfDay();
        $tomorrow = $today->addDay();

        [$first, $after] = match ($preset) {
            ReportingPreset::Today => [$today, $tomorrow],
            ReportingPreset::Yesterday => [$today->subDay(), $today],
            ReportingPreset::Last7Days => [$today->subDays(6), $tomorrow],
            ReportingPreset::Last30Days => [$today->subDays(29), $tomorrow],
            ReportingPreset::ThisMonth => [$today->startOfMonth(), $tomorrow],
        };

        return new self($first->utc(), $after->utc(), $preset, $timezone);
    }

    /**
     * The window a screen's filter form describes, keyed by the constants
     * above, as Filament hands a widget its page filters. Anything a form
     * cannot have said — no filters yet, an unknown preset, Custom with a
     * date still unpicked or unreadable — reads as the default, so a widget
     * always has a window and never a form error. Custom dates the wrong
     * way round are taken as the span between them.
     *
     * @param  array<string, mixed>|null  $filters
     */
    public static function fromFilters(?array $filters, ?CarbonInterface $now = null): self
    {
        return self::daysFromFilters($filters, $now)->forKind(OrderKind::tryFrom((string) ($filters[self::KIND_FILTER] ?? '')));
    }

    /**
     * @param  array<string, mixed>|null  $filters
     */
    private static function daysFromFilters(?array $filters, ?CarbonInterface $now): self
    {
        $preset = ReportingPreset::tryFrom((string) ($filters[self::PRESET_FILTER] ?? '')) ?? ReportingPreset::Last7Days;

        if ($preset !== ReportingPreset::Custom) {
            return self::of($preset, $now);
        }

        $from = $filters[self::FROM_FILTER] ?? null;
        $until = $filters[self::UNTIL_FILTER] ?? null;

        if (! is_string($from) || ! is_string($until) || $from === '' || $until === '') {
            return self::default($now);
        }

        try {
            $timezone = self::merchantTimezone();
            $first = CarbonImmutable::parse($from, $timezone)->startOfDay();
            $last = CarbonImmutable::parse($until, $timezone)->startOfDay();
        } catch (InvalidFormatException) {
            return self::default($now);
        }

        return $last->lessThan($first)
            ? self::coveringDays($last, $first, $timezone)
            : self::coveringDays($first, $last, $timezone);
    }

    /**
     * A window between two dates a Merchant named, both inclusive, as a
     * date picker hands them over (`Y-m-d`).
     */
    public static function between(string $from, string $until): self
    {
        $timezone = self::merchantTimezone();
        $first = CarbonImmutable::parse($from, $timezone)->startOfDay();
        $last = CarbonImmutable::parse($until, $timezone)->startOfDay();

        if ($last->lessThan($first)) {
            throw new InvalidArgumentException("A Reporting Window cannot end ({$until}) before it starts ({$from}).");
        }

        return self::coveringDays($first, $last, $timezone);
    }

    /**
     * The Custom window from one local midnight to the midnight after
     * another, the second no earlier than the first.
     */
    private static function coveringDays(CarbonImmutable $first, CarbonImmutable $last, string $timezone): self
    {
        return new self($first->utc(), $last->addDay()->utc(), ReportingPreset::Custom, $timezone);
    }

    /**
     * The span of the same number of days immediately before this one,
     * ending where this one starts: what a change is measured against.
     * Days are stepped in local time, so the comparison to a window that
     * straddles a daylight-saving change is still whole days.
     */
    public function comparison(): self
    {
        $first = $this->localStart()->subDays($this->days());

        return new self($first->utc(), $this->start, $this->preset, $this->timezone, $this->kind);
    }

    /**
     * How many calendar days the window covers in the Merchant timezone.
     */
    public function days(): int
    {
        return (int) $this->localStart()->diffInDays($this->localEnd());
    }

    public function spansOneDay(): bool
    {
        return $this->days() === 1;
    }

    /**
     * Hourly for one calendar day, so the day is not a single dot; daily
     * for anything longer.
     */
    public function bucketSize(): BucketSize
    {
        return $this->spansOneDay() ? BucketSize::Hour : BucketSize::Day;
    }

    /**
     * Every slice of the window, in order, whether or not anything was
     * captured inside it — a series reads one figure per bucket and an
     * empty hour is a zero, not a gap. Cut in local time and handed out
     * in UTC like the window itself.
     *
     * @return list<ReportingBucket>
     */
    public function buckets(): array
    {
        $size = $this->bucketSize();
        $end = $this->localEnd();
        $cursor = $this->localStart();
        $buckets = [];

        while ($cursor->lessThan($end)) {
            $next = $size === BucketSize::Hour ? $cursor->addHour() : $cursor->addDay();

            $buckets[] = new ReportingBucket($cursor->utc(), $next->min($end)->utc());

            $cursor = $next;
        }

        return $buckets;
    }

    /**
     * The start as the Merchant reads it: midnight of the first day.
     */
    public function localStart(): CarbonImmutable
    {
        return $this->start->setTimezone($this->timezone);
    }

    /**
     * The end as the Merchant reads it: midnight after the last day.
     */
    public function localEnd(): CarbonImmutable
    {
        return $this->end->setTimezone($this->timezone);
    }

    private static function merchantTimezone(): string
    {
        return app(GeneralSettings::class)->timezone;
    }
}
