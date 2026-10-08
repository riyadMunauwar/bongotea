<?php

namespace App\Filament\Resources\Destinations;

use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Resources\Destinations\Pages\CreateDestination;
use App\Filament\Resources\Destinations\Pages\EditDestination;
use App\Filament\Resources\Destinations\Pages\ListDestinations;
use App\Filament\Resources\Destinations\Schemas\DestinationForm;
use App\Filament\Resources\Destinations\Tables\DestinationsTable;
use App\Models\Destination;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Where a Merchant's ad platform accounts are configured.
 *
 * A resource inside the Settings cluster rather than a Settings page, because
 * this is an unbounded list the Merchant creates, each item carrying its own
 * secret — which is exactly what a Settings group cannot hold (ADR-0012).
 * It is gated on the cluster's permission like every page in there: a User
 * who works Orders should not be one click from an access token.
 */
class DestinationResource extends Resource
{
    protected static ?string $model = Destination::class;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    /**
     * After the Settings pages, which sort 0 to 4. A resource left at
     * Filament's default would sort ahead of them and the cluster's own URL
     * would open onto this rather than onto General, which is where a
     * Merchant expects Settings to start.
     */
    protected static ?int $navigationSort = 5;

    /**
     * "Tracking Destinations" in the sidebar, "Destination" on the record: the
     * navigation says which subsystem this belongs to, and the form says what
     * one of them is.
     */
    protected static ?string $navigationLabel = 'Tracking Destinations';

    protected static ?string $modelLabel = 'Destination';

    public static function form(Schema $schema): Schema
    {
        return DestinationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DestinationsTable::configure($table);
    }

    public static function canAccess(): bool
    {
        return SettingsCluster::canAccess();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDestinations::route('/'),
            'create' => CreateDestination::route('/create'),
            'edit' => EditDestination::route('/{record}/edit'),
        ];
    }
}
