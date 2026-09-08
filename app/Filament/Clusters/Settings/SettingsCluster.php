<?php

namespace App\Filament\Clusters\Settings;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Support\Icons\Heroicon;

/**
 * Where a Merchant decides things once for the whole installation.
 *
 * One sidebar entry, last, holding a page per Settings group. The pages are
 * the registry: Filament discovers every class in `Pages/` that names this
 * cluster, and each names the group it edits, so a new group is a group class
 * and a page class and nothing else — no list here, in a provider or in
 * config to keep in step.
 *
 * Gated by one permission for the whole cluster rather than one per page,
 * because what the gate protects is the category — a User who works Orders
 * should not be one click from a payment key — and not any page in
 * particular. The permission is granted directly to Users today and will be
 * carried by roles when roles arrive, with nothing here changing.
 */
class SettingsCluster extends Cluster
{
    /**
     * The permission a User needs to see, open or save anything in here.
     * `database/migrations/2026_09_08_061359_create_manage_settings_permission.php`
     * creates it and must agree with this literal.
     */
    public const PERMISSION = 'manage settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    /**
     * After every resource. The resources sort at Filament's default, so any
     * positive number would do; a large one leaves room for anything that
     * later wants to sit between them and this.
     */
    protected static ?int $navigationSort = 100;

    /**
     * Filament already hides the cluster when no page in it is accessible,
     * which the pages' own gate arranges. This closes the cluster's own URL
     * too, so a User without the permission gets a forbidden response there
     * rather than a redirect to nowhere.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->can(self::PERMISSION) ?? false;
    }
}
