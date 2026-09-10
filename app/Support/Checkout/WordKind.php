<?php

namespace App\Support\Checkout;

/**
 * What kind of line a Checkout Layout's own word is, which is how the island
 * draws it.
 *
 * The designs write a layout's words in eight ways: a headline beside a
 * numbered badge, a headline on its own, an eyebrow (small capitals over a
 * block), a count at the far end of a title row, a heading over one column
 * of a product list drawn as a table, a line shown while a list that can be
 * ticked has nothing ticked, a line beneath the button, and a word on a
 * control that only exists while the script runs. The kind is the slot's to
 * say and the view draws by it, so a layout adding a word chooses from these
 * rather than teaching the view a ninth markup.
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

    /** One column's heading over a Product List drawn as a table, in small capitals. */
    case ColumnHeading;

    /** A line after the Product List, shown by the sheet only while a list that can be ticked has nothing ticked. */
    case EmptyState;

    /** One line beneath the Order Button, after the button's own note. */
    case Line;

    /**
     * A word on a control a behaving layout's script builds — a step in a
     * Stepper's rail, its Next, its Back.
     *
     * Drawn nowhere in the document, because without script there is no rail
     * and no Next for the word to sit on, and a dead button in the markup is
     * a promise the form does not keep (ADR-0015). It rides to the script on
     * the form element instead, the way the Order Button's template does, so
     * a control has no `WordPlace` of its own.
     */
    case Control;
}
