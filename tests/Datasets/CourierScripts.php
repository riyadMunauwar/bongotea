<?php

use Tests\Fixtures\Couriers\PathaoScript;
use Tests\Fixtures\Couriers\SteadfastScript;

/**
 * Every Courier books a parcel the same way from the outside — the action,
 * the call, the Consignment, the Shipment and its first event — so the
 * courier tests run once per Courier over its own documented shapes. A later
 * Courier is a new script and a new row here, nothing more (ADR-0013).
 *
 * Two so far, and they disagree about almost everything underneath: one signs
 * every call with two static keys and the other has to be issued a token
 * first, one has no notion of a store and the other will not take a parcel
 * without one, and each has its own words for where a parcel stands. RedEx
 * has a driver that declares itself and refuses to book, and gains its row
 * with the ticket that builds its booking.
 */
dataset('courier scripts', [
    'Steadfast' => [new SteadfastScript],
    'Pathao' => [new PathaoScript],
]);
