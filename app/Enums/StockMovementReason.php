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
 * Eight cases today, and that is the whole of what this system can currently
 * do to a box: a delivery arrives, a User counts what is on the shelf, a
 * delivery that should never have been recorded is taken back out, a Courier
 * takes a parcel away, a parcel comes back — whole, or in the part a User has
 * named — and a parcel is given up as dead, which is two of them, because
 * giving up on a parcel takes it back off the sale before it writes the loss.
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

    /**
     * A Courier taking a parcel away: one movement per Product on the Order,
     * written when the Shipment carrying it reaches Booked and never when the
     * Order was captured or confirmed (ADR-0021).
     *
     * Named for what the Merchant sold rather than for the parcel that
     * carried it, because that is what the units leaving were. A parcel that
     * comes back is answered by a Return, and one written off by an Unsold
     * and a Loss — each its own row against the same Shipment, because the
     * ledger keeps both the going and whatever came of it, exactly as it
     * keeps a Supply and its Void.
     */
    case Sale = 'sale';

    /**
     * A parcel's units coming back onto the books at the cost they left at —
     * the exact opposite of the Sale it answers, stamped with the figure that
     * Sale carried rather than with whatever the average has become since.
     *
     * Written when a Courier brings the parcel back, and only then: what it
     * says is that a box came back through the door. A parcel given up as
     * dead is answered by an Unsold and a Loss instead, which is the same
     * arithmetic and a different sentence.
     */
    case Return = 'return';

    /**
     * Some of a parcel's units coming back, because a User has said which
     * ones did: at the figure stamped on the Sale they answer, like a Return.
     *
     * The only movement in the system a person decides on rather than a
     * Courier. A Partly Delivered parcel reports a collected amount and never
     * a list of items (ADR-0021), so it writes nothing at all until somebody
     * names the lines that came back — which is why this carries the name of
     * whoever named them, and why the lines they did not name write nothing
     * rather than a Loss.
     *
     * Its own word rather than a Return for the reason `Unsold` is one: the
     * ledger's key is one cause, one Product, one reason, and a parcel a User
     * has answered and a Courier then brings back whole owes both rows. What
     * the Return puts back is then what left and has not already come back.
     */
    case PartialReturn = 'partial_return';

    /**
     * A parcel's units taken back off the sale, because the parcel was given
     * up as dead: the exact opposite of the Sale, at the figure that Sale
     * carried.
     *
     * Half of a write-off and never written alone. The units in a parcel left
     * the shelf once, at Booked, so a Loss that only deducted would take the
     * same box twice and leave On Hand disagreeing with the shelf for good —
     * this is what the Loss beside it is taken out of.
     *
     * Its own word rather than a Return, for a reason the ledger enforces and
     * a reason a Merchant reads. The ledger's is the one-cause-one-movement
     * key: a parcel written off and *then* brought back by the Courier owes
     * both rows, and two rows sharing a reason against one Shipment cannot
     * both exist — the second would be swallowed as a duplicate telling, and
     * the units would never come back. The Merchant's is plainer: nothing
     * came back through any door, and a history saying it did would be a lie
     * about a box.
     */
    case Unsold = 'unsold';

    /**
     * A parcel given up as dead: units that left on it are written off at
     * what they cost when they left.
     *
     * The money figure a Merchant is owed for a parcel a Courier never
     * collected — without it, stock that left and was never paid for reads
     * as an ordinary Sale, and surfaces months later as shrinkage nobody can
     * account for. Written against the Shipment beside the Unsold that takes
     * the units back off the sale, so what the pair does to On Hand is
     * nothing and what it does to the record is name the loss.
     */
    case Loss = 'loss';

    public function getLabel(): string
    {
        return match ($this) {
            self::Adjustment => 'Adjustment',
            self::Supply => 'Supply',
            self::Void => 'Void',
            self::Sale => 'Sale',
            self::Return => 'Return',
            self::PartialReturn => 'Partial return',
            self::Unsold => 'Unsold',
            self::Loss => 'Loss',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Adjustment => 'warning',
            self::Supply => 'success',

            /** Quiet on purpose: stock leaving on a parcel somebody bought is the ordinary day, not something to look at. */
            self::Sale => 'gray',

            /** A parcel that came back is not a disaster and not good news: it is a fact to read past — and half of one reads the same way. */
            self::Return, self::PartialReturn => 'info',

            /** Quiet like the Sale it undoes: the loud half of a write-off is the Loss underneath it. */
            self::Unsold => 'gray',

            /** Loud on purpose: a void is a correction somebody should notice while reading past it, and a loss is money gone. */
            self::Void, self::Loss => 'danger',
        };
    }
}
