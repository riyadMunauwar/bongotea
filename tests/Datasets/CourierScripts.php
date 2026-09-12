<?php

use Tests\Fixtures\Couriers\SteadfastScript;

/**
 * Every Courier books a parcel the same way from the outside — the action,
 * the call, the Consignment, the Shipment and its first event — so the
 * courier tests run once per Courier over its own documented shapes. A later
 * Courier is a new script and a new row here, nothing more (ADR-0013).
 *
 * Only Steadfast so far: Pathao and RedEx have drivers that declare
 * themselves and refuse to book, and each gains its row with the ticket that
 * builds its booking.
 */
dataset('courier scripts', [
    'Steadfast' => [new SteadfastScript],
]);
