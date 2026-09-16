<?php

namespace App\Filament\Clusters\Settings;

use App\Enums\Permission;
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
 * The category has a floor with pages standing above it.
 * `Permission::ManageSettings` is the floor: `canAccess()` gates the cluster
 * on it — a User who works Orders should not be one click from a payment key —
 * and every Settings *page* is reachable to anyone through that floor. A
 * resource in here may set its own gate higher, and the Roles screen does: it
 * is Owner-only, so whoever holds the payment keys still cannot promote
 * themselves. Both the floor and any gate above it are held through a Role
 * like every other Permission (ADR-0025).
 */
class SettingsCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    /**
     * Last, and the gap above it is the point. Every other top-level entry
     * now names its own place in the sidebar — Dashboard 0, Orders 1,
     * Products 2, Pages 3 — so this could have been 4; it is 100 so that
     * anything which later belongs between the work and the settings has
     * somewhere to go without renumbering the four above it.
     */
    protected static ?int $navigationSort = 100;

    /**
     * Filament already hides the cluster when no page in it is accessible,
     * which the pages' own gate arranges. This closes the cluster's own URL
     * too, so a User without the Permission gets a forbidden response there
     * rather than a redirect to nowhere.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::ManageSettings) ?? false;
    }
}
