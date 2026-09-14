<?php

namespace App\Filament\Clusters\Inventory;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;

/**
 * What the Merchant owns, as a working area of its own.
 *
 * One sidebar entry holding a resource per record type, the way
 * `ReportsCluster` holds a page per subject: Stock Counts today, with
 * Supplies and Suppliers expected next. Without it, adopting inventory would
 * cost the sidebar a line per record type — three lines for one subject,
 * beside the whole Orders queue on one.
 *
 * **A working area, not a report.** Everything in here is something a User
 * does — counting a shelf, recording a delivery — and every figure it draws
 * is how things stand rather than how they went over a span of days. So no
 * page in here carries the Reporting Window, and the Stock report, when it
 * arrives, belongs in Reports beside the others (`ReportsCluster` says why:
 * an all-current page draws no window rather than a dead one).
 *
 * Ungated, like Reports and unlike `SettingsCluster`. A User who works Orders
 * is the same User who counts the shelf, and a permission here would gate the
 * shelf against the person standing at it.
 */
class InventoryCluster extends Cluster
{
    /**
     * A box on a shelf, for the things a Merchant owns rather than the things
     * they sell. It sits beside the tag that means a Product and the lorry
     * that means a parcel, and is deliberately neither: in the collapsed
     * 3.5rem rail this glyph is the only label any of these screens gets.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    /**
     * After the work and before the reading: Dashboard 0, Orders 1, Shipments
     * 2, Products 3, Pages 4, Inventory 10, Reports 50, Settings 100.
     *
     * 10 rather than 5 for the reason Reports is 50 and Settings is 100 — a
     * category arriving later has somewhere to go without renumbering the
     * five resources above it. Above Reports because counting a shelf is work
     * rather than reading, and below Pages because none of it is on the
     * selling surface at all.
     */
    protected static ?int $navigationSort = 10;

    /**
     * Tabs across the top, as in `ReportsCluster` — which holds the whole
     * reasoning, including why `$shouldRegisterSubNavigation = false` is a
     * trap rather than a tidier version of this. Inherited by every resource
     * added here later, so none of them declares it.
     */
    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
}
