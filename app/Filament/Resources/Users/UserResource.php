<?php

namespace App\Filament\Resources\Users;

use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Where a Merchant manages the people who can sign in.
 *
 * A resource inside the Settings cluster, above the cluster's floor, for the
 * same reason `RoleResource` is: `SettingsCluster` is gated by
 * `manage settings`, but handing out access — adding a User, giving and
 * taking their Roles, promoting one to Owner — is the one thing whoever holds
 * the payment keys must not be able to reach, because it is how they would
 * promote themselves. So this is Owner-only, and a User holding
 * `manage settings` sees the cluster exactly as before with no Users entry.
 *
 * The Owner adds a User with a name, an email and an initial password handed
 * over out of band; nothing here sends mail. A User who has left is filed
 * away rather than deleted, because the row is what keeps their name on the
 * Stock Counts and Adjustments they made — deletion would null the
 * attribution a surprising figure is asked about.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Sort 6, above the Roles resource at 7 and below the Settings pages
     * (0–5), so adding it renumbers none of them.
     */
    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    /**
     * Owner-only, closing the resource's own URLs too so a User holding
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
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
