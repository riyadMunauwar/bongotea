<?php

namespace App\Enums;

use App\Support\Tracking\Facebook;
use App\Support\Tracking\Provider as ProviderDriver;
use Filament\Support\Contracts\HasLabel;

/**
 * An ad platform events can be reported to, by name.
 *
 * The enum is the name and the driver is the behaviour, exactly as
 * `PaymentMethod` and `PaymentGateway` are (ADR-0013): everything that
 * decides what a Moment becomes on the wire, how many events one request may
 * carry and why its account cannot be sent to asks the driver. Adding
 * TikTok is one case here, one class implementing the contract, and its
 * properties on `TrackingSettings`.
 */
enum TrackingProvider: string implements HasLabel
{
    case Facebook = 'facebook';

    public function getLabel(): string
    {
        return match ($this) {
            self::Facebook => 'Facebook',
        };
    }

    /**
     * What the Merchant's own ad account calls the public identifier the
     * account is addressed by. Facebook renamed its Pixel ID to a Dataset
     * ID; TikTok calls its own a Pixel Code.
     */
    public function datasetLabel(): string
    {
        return match ($this) {
            self::Facebook => 'Dataset ID',
        };
    }

    /**
     * Every event this Provider reports, across every Moment, in the order
     * the Moments produce them.
     *
     * Derived from the driver's own answers rather than listed, because the
     * driver is already the only thing that speaks this Provider's
     * vocabulary: a Provider that gains an event gains a switch on the
     * tracking settings page without it being edited, and the same derivation serves every Provider rather than being
     * copied into each driver.
     *
     * @return list<string>
     */
    public function events(): array
    {
        $driver = $this->driver();
        $events = [];

        foreach (TrackingMoment::cases() as $moment) {
            foreach ($driver->eventsFor($moment) as $event) {
                if (! in_array($event, $events, true)) {
                    $events[] = $event;
                }
            }
        }

        return $events;
    }

    /**
     * The driver behind this Provider, resolved from the container so it
     * takes its Settings and its HTTP client factory by injection.
     */
    public function driver(): ProviderDriver
    {
        return app(match ($this) {
            self::Facebook => Facebook::class,
        });
    }
}
