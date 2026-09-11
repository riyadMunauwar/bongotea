<?php

namespace App\Support\Tracking;

use App\Enums\TrackingMoment;
use App\Enums\TrackingProvider;
use App\Models\Destination;
use App\Support\Settings\TrackingSettings;

/**
 * What one Page reports, and where.
 *
 * One object rather than three answers, because all three are read from one
 * Tracking Config and the two halves of the system that ask — the recorder
 * that writes outbox rows and the snippet builder that writes the browser's
 * copy — must not be able to disagree about any of them. A Moment and a Pixel
 * both begin by asking `TrackingPlans::forPage()` for one of these.
 *
 * It is a reading and never a store: nothing here writes, and a Plan built
 * from a form's half-typed state answers exactly as the saved Page will,
 * which is what lets the Tracking tab show a preview that is not a second
 * implementation of the same rules.
 */
final class TrackingPlan
{
    /**
     * @param  list<Destination>  $destinations
     */
    private function __construct(
        private readonly array $destinations,
        private readonly TrackingConfig $config,
        private readonly TrackingSettings $settings,
    ) {}

    /**
     * @param  list<Destination>  $destinations
     */
    public static function of(array $destinations, TrackingConfig $config, TrackingSettings $settings): self
    {
        return new self($destinations, $config, $settings);
    }

    /**
     * Where this Page reports: the Destination it picked where it picked a
     * usable one, and each other Provider's default beside it.
     *
     * @return list<Destination>
     */
    public function destinations(): array
    {
        return $this->destinations;
    }

    /** Whether this Page reports nowhere at all, which is nothing to record and no Pixel to inject. */
    public function reportsNowhere(): bool
    {
        return $this->destinations === [];
    }

    /**
     * The events this Destination's Provider produces for this Moment, less
     * the ones this Page does not report.
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
     * Whether this Page reports one of a Provider's events: its own word
     * where it has one, and the installation's default where it has not.
     */
    public function reports(TrackingProvider $provider, string $event): bool
    {
        return $this->config->choiceFor($provider, $event)
            ?? $this->settings->reportsByDefault($provider, $event);
    }

    /**
     * Whether what this Page reports may name what was bought.
     *
     * Off, an event still carries its value and currency — the Merchant keeps
     * optimisation and reporting — and carries no product name, id or basket.
     * That is the whole of what a Page selling something Meta's business tool
     * terms restrict has to withhold.
     */
    public function sendsContentDetail(): bool
    {
        return ! $this->config->suppressesContentDetail;
    }
}
