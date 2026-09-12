<?php

namespace App\Support\Couriers;

/**
 * The Courier could not be asked at all: a connection that would not open, a
 * gateway timing out, an outage at their end.
 *
 * Silence and not an answer, so nothing is concluded from it and the Order is
 * left exactly as it was. The operator is told to try again rather than told
 * the parcel was refused.
 */
class CourierUnreachable extends BookingNotMade {}
