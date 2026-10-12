<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How strong a Product's liquor is, in the ten grades a tea trader says,
 * strongest first. A plus or minus is a shade either side of the number and
 * never arithmetic. Each is stored as its rank, so a stronger grade always
 * sorts above a weaker one and a grade added later moves no saved Product.
 */
enum Strength: int implements HasLabel
{
    case FivePlus = 10;
    case Five = 9;
    case FiveMinus = 8;
    case FourPlus = 7;
    case Four = 6;
    case FourMinus = 5;
    case ThreePlus = 4;
    case Three = 3;
    case ThreeMinus = 2;
    case TwoPlus = 1;

    /** The grade as it is written, e.g. "৪+". */
    public function grade(): string
    {
        return match ($this) {
            self::FivePlus => '৫+',
            self::Five => '৫',
            self::FiveMinus => '৫-',
            self::FourPlus => '৪+',
            self::Four => '৪',
            self::FourMinus => '৪-',
            self::ThreePlus => '৩+',
            self::Three => '৩',
            self::ThreeMinus => '৩-',
            self::TwoPlus => '২+',
        };
    }

    /** How many of the five bars it fills: the number alone, whatever its shade. */
    public function bars(): int
    {
        return match ($this) {
            self::FivePlus, self::Five, self::FiveMinus => 5,
            self::FourPlus, self::Four, self::FourMinus => 4,
            self::ThreePlus, self::Three, self::ThreeMinus => 3,
            self::TwoPlus => 2,
        };
    }

    public function getLabel(): string
    {
        return $this->grade();
    }
}
