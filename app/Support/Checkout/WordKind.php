<?php

namespace App\Support\Checkout;

/**
 * What kind of line a Checkout Layout's own word is, which is how the island
 * draws it.
 *
 * The designs write a layout's words in four ways: a headline beside a
 * numbered badge, a headline on its own, an eyebrow (small capitals over a
 * block), and a line beneath the button. The kind is the slot's to say and
 * the view draws by it, so a layout adding a word chooses from these rather
 * than teaching the view a fifth markup.
 */
enum WordKind
{
    /** A heading beside a badge numbered by its position among the sections drawn. */
    case NumberedHeadline;

    /** A heading over a section, with no badge. */
    case Headline;

    /** Small capitals over a block, in the muted ink. */
    case Eyebrow;

    /** One line beneath the Order Button, after the button's own note. */
    case Line;
}
