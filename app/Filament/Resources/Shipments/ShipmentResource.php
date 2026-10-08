<?php

namespace App\Filament\Resources\Shipments;

use App\Enums\Permission;
use App\Filament\Resources\Shipments\Pages\ListShipments;
use App\Filament\Resources\Shipments\Pages\ViewShipment;
use App\Filament\Resources\Shipments\Schemas\ShipmentInfolist;
use App\Filament\Resources\Shipments\Tables\ShipmentsTable;
use App\Models\Shipment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The parcels, as a surface of their own.
 *
 * An Order's screen answers "where is this customer's parcel"; this answers
 * the questions that are about the parcels themselves — which of RedEx's are
 * on hold, what a Consignment a courier's support team just quoted belongs to,
 * what a fortnight of returns cost. Reading them off the Orders list would
 * mean an Order with two attempts appearing once, under whichever of them is
 * Active, which is the one thing a delivery history must not do (ADR-0018).
 *
 * Read-only, and deliberately: there is no create page and no edit page. A
 * Shipment exists because a Courier accepted a parcel, and every field on it
 * is either what that Courier was told or what it has since said. Typing one
 * in would be a parcel no Courier is carrying wearing the same name, exactly
 * as a hand-typed Order would be an Order with no Page behind it.
 */
class ShipmentResource extends Resource
{
    protected static ?string $model = Shipment::class;

    /**
     * The lorry the parcel leaves on, beside the Orders bag it was packed
     * from. Drawn to the same outline spec as the other four, so the five
     * read as peers in the collapsed rail.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    /**
     * Our reference for the attempt, which is what every Courier echoes back
     * and the one name that resolves to exactly one Shipment. A Consignment
     * would not: each Courier spells one its own way and only its issuer will
     * answer to it.
     */
    protected static ?string $recordTitleAttribute = 'invoice_reference';

    /** Third, directly under Orders: a parcel is what became of one. See `Pages\Dashboard` for the whole order. */
    protected static ?int $navigationSort = 2;

    /**
     * The parcels are part of the same working area as the Orders they came
     * from, so they sit behind the same floor: `work orders` (ADR-0025). A User
     * without it is refused the list and the sidebar entry, not shown a surface
     * they have no Order to reach it from. Closing the resource's own URL too
     * gives a forbidden response rather than a redirect to nowhere.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::WorkOrders) ?? false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return ShipmentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ShipmentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListShipments::route('/'),
            'view' => ViewShipment::route('/{record}'),
        ];
    }
}
