<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Why a quantity of a Product moved.
 *
 * A movement is something that happened to a box, so every case here names a
 * real event: a User counted a shelf, a delivery arrived, a Courier took a
 * parcel. Nothing is written for a decision somebody made about an Order —
 * that is Committed, which is a fact about Orders and never a movement
 * (ADR-0021).
 *
 * Three cases today, and that is the whole of what this system can currently
 * do to a box: a delivery arrives, a User counts what is on the shelf, and a
 * delivery that should never have been recorded is taken back out.
 * Shipment, Return and Loss join them as the tickets that can actually cause
 * them land — each one a case here, a driver of it in `StockLedger`, and no
 * migration, because the column is a string cast to this enum rather than a
 * native MySQL enum.
 */
enum StockMovementReason: string implements HasColor, HasLabel
{
    /**
     * The ledger being squared with what a User counted. Signed either way:
     * a count above what the ledger held raises it and one below lowers it,
     * and the cause of the discrepancy is on the Stock Count that wrote it
     * rather than here.
     */
    case Adjustment = 'adjustment';

    /**
     * Goods arriving from a Supplier: one movement per line of the Supply
     * that caused it, always raising what is held.
     *
     * The only movement that carries a cost somebody actually paid rather
     * than the average in force, which is exactly what makes the average roll
     * forward at all (ADR-0022).
     */
    case Supply = 'supply';

    /**
     * A Supply being taken back out because it should never have been
     * recorded: one movement per line of it, the exact opposite of the one
     * that line wrote, stamped with the same cost.
     *
     * Its own reason rather than a second Supply movement with a negative
     * quantity, for two reasons that point the same way. The ledger's
     * one-cause-one-movement key is `(source, product, reason)`, so a
     * reversal pointing at the line it undoes could not share the reason
     * without colliding — which is also what makes voiding the same Supply
     * twice impossible at the database. And a Merchant reading a Product's
     * history is owed the word for what happened: a delivery that was struck
     * out is not a delivery of minus forty.
     */
    case Void = 'void';

    public function getLabel(): string
    {
        return match ($this) {
            self::Adjustment => 'Adjustment',
            self::Supply => 'Supply',
            self::Void => 'Void',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Adjustment => 'warning',
            self::Supply => 'success',

            /** Loud on purpose: a void is a correction somebody should notice while reading past it. */
            self::Void => 'danger',
        };
    }
}
