<?php

namespace App\Filament\Clusters\Reports;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;

/**
 * Where a Merchant goes to read the installation's figures at length.
 *
 * One sidebar entry holding a page per subject. The pages are the registry:
 * Filament discovers every class in `Pages/` that names this cluster, so a new
 * report is a page class and nothing else — no list here, in a provider or in
 * config to keep in step. It holds the Courier Report, the Stock report, the
 * Buying report and the Margin report.
 *
 * It exists so that the reports after the first do not each cost a top-level
 * sidebar line. The Courier Report was one for a while, which is what made the
 * question worth asking: a screen carrying a single table had the same standing
 * in the sidebar as the whole Orders queue.
 *
 * **A report page draws the Reporting Window only if a figure on it obeys the
 * window.** That rule was written here before there was anything to apply it
 * to, and `StockReport` is the page it was written for: every figure on it is
 * a stock level as things stand, so it draws no window control whatsoever
 * rather than a dead one. A Merchant who moved a control that changed nothing
 * would read the same figures as the answer to a different question, which is
 * worse than having no control to move.
 *
 * Everything else in here obeys the window, which is the distinction the word
 * *report* carries in `CONTEXT.md` and what separates these pages from the
 * courier to-do list on the Dashboard — that ignores the window because a
 * backlog drawn under a window a Merchant has narrowed is a backlog that
 * quietly shrinks.
 *
 * Ungated, unlike `SettingsCluster`, and deliberately: the Dashboard already
 * draws Sales, Average Order Value and courier money to every User of the
 * panel, so a permission here would stop nobody from reading the same class of
 * figure one click away — and a gate a Merchant walks around is worse than
 * none, because the next reader assumes the figures are protected. A
 * `view reports` permission is owed on the day the Dashboard's own figures are
 * gated with it, which is its own piece of work.
 */
class ReportsCluster extends Cluster
{
    /**
     * A chart, for a category that is figures about things rather than the
     * things themselves. It was the Courier Report's own icon before the
     * cluster existed, chosen there to avoid the truck that belongs to
     * Shipments, and that reasoning moves up here intact: in the collapsed
     * 3.5rem rail this glyph is the only label any of these pages gets.
     *
     * It is spent now. No other top-level entry may draw a chart.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    /**
     * Between the work and the settings, in the gap `SettingsCluster` left
     * open on purpose — its own doc comment reserved 100 "so that anything
     * which later belongs between the work and the settings has somewhere to
     * go without renumbering". This is that thing, and `InventoryCluster` at
     * 10 is the second of them. The sidebar reads Dashboard 0, Orders 1,
     * Shipments 2, Products 3, Pages 4, Inventory 10, Reports 50, Settings
     * 100: the work, then the reading, then the configuring.
     *
     * 50 rather than 5 for the same reason 100 was chosen: a report is not the
     * last kind of category that could want a line here.
     */
    protected static ?int $navigationSort = 50;

    /**
     * Tabs across the top of the page, not a second column down its side.
     *
     * Filament's default is `Start`, which draws the cluster's pages as a
     * vertical strip *inside* the page — stacked on top of the panel's own
     * 14rem sidebar, so two columns of navigation stand between the screen
     * edge and the first figure. A report is a wide thing: the courier table
     * alone is eight columns, and every report after it is a table or a chart
     * that would rather have the width than the wayfinding.
     *
     * `HasSubNavigation::getSubNavigationPosition()` reads the page first, then
     * the cluster, then the panel — so this one property is inherited by every
     * report page added later, and none of them declares it.
     *
     * This is not the topbar ADR-0003 switched off. That is the panel's 4rem
     * shell strip; these tabs are drawn by the page, which is exactly what that
     * ADR anticipated would have to happen for anything wanting to live at the
     * top of a page.
     *
     * The alternative — `$shouldRegisterSubNavigation = false` — is a trap
     * rather than a tidier version of this. The sidebar carries one entry for
     * the whole cluster and `/admin/reports` only redirects to the first page,
     * so with the sub-navigation gone a second report would be reachable by
     * typing its URL and no other way. (`Cluster::getSubNavigation()` overrides
     * the trait and ignores the flag, which is why the redirect would go on
     * working and hide the problem.)
     */
    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
}
