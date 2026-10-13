<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Which Cart an Order came from, settled at Capture and never changed
 * (ADR-0034): bulk — Kilograms, priced by each Product's Tiers and the
 * Delivery Bands — or retail — Packs, each at its Pack Price, with the flat
 * retail Delivery Charge. Everything after Capture treats the two alike; only
 * pricing, Repricing and the Quote check branch on it.
 */
enum OrderKind: string implements HasColor, HasLabel
{
    case Bulk = 'bulk';
    case Retail = 'retail';

    public function getLabel(): string
    {
        return match ($this) {
            self::Bulk => 'Bulk',
            self::Retail => 'Retail',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Bulk => 'gray',
            self::Retail => 'info',
        };
    }
}
