<?php

namespace App\Filament\Resources\Destinations\Widgets;

use App\Models\Destination;
use App\Support\Tracking\DeliveryHealth;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Whether reporting is healthy, above the list of places it reports to.
 *
 * Three tiles answering the three questions an operator arrives with. How
 * much is waiting, which is a queue being worked unless it is days old. How
 * much was refused, which is a credential or a payload somebody must fix.
 * And when the sweep last ran — the one that catches the failure the outbox
 * is arranged around, because a scheduler that has stopped shows up nowhere
 * else: the events do not vanish, they simply never leave, and every other
 * figure on this screen looks like a quiet week.
 *
 * The figures come whole from `DeliveryHealth`; nothing here counts. Drawn
 * only where a Destination exists, so a Merchant who has never configured
 * tracking is not told their scheduler is broken.
 */
class DeliveryHealthStats extends StatsOverviewWidget
{
    protected ?string $heading = 'Delivery';

    protected ?string $description = 'Whether what your store recorded is actually reaching your ad accounts.';

    public static function canView(): bool
    {
        return Destination::query()->exists();
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $health = app(DeliveryHealth::class);

        return [
            $this->waiting($health),
            $this->failed($health),
            $this->lastSwept($health),
        ];
    }

    /**
     * Events written down and not yet delivered.
     *
     * A figure with no colour: a queue is the normal state of an outbox
     * between sweeps, and colouring it would cry wolf every five minutes. The
     * age of the oldest one is what distinguishes a queue being worked from a
     * queue nothing is working, so that is the description.
     */
    private function waiting(DeliveryHealth $health): Stat
    {
        $waiting = $health->waiting();
        $oldest = $health->oldestWaitingAt();

        return Stat::make('Waiting to send', number_format($waiting))
            ->description($oldest === null ? 'Nothing waiting' : "Oldest happened {$oldest->diffForHumans()}")
            ->descriptionIcon(Heroicon::OutlinedClock)
            ->descriptionColor('gray');
    }

    /**
     * Events that never arrived — the same set the failures table below
     * lists, so the tile and the rows under it cannot disagree. A tile
     * reading nought over a table of failures is worse than no tile at all.
     *
     * The split is in the description because it is what the operator does
     * next: a refusal is a credential or a payload somebody must fix and will
     * sit here until they do, since the sweep never retries one, while an
     * expiry is a Destination that stayed broken for longer than the
     * Provider's window.
     */
    private function failed(DeliveryHealth $health): Stat
    {
        ['total' => $total, 'refused' => $refused, 'expired' => $expired] = $health->undelivered();

        return Stat::make('Failed', number_format($total))
            ->description($total === 0
                ? 'Nothing has failed'
                : "{$refused} refused by the Provider, {$expired} too old to accept")
            ->descriptionIcon($total === 0 ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedExclamationTriangle)
            ->descriptionColor($total === 0 ? 'success' : 'danger')
            ->color($total === 0 ? 'success' : 'danger');
    }

    /**
     * When `tracking:sweep` last finished.
     *
     * The tile that earns the screen. Nothing reaches a Provider except from
     * that command, so a scheduler nobody set up means a Merchant reporting
     * nothing at all while every Page looks configured and every event is
     * safely written down.
     */
    private function lastSwept(DeliveryHealth $health): Stat
    {
        $swept = $health->lastSweptAt();
        $stale = $health->isStale();

        return Stat::make('Last swept', $swept?->diffForHumans() ?? 'Never')
            ->description($stale
                ? 'The scheduler may not be running — no events can leave until it does'
                : 'Delivering every five minutes')
            ->descriptionIcon($stale ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedArrowPath)
            ->descriptionColor($stale ? 'danger' : 'success')
            ->color($stale ? 'danger' : 'success');
    }
}
