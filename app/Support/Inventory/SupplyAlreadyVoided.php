<?php

namespace App\Support\Inventory;

use App\Models\Supply;
use LogicException;

/**
 * This delivery has already been struck out, so it is not struck out again.
 *
 * Refused in three places that each catch a different thing, and this class
 * is what lets one of them be told apart from the rest. `VoidSupplyAction`
 * hides the button, which answers the User who can see the screen;
 * `StockLedger::voidSupply()` refuses under the Supply's own lock against a
 * fresh read, which answers the colleague who pressed it a minute ago from a
 * page drawn before theirs; and the movements' one-cause-one-movement key
 * refuses at the database, which answers everything else.
 *
 * A `LogicException` and not a `RuntimeException`, because it is the same
 * refusal `Supply` makes against being edited at all — a document that has
 * been struck out has nothing left to take back out, whoever asks.
 */
class SupplyAlreadyVoided extends LogicException
{
    public function __construct(public readonly Supply $supply)
    {
        parent::__construct("Supply {$supply->getKey()} has already been voided. A Supply is voided once.");
    }
}
