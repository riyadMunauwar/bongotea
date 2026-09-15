<?php

namespace App\Support\Inventory;

use App\Models\Shipment;
use LogicException;

/**
 * This parcel is not a question, so it is not answered.
 *
 * Either it never half arrived — a parcel that was delivered whole, came back
 * whole or is still out has nothing for a User to name — or somebody has
 * already said which of its items came back, and an answer is given once.
 *
 * Refused in three places that each catch a different thing, exactly as
 * `SupplyAlreadyVoided` is. `AnswerPartlyDeliveredAction` hides the question,
 * which answers the User who can see the screen; `StockLedger::recordPartialReturn()`
 * refuses under the parcel's own lock against a fresh read, which answers the
 * colleague who pressed it a minute ago from a page drawn before theirs; and
 * the movements' one-cause-one-movement key refuses at the database, which
 * answers everything else.
 *
 * A `LogicException` rather than a `RuntimeException`, because it says a
 * question was asked of a parcel that was never asking one — not that
 * something went wrong on the way to answering it.
 */
class ParcelNotAwaitingAnswer extends LogicException
{
    public function __construct(public readonly Shipment $shipment)
    {
        parent::__construct(
            "Shipment {$shipment->getKey()} is not waiting on an answer. Only a Partly Delivered parcel nobody has answered is, and it is answered once.",
        );
    }
}
