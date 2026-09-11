<?php

namespace App\Support\Tracking;

use Closure;

/**
 * What somebody has said about a Provider's events, keyed by the Provider and
 * then by the Provider's own name for the event.
 *
 * The shape a Page's Tracking Config holds, the shape the tracking settings
 * row holds, and the shape the Tracking tab hands over — one walk, read the
 * same way by all three, so a Merchant's switches cannot be read one way on
 * the tab and another on the path of a Moment.
 *
 * Reading is total and forgiving, like every stored shape here: anything that
 * is not a Provider's name over an event's name over an answer is nothing
 * said, which is exactly what an absent switch means anyway. What counts as
 * an answer is the caller's, because a row holds booleans, a form holds
 * words, and both arrive here.
 */
final class EventSwitches
{
    /**
     * @template TValue
     *
     * @param  Closure(mixed): ?TValue  $answer  the stored value as this caller reads it, or null where nothing was said
     * @return array<string, array<string, TValue>>
     */
    public static function read(mixed $stored, Closure $answer): array
    {
        if (! is_array($stored)) {
            return [];
        }

        $switches = [];

        foreach ($stored as $provider => $events) {
            if (! is_string($provider) || ! is_array($events)) {
                continue;
            }

            foreach ($events as $event => $choice) {
                $value = is_string($event) ? $answer($choice) : null;

                if ($value !== null) {
                    $switches[$provider][$event] = $value;
                }
            }
        }

        return $switches;
    }
}
