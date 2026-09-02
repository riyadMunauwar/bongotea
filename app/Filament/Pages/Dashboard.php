<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

/**
 * The panel's own Dashboard, owning the panel root.
 *
 * It is subclassed rather than registered as Filament's, so the screen the
 * panel opens onto is a file in this application that a widget can be given a
 * home on. None is registered yet: the two Filament ships by default — a card
 * greeting the User by a name they already know, and the framework's version
 * number — are the reason the Dashboard was dropped once, and they are not
 * what earns it back.
 */
class Dashboard extends BaseDashboard {}
