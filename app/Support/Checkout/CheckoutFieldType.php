<?php

namespace App\Support\Checkout;

/**
 * The control a Checkout Field is asked with.
 *
 * Three cases rather than the six the field system is headed for: a type is
 * only real once something renders it and compiles rules for it, and the
 * built-in fields — the only fields that exist so far — ask in exactly these
 * three ways.
 */
enum CheckoutFieldType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Select = 'select';
}
