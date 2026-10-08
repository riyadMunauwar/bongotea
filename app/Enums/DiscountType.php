<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How a Product takes its Tier Discounts off its Regular Price: one kind for
 * the whole Product, because "10% off" and "৳30 off" are different promises
 * to a Customer.
 */
enum DiscountType: string implements HasLabel
{
    case Percent = 'percent';
    case Fixed = 'fixed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Percent => 'Percent off',
            self::Fixed => 'Taka off per kg',
        };
    }
}
