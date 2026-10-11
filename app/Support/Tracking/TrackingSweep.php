<?php

namespace App\Support\Tracking;

use App\Enums\TrackingEventState;
use App\Enums\TrackingProvider;
use App\Models\TrackingEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one place an ad platform is ever spoken to.
 *
 * It drains the outbox in the shape every Provider's API takes: group by
 * Provider, cut batches at the Provider's own limit, one request per
 * batch, record what was said. Nothing here knows an event name or a payload
 * field — that is the Provider's, and this is why adding TikTok needs no
 * change to the sweep.
 *
 * The states follow `PaymentState`'s rule exactly. A Provider that could not
 * be reached leaves its rows Pending for the next sweep, so a broken cron or
 * a brief outage loses nothing; a Provider that refused the batch marks them
 * Failed with what it said, because that is a defect a human must fix and
 * retrying it forever would be an infinite loop. A row the Provider will no
 * longer accept whatever we do is Expired, so the queue can drain.
 */
class TrackingSweep
{
    public function __construct(private readonly DeliveryHealth $health) {}

    /**
     * What one sweep did: how many rows were delivered, refused, given up on
     * and left for next time, and why each unusable Provider was passed
     * over, in the Provider's own wording and keyed by its name.
     *
     * @return array{sent: int, failed: int, expired: int, pending: int, skipped: array<string, string>}
     */
    public function run(): array
    {
        $tally = ['sent' => 0, 'failed' => 0, 'expired' => 0, 'pending' => 0, 'skipped' => []];

        foreach ($this->providersWithPendingRows() as $provider) {
            $tally['expired'] += $this->expireStaleRows($provider);

            $refusal = $provider->driver()->refusal();

            if ($refusal !== null) {
                $tally['skipped'][$provider->value] = $refusal;

                continue;
            }

            $this->deliver($provider, $tally);
        }

        /**
         * Last of all, and whatever was found. An empty outbox and a
         * scheduler that never fired look identical from the rows alone, and
         * telling those two apart is the whole reason the diagnostics screen
         * carries this figure.
         */
        $this->health->sweptNow();

        return $tally;
    }

    /**
     * Every Provider holding at least one Pending row, in the enum's order.
     *
     * Asked of the rows rather than of the Settings, so a Provider switched
     * off since its rows were written still has them expired once they are
     * too old, and is named in the tally for as long as they wait.
     *
     * @return list<TrackingProvider>
     */
    private function providersWithPendingRows(): array
    {
        $waiting = TrackingEvent::query()
            ->where('state', TrackingEventState::Pending)
            ->distinct()
            ->pluck('provider')
            ->all();

        return array_values(array_filter(
            TrackingProvider::cases(),
            fn (TrackingProvider $provider): bool => in_array($provider, $waiting, true),
        ));
    }

    /**
     * Mark every Pending row this Provider will no longer accept.
     *
     * Measured from when the Moment happened, not from when the row was
     * written, because that is what a Provider's acceptance window is
     * measured from. Done before the refusal check, so a Provider the
     * Merchant never fixes still drains rather than growing forever.
     */
    private function expireStaleRows(TrackingProvider $provider): int
    {
        $cutoff = now()->subSeconds($provider->driver()->acceptanceWindowSeconds());

        return TrackingEvent::query()
            ->where('provider', $provider)
            ->where('state', TrackingEventState::Pending)
            ->where('occurred_at', '<=', $cutoff)
            ->update([
                'state' => TrackingEventState::Expired,
                'last_error' => 'Older than what '.$provider->getLabel().' accepts.',
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array{sent: int, failed: int, expired: int, pending: int, skipped: array<string, string>}  $tally
     */
    private function deliver(TrackingProvider $provider, array &$tally): void
    {
        $driver = $provider->driver();

        TrackingEvent::query()
            ->where('provider', $provider)
            ->pending()
            ->with(['order.lines'])
            ->chunkById($driver->batchLimit(), function (Collection $batch) use ($provider, $driver, &$tally): bool {
                /** @var list<TrackingEvent> $events */
                $events = $batch->all();

                $delivery = $driver->send($events);

                if ($delivery->accepted) {
                    $tally['sent'] += $this->settle($batch, TrackingEventState::Sent, null);

                    return true;
                }

                if (! $delivery->isFinal) {
                    $tally['pending'] += $this->settle($batch, TrackingEventState::Pending, $delivery->error);

                    Log::warning('A tracking batch could not be delivered and stays pending.', [
                        'provider' => $provider->value,
                        'events' => $batch->count(),
                        'error' => $delivery->error,
                    ]);

                    /**
                     * Stop rather than walk on: the Provider is unreachable,
                     * so every later batch for this Provider would fail
                     * the same way and cost a call to find out.
                     */
                    return false;
                }

                $tally['failed'] += $this->settle($batch, TrackingEventState::Failed, $delivery->error);

                /** What the Provider actually said, whole: a refusal is the one outcome somebody has to read. */
                Log::warning('A tracking batch was refused and will not be retried.', [
                    'provider' => $provider->value,
                    'events' => $batch->count(),
                    'error' => $delivery->error,
                    'response' => $delivery->response,
                ]);

                return true;
            });
    }

    /**
     * Write one batch's outcome. The attempt is counted whatever it was, so
     * a row that has been tried and left Pending says so, and `sent_at`
     * records delivery rather than the sweep that happened to run.
     *
     * @param  Collection<int, TrackingEvent>  $batch
     */
    private function settle(Collection $batch, TrackingEventState $state, ?string $error): int
    {
        $ids = $batch->modelKeys();

        TrackingEvent::query()->whereKey($ids)->update([
            'state' => $state,
            'last_error' => $error,
            'sent_at' => $state === TrackingEventState::Sent ? now() : null,
            'attempts' => DB::raw('attempts + 1'),
            'updated_at' => now(),
        ]);

        return count($ids);
    }
}
