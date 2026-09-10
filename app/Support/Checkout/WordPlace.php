<?php

namespace App\Support\Checkout;

/**
 * Where on the form a Checkout Layout's own word is drawn.
 *
 * The document is written in the same order for every layout (ADR-0015), so
 * a slot's place is one of a fixed few: over the products, at the far end of
 * the row the products' word sits on, after the product list, over the
 * questions, over the delivery choice, over the payment choice, or beneath
 * the button. A layout draws at most one word at each place, and the view
 * asks the layout which (`CheckoutLayout::slotAt()`) rather than testing
 * every slot by name.
 */
enum WordPlace
{
    case Products;
    case ItemCount;
    case EmptyList;
    case Details;
    case Delivery;
    case Payment;
    case Button;
}
