<?php

use App\Enums\Courier;

/**
 * Every Courier answers the same questions about itself — what it needs typed
 * in before it can be spoken to, what it can be asked to do, and why it
 * cannot be used — so the registry's tests run once per Courier. A fourth
 * Courier is a case on the enum and a driver, and it is covered here the
 * moment it exists (ADR-0013).
 *
 * Built from the enum rather than listed, because a Courier missing from a
 * hand-written list would be a Courier nothing ever asked about.
 */
dataset('couriers', array_combine(
    array_map(fn (Courier $courier): string => $courier->getLabel(), Courier::cases()),
    array_map(fn (Courier $courier): array => [$courier], Courier::cases()),
));
