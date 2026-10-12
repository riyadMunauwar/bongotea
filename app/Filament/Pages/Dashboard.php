<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HasReportingWindow;
use App\Filament\Widgets\GatedOnDashboard;
use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;

/**
 * The panel's own Dashboard, owning the panel root.
 *
 * It is subclassed rather than registered as Filament's, so the screen the
 * panel opens onto is a file in this application that a widget can be given a
 * home on. It carried none for a while: the two Filament ships by default — a
 * card greeting the User by a name they already know, and the framework's
 * version number — are the reason the Dashboard was dropped once, and they were
 * never what earned it back.
 *
 * `App\Filament\Widgets\CachePathIndicator` is what did: glanceable,
 * per-installation operational truth, surfaced nowhere else in the panel. The
 * report widgets came after it and sit above it, in the order each one's sort
 * property says, all of them discovered rather than listed here.
 *
 * The Reporting Window is the one thing the page itself holds: a filter form
 * every report widget reads through Filament's page filters and hands to
 * `ReportingWindow::fromFilters()`, so every figure on the screen is counted
 * over the same days. Filament keeps the chosen filter in the session, which
 * is what makes it survive a visit to the Orders list and back.
 *
 * Every widget is drawn under that filter, in sort order, with no header
 * slot. Needs Attention was pinned above it for a while, being the one
 * widget that ignored the window — but it is full width, and the widgets
 * that ignore it now sit together at the foot of the screen so the tiles and
 * charts are read without scrolling past them. There are three of them, the
 * courier to-do list: the backlog of Orders nobody has sent, Courier money,
 * and Needs Attention. What the position used to say, each of them now says
 * in its own subheading, which is the whole of that signal once a figure is
 * drawn under a control it does not obey. Promoting a widget into the header
 * again would hand the slot to whichever widget happened to sort first,
 * which is how a widget that reads the filter ends up drawn above it.
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;
    use HasReportingWindow;

    /**
     * The widget grid, which is what this screen is. Filament would otherwise
     * hand the Dashboard a house — true enough of the panel root, but it says
     * nothing about what is on the screen, and a chart glyph would promise a
     * pie among figures that are drawn as lines, bars and tables.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    /**
     * First, and every top-level entry says where it sits the same way: the
     * sidebar reads Dashboard 0, Orders 1 (with Shipments and Customers inside
     * it), Products 2, Pages 3, Enquiries 4, Inventory 10, Reports 50, then
     * Settings at 100 — the work, then the reading, then the configuring. The
     * clusters sit in the tens and hundreds so that a category arriving later
     * needs no renumbering of the five above them.
     * Filament reads an undeclared sort as `-1`, so a resource that leaves
     * this off does not land at the end — it lands above this line.
     */
    protected static ?int $navigationSort = 0;

    /**
     * The Reporting Window control itself, drawn here as a filter form
     * across the top of the screen. Its components are
     * `HasReportingWindow`'s, so every report offers the same presets and
     * the same two date pickers, drawn the same way.
     */
    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components($this->reportingWindowComponents());
    }

    /**
     * The discovered widgets, less those the current User may not see.
     *
     * The page itself is ungated — anyone who holds a Role reaches it — but
     * its widgets divide into two audiences that stopped being one screen in
     * #161: the Merchant's figures behind `view reports` and the operator's
     * to-do list behind `work orders`. Each gated widget names its Permission
     * through `GatedOnDashboard`, and this drops the ones the User lacks, so a
     * User who works Orders lands on their to-do list and none of the revenue,
     * a User holding neither meets an empty screen rather than an error, and
     * the Merchant meets the whole screen. `GatedOnDashboard` holds the
     * reasoning.
     *
     * @return array<class-string<Widget> | WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        return array_values(array_filter(
            parent::getWidgets(),
            fn (string|WidgetConfiguration $widget): bool => $this->maySeeOnDashboard($widget),
        ));
    }

    /**
     * Whether the current User may see this widget on the Dashboard. A widget
     * that names no Permission through `GatedOnDashboard` is drawn for
     * everyone; one that does is drawn only for a User who holds it.
     */
    private function maySeeOnDashboard(string|WidgetConfiguration $widget): bool
    {
        $class = is_string($widget) ? $widget : $widget->widget;

        if (! is_subclass_of($class, GatedOnDashboard::class)) {
            return true;
        }

        return auth()->user()?->can($class::dashboardPermission()) ?? false;
    }
}
