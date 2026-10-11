<?php

namespace App\Support\Tracking;

use App\Enums\TrackingMoment;
use App\Enums\TrackingProvider;
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
     * @param  list<TrackingProvider>  $providers
     */
    private function __construct(
        private readonly array $providers,
        private readonly TrackingSettings $settings,
    ) {}

    /**
     * @param  list<TrackingProvider>  $providers
     */
    public static function of(array $providers, TrackingSettings $settings): self
    {
        return new self($providers, $settings);
    }

    /**
     * Where the store reports: every Provider switched on.
     *
     * @return list<TrackingProvider>
     */
    public function providers(): array
    {
        return $this->providers;
    }

    /** Whether the store reports nowhere at all, which is nothing to record and no Pixel to inject. */
    public function reportsNowhere(): bool
    {
        return $this->providers === [];
    }

    /**
     * The events this Provider produces for this Moment, less the ones the
     * store does not report.
     *
     * The Provider still decides what a Moment produces; this only takes
     * things off that list. So a Moment a Provider says nothing about is
     * still nothing, and a switch can never invent an event.
     *
     * @return list<string>
     */
    public function eventsFor(TrackingProvider $provider, TrackingMoment $moment): array
    {
        return array_values(array_filter(
            $provider->driver()->eventsFor($moment),
            fn (string $event): bool => $this->reports($provider, $event),
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
     * Whether what the store reports may name what was bought, as the
     * Tracking settings have it.
     */
    public function sendsContentDetail(): bool
    {
        return $this->settings->namesProducts;
    }
}
