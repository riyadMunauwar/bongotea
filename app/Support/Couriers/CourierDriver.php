<?php

namespace App\Support\Couriers;

use App\Enums\Courier;
use App\Enums\ShipmentStatus;
use App\Models\Shipment;

/**
 * What every Courier is behind.
 *
 * One contract so that nothing outside this namespace speaks any courier's
 * vocabulary or branches on any courier's name: what a Courier needs before
 * it can be spoken to, what it can be asked to do, how many parcels one call
 * may carry, how often it is worth asking again, and why it cannot be used
 * are all its driver's answers. Adding a fourth Courier is one class here and
 * one case on `Courier` (ADR-0013).
 *
 * The couriers are unequal and this contract leans into it rather than
 * levelling it down. One has no webhook and must be polled; one has no bulk
 * endpoint and must be sent parcels one at a time; one alone insists on
 * resolving the delivery area itself. Every one of those asymmetries is a
 * declaration here, so a caller asks what a Courier supports and never which
 * Courier it is.
 *
 * Status, webhooks and balance arrive with the tickets that need them. The
 * contract declares only what is implemented: a method declared here that
 * every driver throws from would be a contract that lies about what can be
 * asked.
 */
interface CourierDriver
{
    public function courier(): Courier;

    /**
     * Hand this parcel to the Courier and answer with what it said: the
     * Consignment, the tracking code, and where it says the parcel stands.
     *
     * Called inside the request with a User waiting on the Consignment
     * (ADR-0020), so it is asked once and never retried here. A Courier that
     * says no and a Courier that cannot be reached are different facts and
     * are raised as different exceptions; neither records anything as sent.
     *
     * The Shipment carries what to send — the invoice reference for this
     * attempt, what to collect, and the recipient as this attempt is being
     * told it — and is not saved yet: a refused booking leaves no row behind.
     *
     * @throws CourierRefusedBooking when the Courier answered and said no.
     * @throws CourierUnreachable when the Courier could not be asked.
     */
    public function book(Shipment $shipment): ShipmentBooked;

    /**
     * What one of this Courier's own status words means in the system's
     * vocabulary (ADR-0018).
     *
     * The only place a Courier's vocabulary is spoken. A word this Courier
     * has never documented is Unknown rather than an exception: couriers add
     * statuses without telling anyone, and a parcel whose status we cannot
     * read is still a parcel to be asked about again.
     */
    public function canonicalStatus(string $rawStatus): ShipmentStatus;

    /**
     * What this Courier needs to know about a parcel beyond who it is going
     * to — and what no other Courier is asked.
     *
     * The one place a Courier's own questions are spelled out, so the send
     * dialog draws the chosen Courier's and nobody else's, and a fourth
     * Courier's arrive with its driver rather than with a change to a screen.
     * Each field names the settings property holding the installation's
     * default for it, which is what makes the dialog an override of what the
     * Merchant decided once rather than a second place to decide it.
     *
     * Empty for a Courier that asks nothing beyond the recipient.
     *
     * @return list<ParcelField>
     */
    public function parcelFields(): array;

    /**
     * The names of the credentials this driver needs from the installation's
     * Courier settings, every one of which is a secret there.
     *
     * A Courier with any of these blank is not usable, whatever its switch
     * says. The names must be spelled the way the settings group spells them.
     *
     * @return list<string>
     */
    public function credentialProperties(): array;

    /**
     * Whether this Courier takes more than one parcel in a single call.
     *
     * False is not a smaller version of true: a Courier without a bulk
     * endpoint is sent one request per parcel, which is what forces a batch
     * to be swept rather than booked while a User waits (ADR-0020).
     */
    public function supportsBulk(): bool;

    /**
     * Whether this Courier announces a parcel's progress on its own.
     *
     * False means polling is not a backstop but the only channel there is,
     * and the timeline's resolution is the poll interval — which is why every
     * Shipment Event carries both the Courier's time and ours.
     */
    public function supportsWebhook(): bool;

    /**
     * Whether this Courier will say what it is holding of the Merchant's
     * money. False reads as "not reported" on the balance widget and never as
     * zero, because a blank mistaken for zero is a Merchant told their money
     * is gone.
     */
    public function supportsBalance(): bool;

    /**
     * Whether a booking must name a delivery area this Courier knows by id,
     * rather than an address in free text.
     *
     * True is the whole of why a booking dialog guesses an area from the
     * Customer's address and asks a human to confirm it, and why a parcel
     * whose area could not be matched waits rather than going to the wrong
     * depot.
     */
    public function needsAreaResolution(): bool;

    /**
     * How many parcels one booking call to this Courier may carry. One for a
     * Courier with no bulk endpoint, so a batch is the same loop either way
     * and nothing has to ask whether there is an endpoint before cutting it.
     */
    public function batchLimit(): int;

    /**
     * How long to leave an Active Shipment alone before asking this Courier
     * about it again.
     *
     * A driver property and not one global constant: a Courier that announces
     * nothing must be asked often enough for its timeline to mean something,
     * and asking one that announces at the same rate is a call nobody needed.
     */
    public function reAskIntervalSeconds(): int;

    /**
     * Why this Courier cannot be used, worded for the Merchant, or null where
     * it can.
     *
     * The one wording of "usable", as `PaymentSettings::refusal()` is for a
     * Payment Method: the admin prints it and the sweep prints it, and
     * neither restates the rule.
     */
    public function refusal(): ?string;
}
