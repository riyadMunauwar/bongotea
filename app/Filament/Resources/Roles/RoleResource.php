<?php

namespace App\Filament\Resources\Roles;

use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\Schemas\RoleForm;
use App\Filament\Resources\Roles\Tables\RolesTable;
use App\Models\Role;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Where a Merchant composes the Roles they give to Users.
 *
 * A resource inside the Settings cluster rather than a Settings page, because
 * this is an unbounded list the Merchant creates — the same reason Users is
 * one. It sits above the cluster's own floor, though:
 * `SettingsCluster` is gated by `manage settings`, but composing Roles is the
 * one thing whoever holds the payment keys must not be able to reach, because
 * it is how they would promote themselves. So this is Owner-only, and a User
 * holding `manage settings` sees the cluster exactly as before with no Roles
 * entry in it.
 */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    /**
     * Sort 9, last: below the Settings pages (0–7) and the Users screen at 8.
     */
    protected static ?int $navigationSort = 9;

    public static function form(Schema $schema): Schema
    {
        return RoleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RolesTable::configure($table);
    }

    /**
     * Owner-only, and closing the resource's own URL too so a User holding
     * `manage settings` gets a forbidden response rather than a screen they
     * were never meant to reach.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->isOwner() ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
