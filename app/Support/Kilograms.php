<?php

namespace App\Support;

/**
 * Formats a whole number of Kilograms for display.
 *
 * The Kilogram is the one unit everything is sold in and every quantity is a
 * whole number of them, so there are no decimals to choose and nothing a
 * Merchant sets: what this owns is that every screen says it the same way.
 */
class Kilograms
{
    /**
     * Render Kilograms with their unit, e.g. 1250 -> "1,250 kg".
     */
    public static function format(int $kilograms): string
    {
        return number_format($kilograms).' kg';
    }
}
