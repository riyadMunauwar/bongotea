<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Orders\OrderResource;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The backlog tile, full width above Courier money: how many Orders are
 * still waiting to be handed to a Courier, and one click into the queue
 * holding exactly those.
 *
 * Parcels fall through a gap silently: nothing on the queue distinguishes
 * an Order booked this morning from one captured three days ago that
 * nobody ever sent. The Orders list can be asked — `not_yet_sent` is a
 * filter on it — but asking is going looking, and a backlog a Merchant has
 * to go looking for is a backlog they find a week late. So it is put in
 * front of them, and the tile is the way in rather than a number to write
 * down: its description is a link to the queue with the filter already on
 * and the tab set to All, so the list that opens is the list that was
 * counted (`OrderResource::notYetSentUrl()`).
 *
 * A to-do list, not a report. It reads no page filter and takes no
 * Reporting Window, and it says so in its own subheading for the reason
 * Needs Attention and Courier money say it in theirs: drawn under that
 * control like every figure that does obey it, a number silently read as
 * filtered is a backlog that quietly shrinks when a Merchant narrows the
 * window.
 *
 * The counting is `OrderResource::notYetSentCount()`'s, beside the filter
 * the link turns on, so the tile and the queue cannot drift apart. Nothing
 * here counts.
 */
class UnsentOrdersStat extends StatsOverviewWidget
{
    /**
     * Above Courier money, so the three widgets that ignore the Reporting
     * Window — this, Courier money and Needs Attention — are read as one
     * group at the foot of the screen rather than scattered among the
     * figures that obey it. Between the Delivery mix at 60 and Courier money
     * at 65, so no existing widget moves.
     */
    protected static ?int $sort = 63;

    protected ?string $heading = 'Waiting for a Courier';

    protected ?string $description = 'As things stand now, whatever the Reporting Window above says.';

    /**
     * One column, so the single tile is the width of the screen rather than
     * a third of it beside two empty cells — Filament's default grid is
     * three across, which is right for a row of figures and wrong for a
     * figure on its own.
     */
    protected int|array|null $columns = 1;

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $waiting = OrderResource::notYetSentCount();
        $cleared = $waiting === 0;

        /**
         * The link is on the tile whatever the figure is. A nought says the
         * backlog is worked through rather than that the tile has stopped
         * counting, and the one way a Merchant tells those apart is to open
         * the queue and be shown the empty list under the filter that
         * produced it.
         */
        return [
            Stat::make('Not yet sent to a Courier', number_format($waiting))
                ->description($cleared
                    ? 'Every Order is out with a Courier or has already reached a door'
                    : 'Book them from the Orders list')
                ->descriptionIcon($cleared ? Heroicon::CheckCircle : Heroicon::ArrowTopRightOnSquare)
                ->descriptionColor($cleared ? 'success' : 'warning')
                ->url(OrderResource::notYetSentUrl()),
        ];
    }
}
