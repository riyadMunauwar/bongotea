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
 * carry and why a Destination cannot be sent to asks the driver. Adding
 * TikTok is one case here and one class implementing the contract.
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
     * What the Merchant's own ad account calls the public identifier a
     * Destination is addressed by. Facebook renamed its Pixel ID to a Dataset
     * ID; TikTok calls its own a Pixel Code.
     */
    public function datasetLabel(): string
    {
        return match ($this) {
            self::Facebook => 'Dataset ID',
        };
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
