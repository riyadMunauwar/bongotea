<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;

/**
 * A widget the Dashboard draws only for a User holding a Permission.
 *
 * The Dashboard is the panel root, reachable by anyone who holds a Role, but
 * its widgets serve two audiences that used to be the same screen (#161): the
 * Merchant's figures and the operator's to-do list. A widget names which one
 * it is by implementing this — a money figure returns `Permission::ViewReports`,
 * a to-do returns `Permission::WorkOrders` — and `App\Filament\Pages\Dashboard`
 * drops any widget whose Permission the current User lacks before it draws the
 * grid.
 *
 * The gate is read by the Dashboard rather than declared in each widget's
 * `canView()` because `canView()` is static and blind to where the widget is
 * drawn: six of the money widgets are also the Page Report's own, reached
 * through `author pages`, and a global `canView()` would wrongly blank them
 * there. So this method is consulted only by the Dashboard's own widget
 * selection and is inert wherever a screen names the widget explicitly.
 *
 * A widget implementing nothing here is drawn for every panel User. The Cache
 * Path indicator is the one that stays that way, being per-installation
 * operational truth rather than either audience's figure — so a User holding
 * neither Permission meets it alone rather than an empty screen or an error.
 */
interface GatedOnDashboard
{
    public static function dashboardPermission(): Permission;
}
