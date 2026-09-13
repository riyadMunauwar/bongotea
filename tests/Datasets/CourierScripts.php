<?php

use Tests\Fixtures\Couriers\CourierScript;
use Tests\Fixtures\Couriers\PathaoScript;
use Tests\Fixtures\Couriers\RedExScript;
use Tests\Fixtures\Couriers\SteadfastScript;

/**
 * Every Courier books a parcel the same way from the outside — the action,
 * the call, the Consignment, the Shipment and its first event — so the
 * courier tests run once per Courier over its own documented shapes. A later
 * Courier is a new script and a new row here, nothing more (ADR-0013).
 *
 * Three, and they disagree about almost everything underneath: one signs
 * every call with two static keys, one has to be issued a token first and one
 * pastes a token that never expires; one has no notion of a store and two
 * will not take a parcel without one; two read the Customer's address in free
 * text and the third refuses it, insisting on an area out of a list only it
 * holds. Each has its own words for where a parcel stands, and each proves
 * its own vocabulary in its own script.
 *
 * @return array<string, CourierScript>
 */
function courierScripts(): array
{
    return [
        'Steadfast' => new SteadfastScript,
        'Pathao' => new PathaoScript,
        'RedEx' => new RedExScript,
    ];
}

dataset('courier scripts', array_map(
    fn (CourierScript $script): array => [$script],
    courierScripts(),
));

/**
 * The couriers that announce a parcel's progress on their own, and the ones
 * that never do.
 *
 * Split by what the script says it supports and never by name, exactly as the
 * application asks the driver: the webhook suite runs over the first and
 * proves the second is untouched by any of it. A Courier that starts
 * announcing moves between these two on the strength of one declaration.
 *
 * @param  bool  $announcing  whether to take the couriers that announce, or the ones that do not.
 * @return array<string, array{CourierScript}>
 */
function courierScriptsAnnouncing(bool $announcing): array
{
    return array_map(
        fn (CourierScript $script): array => [$script],
        array_filter(courierScripts(), fn (CourierScript $script): bool => $script->supportsWebhook() === $announcing),
    );
}

dataset('announcing couriers', courierScriptsAnnouncing(true));

dataset('silent couriers', courierScriptsAnnouncing(false));

/**
 * The couriers that will not read an address in free text and insist on
 * naming the delivery area out of a list of their own, and the ones that
 * resolve the destination themselves.
 *
 * Split by what the script declares, exactly as the announcing pair is: a
 * batch booked with nobody in front of it has to guess an area for the first
 * kind and has nothing to guess for the second, and neither test may name the
 * one Courier that happens to be in either list.
 *
 * @param  bool  $resolving  whether to take the couriers that name their own areas, or the ones that do not.
 * @return array<string, array{CourierScript}>
 */
function courierScriptsResolvingAreas(bool $resolving): array
{
    return array_map(
        fn (CourierScript $script): array => [$script],
        array_filter(courierScripts(), fn (CourierScript $script): bool => $script->needsAreaResolution() === $resolving),
    );
}

dataset('area-naming couriers', courierScriptsResolvingAreas(true));

dataset('free-text couriers', courierScriptsResolvingAreas(false));
