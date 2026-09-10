<?php

namespace App\Support\Checkout;

/**
 * Where on the form a Checkout Layout's own word is drawn.
 *
 * The document is written in the same order for every layout (ADR-0015), so
 * a slot's place is one of a fixed few: over the products, at the far end of
 * the row the products' word sits on, over each of the three columns a
 * layout may head the product list with, after the product list, over the
 * questions, over the delivery choice, over the payment choice, or beneath
 * the button. A layout draws at most one word at each place, and the view
 * asks the layout which (`CheckoutLayout::slotAt()`) rather than testing
 * every slot by name.
 *
 * The three column headings are three places rather than one, because that
 * is what "at most one word at a place" costs a layout drawing the product
 * list as a table: each heading is its own box on the Words tab and its own
 * cell in the sheet, and the view draws the three side by side on one
 * template line so a layout heading none emits the bytes it always did.
 */
enum WordPlace
{
    case Products;
    case ItemCount;
    case ItemHeading;
    case QuantityHeading;
    case AmountHeading;
    case EmptyList;
    case Details;
    case Delivery;
    case Payment;
    case Button;
}
