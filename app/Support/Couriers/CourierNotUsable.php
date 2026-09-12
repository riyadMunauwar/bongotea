<?php

namespace App\Support\Couriers;

/**
 * This installation cannot speak to this Courier at all — it is switched
 * off, a credential is missing, or what it is being asked for is not built
 * yet.
 *
 * Never worded as a refusal: no Courier was asked and none said anything, and
 * an operator told "Steadfast refused this parcel" would ring Steadfast about
 * a setting only the Merchant can change. The reason is the one wording
 * `CourierSettings::refusal()` gives, never a restatement of it.
 */
class CourierNotUsable extends BookingNotMade {}
