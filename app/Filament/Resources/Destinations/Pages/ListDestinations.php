<?php

namespace App\Filament\Resources\Destinations\Pages;

use App\Filament\Resources\Destinations\DestinationResource;
use App\Filament\Resources\Destinations\Widgets\DeliveryHealthStats;
use App\Filament\Resources\Destinations\Widgets\RecentDeliveryFailures;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * The Destinations, and the diagnostics for them.
 *
 * This screen is where a Merchant already comes to ask "is my tracking
 * working" — the list itself answers it for configuration, naming a
 * Destination that is off or missing a credential in the Provider's own
 * wording. The two widgets answer the other half, which the list cannot see:
 * whether what the Pages recorded is actually leaving the building.
 *
 * Both live here rather than on the Dashboard for the same reason the
 * Destinations are in Settings: it is an operator's question, asked when
 * something looks wrong, not a figure a Merchant reads every morning. They
 * are also deliberately not discovered widgets — anything under
 * `app/Filament/Widgets` is registered on the panel and drawn on the
 * Dashboard, which is not where these belong.
 */
class ListDestinations extends ListRecords
{
    protected static string $resource = DestinationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            DeliveryHealthStats::class,
        ];
    }

    /**
     * @return array<class-string>
     */
    protected function getFooterWidgets(): array
    {
        return [
            RecentDeliveryFailures::class,
        ];
    }
}
