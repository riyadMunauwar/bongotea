<?php

namespace App\Support\Couriers;

use App\Enums\Courier;
use App\Support\Settings\CourierSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * What each Courier is holding of the Merchant's money, asked for once and
 * held.
 *
 * Derived state a Courier publishes, kept the way `RedExAreas` keeps an area
 * list and for the same reason: it is read while a screen is being drawn, and
 * a Merchant who reloads the Dashboard ten times must not be ten calls to a
 * Courier, each of which a slow morning turns into fifteen seconds of blank
 * widget.
 *
 * Its own class rather than a method on the driver, because a balance has a
 * lifecycle the driver has not: it is fetched, it is held, it goes stale, and
 * a read that fails falls back on the last one that did not. The driver
 * answers what the Courier says right now and knows none of that.
 *
 * Two guarantees this is the single place for. A Courier that reports no
 * balance is **never asked** — `supportsBalance()` is read before anything
 * else, so the question is not put to a Courier that has no answer to it.
 * And a read that fails never becomes a zero: the figure last read stands,
 * with the time it was read at, and where there is none the Merchant is told
 * in words why there is no figure.
 */
class CourierBalances
{
    /** Where a Courier's last figure is held. */
    private const CACHE_PREFIX = 'courier.balance.';

    /**
     * How long a figure goes on standing for after it was read.
     *
     * A day, and far longer than the interval it is re-read on, because it is
     * not freshness this bounds — it is what a Merchant is shown while a
     * Courier is unreachable, and a figure with its age beside it is a better
     * answer than nothing at all.
     *
     * Measured from when the Courier gave the figure and never from the last
     * attempt to re-read it, which is the whole of what makes it a bound: a
     * Courier whose credentials were revoked on Monday fails every read
     * afterwards, and a day later the widget must say so rather than go on
     * showing Monday's money for as long as somebody keeps opening the
     * screen.
     */
    private const HELD_FOR_SECONDS = 86400;

    /**
     * How long a Courier is left alone between reads.
     *
     * Five minutes: long enough that a screen reloaded all morning costs one
     * call, short enough that a Merchant watching money come in sees it move.
     * Nothing turns on the figure — it is read and never written onto an
     * Order or a Shipment — so this is a comfort interval and not a
     * correctness one.
     */
    private const RE_ASK_AFTER_SECONDS = 300;

    public function __construct(private readonly CourierSettings $settings) {}

    /**
     * What this Courier is holding: the figure it last gave, a fresh one
     * where it is due to be asked again, and the reason there is none where
     * there is none.
     */
    public function of(Courier $courier): CourierBalance
    {
        $driver = $courier->driver();

        /** Asked of the capability and never of the name, so a Courier that reports none is never called. */
        if (! $driver->supportsBalance()) {
            return CourierBalance::notReported();
        }

        /**
         * A Courier this installation cannot speak to is said so in the one
         * wording there is, ahead of anything held: a Merchant who has just
         * switched a Courier off is owed that sentence rather than the figure
         * it was holding this morning.
         */
        if (($refusal = $driver->refusal()) !== null) {
            return CourierBalance::unavailable($refusal);
        }

        $held = $this->held($courier);

        if ($held !== null && CarbonImmutable::now()->getTimestamp() < $held['askedAt'] + self::RE_ASK_AFTER_SECONDS) {
            return self::answerFrom($held);
        }

        return $this->hold($courier, $driver->balance(), $held);
    }

    /**
     * Write down what the Courier just said and answer with what the Merchant
     * should see.
     *
     * A figure replaces what was held. An answer that is not a figure keeps
     * whatever figure was there and records why this read produced none, so
     * the widget goes on showing the last real figure and the Courier is
     * still left alone until the interval is up.
     *
     * @param  array{amount: ?int, readAt: ?int, error: ?string, askedAt: int}|null  $held
     */
    private function hold(Courier $courier, CourierBalance $answer, ?array $held): CourierBalance
    {
        $record = [
            'amount' => $answer->isReported() ? $answer->amount : ($held['amount'] ?? null),
            'readAt' => $answer->isReported() ? $answer->readAt?->getTimestamp() : ($held['readAt'] ?? null),
            'error' => $answer->isReported() ? null : $answer->error,
            'askedAt' => CarbonImmutable::now()->getTimestamp(),
        ];

        Cache::put($this->cacheKey($courier), $record, self::HELD_FOR_SECONDS);

        return self::answerFrom($record);
    }

    /**
     * The record held for this Courier, read back as carefully as it would be
     * off the wire — a row this does not recognise is no record at all rather
     * than something to fail on.
     *
     * @return array{amount: ?int, readAt: ?int, error: ?string, askedAt: int}|null
     */
    private function held(Courier $courier): ?array
    {
        $record = Cache::get($this->cacheKey($courier));

        if (! is_array($record) || ! is_int($record['askedAt'] ?? null)) {
            return null;
        }

        return [
            'amount' => is_int($record['amount'] ?? null) ? $record['amount'] : null,
            'readAt' => is_int($record['readAt'] ?? null) ? $record['readAt'] : null,
            'error' => is_string($record['error'] ?? null) ? $record['error'] : null,
            'askedAt' => $record['askedAt'],
        ];
    }

    /**
     * A held record as the answer a Merchant reads: the figure where there is
     * one still standing, and the reason where there is not.
     *
     * A figure older than it may stand for is no longer an answer — it is
     * dropped in favour of whatever the last read had to say about why it
     * could not be replaced, which by then is the only true thing there is to
     * say. A figure that is still standing carries that reason too, so a
     * Merchant reading money from this morning is told the Courier has been
     * silent since.
     *
     * @param  array{amount: ?int, readAt: ?int, error: ?string, askedAt: int}  $record
     */
    private static function answerFrom(array $record): CourierBalance
    {
        $readAt = $record['readAt'] === null ? null : CarbonImmutable::createFromTimestamp($record['readAt']);

        $stillStands = $record['amount'] !== null
            && $readAt !== null
            && $readAt->addSeconds(self::HELD_FOR_SECONDS)->isFuture();

        if (! $stillStands) {
            return CourierBalance::unavailable($record['error'] ?? 'This Courier could not be asked what it is holding.');
        }

        return CourierBalance::of((int) $record['amount'], $readAt, $record['error']);
    }

    /**
     * Where this Courier's figure is held: its name and a fingerprint of the
     * credentials it was read with, never the credentials themselves.
     *
     * Keyed by them for the reason `PathaoAccessToken` is — a balance is that
     * account's product and nothing else's. A Merchant who pastes a second
     * Steadfast account's keys is asking about a different account, and must
     * not be shown the first one's money under the new one's name for the
     * five minutes until the next read.
     */
    private function cacheKey(Courier $courier): string
    {
        $credentials = array_map(
            fn (string $property): string => (string) $this->settings->{$property},
            $courier->driver()->credentialProperties(),
        );

        return self::CACHE_PREFIX.$courier->value.'.'.hash('sha256', implode('|', $credentials));
    }
}
