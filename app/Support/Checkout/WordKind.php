<?php

namespace App\Support\Checkout;

/**
 * What kind of line a Checkout Layout's own word is, which is how the island
 * draws it.
 *
 * The designs write a layout's words in six ways: a headline beside a
 * numbered badge, a headline on its own, an eyebrow (small capitals over a
 * block), a count at the far end of a title row, a line shown while a list
 * that can be ticked has nothing ticked, and a line beneath the button. The
 * kind is the slot's to say and the view draws by it, so a layout adding a
 * word chooses from these rather than teaching the view a seventh markup.
 */
enum WordKind
{
    /** A heading beside a badge numbered by its position among the sections drawn. */
    case NumberedHeadline;

    /** A heading over a section, with no badge. */
    case Headline;

    /** Small capitals over a block, in the muted ink. */
    case Eyebrow;

    /** A small line in the muted ink at the far end of the row a title sits on, naming a figure. */
    case Count;

    /** A line after the Product List, shown by the sheet only while a list that can be ticked has nothing ticked. */
    case EmptyState;

    /** One line beneath the Order Button, after the button's own note. */
    case Line;
}
