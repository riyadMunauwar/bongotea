<?php

namespace App\Support\Couriers;

/**
 * The Courier was asked and said no.
 *
 * An answer, and a final one: the same parcel asked again in a minute is
 * refused again, so this is worded back to the operator to act on — a phone
 * number the Courier will not take, a Merchant account out of credit — and
 * never retried on its own.
 */
class CourierRefusedBooking extends BookingNotMade {}
