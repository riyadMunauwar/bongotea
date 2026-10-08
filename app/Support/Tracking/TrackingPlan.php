<?php

namespace App\Support\Tracking;

use App\Enums\TrackingMoment;
use App\Enums\TrackingProvider;
use App\Models\Destination;
use App\Support\Settings\TrackingSettings;

/**
 * What the store reports, and where.
 *
 * One object rather than three answers, because the two halves of the system
 * that ask — the recorder that writes outbox rows and the snippet builder
 * that writes the browser's copy — must not be able to disagree about any of
 * them. A Moment and a Pixel both begin by asking `TrackingPlans::current()`
 * for one of these.
 *
 * It is a reading and never a store: nothing here writes.
 */
final class TrackingPlan
{
    /**
     * @param  list<Destination>  $destinations
     */
    private function __construct(
        private readonly array $destinations,
        private readonly TrackingSettings $settings,
    ) {}

    /**
     * @param  list<Destination>  $destinations
     */
    public static function of(array $destinations, TrackingSettings $settings): self
    {
        return new self($destinations, $settings);
    }

    /**
     * Where the store reports: each Provider's default Destination.
     *
     * @return list<Destination>
     */
    public function destinations(): array
    {
        return $this->destinations;
    }

    /** Whether the store reports nowhere at all, which is nothing to record and no Pixel to inject. */
    public function reportsNowhere(): bool
    {
        return $this->destinations === [];
    }

    /**
     * The events this Destination's Provider produces for this Moment, less
     * the ones the store does not report.
     *
     * The Provider still decides what a Moment produces; this only takes
     * things off that list. So a Moment a Provider says nothing about is
     * still nothing, and a switch can never invent an event.
     *
     * @return list<string>
     */
    public function eventsFor(Destination $destination, TrackingMoment $moment): array
    {
        return array_values(array_filter(
            $destination->driver()->eventsFor($moment),
            fn (string $event): bool => $this->reports($destination->provider, $event),
        ));
    }

    /**
     * Whether the store reports one of a Provider's events, as the Tracking
     * settings have it.
     */
    public function reports(TrackingProvider $provider, string $event): bool
    {
        return $this->settings->reportsByDefault($provider, $event);
    }

    /**
     * Whether what the store reports may name what was bought.
     *
     * Always, for now: whether to withhold the basket becomes a store-wide
     * Tracking setting of its own.
     */
    public function sendsContentDetail(): bool
    {
        return true;
    }
}
