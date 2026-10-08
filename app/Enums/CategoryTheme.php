<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * One designed set of colours a Category wears wherever it is drawn.
 *
 * A theme is designed as a whole and never assembled colour by colour, so the
 * set lives here in code and a Category stores only the key. Premium, Gold
 * and Silver are the mockup's own ten tokens each, copied exactly; Garden,
 * Masala and Monsoon are designed in the same family for a grade the mockup
 * did not have; Neutral is what a Category falls back to when its stored key
 * names a theme that no longer ships.
 *
 * Every theme draws on one of two panels: a dark one (Premium, Masala) whose
 * text and ticks sit light on it, or a light one whose text is the store's
 * ink. The token names say what each colour is for, so a theme can be
 * redrawn without a storefront component knowing which kind it is.
 */
enum CategoryTheme: string implements HasLabel
{
    case Premium = 'premium';
    case Gold = 'gold';
    case Silver = 'silver';
    case Garden = 'garden';
    case Masala = 'masala';
    case Monsoon = 'monsoon';
    case Neutral = 'neutral';

    public function getLabel(): string
    {
        return match ($this) {
            self::Premium => 'Premium',
            self::Gold => 'Gold',
            self::Silver => 'Silver',
            self::Garden => 'Garden',
            self::Masala => 'Masala',
            self::Monsoon => 'Monsoon',
            self::Neutral => 'Neutral',
        };
    }

    /**
     * The theme a stored key names, or Neutral when it names none.
     */
    public static function fromKey(?string $key): self
    {
        return self::tryFrom((string) $key) ?? self::Neutral;
    }

    /**
     * The ten colours, keyed by what each is for: the Category's text and
     * its light tint (pill and tile), its panel and the text and secondary
     * text on that panel, the accent, the pill on the panel, the tick
     * behind a "why" point, and the panel's border.
     *
     * @return array{text: string, tint: string, panel: string, panelText: string, panelSubtext: string, accent: string, pillBackground: string, pillText: string, tickBackground: string, line: string}
     */
    public function tokens(): array
    {
        return match ($this) {
            self::Premium => [
                'text' => '#8A5A1F', 'tint' => '#F6EEDF',
                'panel' => '#1F3A2B', 'panelText' => '#FFFFFF', 'panelSubtext' => '#C5D3C9',
                'accent' => '#D4AE76', 'pillBackground' => '#D4AE76', 'pillText' => '#12231A',
                'tickBackground' => 'rgba(212,174,118,.18)', 'line' => 'rgba(255,255,255,.14)',
            ],
            self::Gold => [
                'text' => '#7A6410', 'tint' => '#F4EFD9',
                'panel' => '#FAF5EA', 'panelText' => '#16261D', 'panelSubtext' => '#5C6660',
                'accent' => '#9A6A2F', 'pillBackground' => '#B07A3B', 'pillText' => '#FFFFFF',
                'tickBackground' => '#FFFFFF', 'line' => '#EADFC6',
            ],
            self::Silver => [
                'text' => '#4D5A55', 'tint' => '#ECEFEE',
                'panel' => '#FFFFFF', 'panelText' => '#16261D', 'panelSubtext' => '#5C6660',
                'accent' => '#5C6A66', 'pillBackground' => '#6B7772', 'pillText' => '#FFFFFF',
                'tickBackground' => '#F3F5F4', 'line' => '#E2E6E2',
            ],
            self::Garden => [
                'text' => '#2E6B45', 'tint' => '#EDF3EE',
                'panel' => '#F4F8F4', 'panelText' => '#16261D', 'panelSubtext' => '#5C6660',
                'accent' => '#2E6B45', 'pillBackground' => '#2E6B45', 'pillText' => '#FFFFFF',
                'tickBackground' => '#FFFFFF', 'line' => '#D5E3D8',
            ],
            self::Masala => [
                'text' => '#9A3B22', 'tint' => '#F8E9E3',
                'panel' => '#3A1D14', 'panelText' => '#FFFFFF', 'panelSubtext' => '#E2C9BF',
                'accent' => '#E8A57A', 'pillBackground' => '#E8A57A', 'pillText' => '#2A140D',
                'tickBackground' => 'rgba(232,165,122,.18)', 'line' => 'rgba(255,255,255,.14)',
            ],
            self::Monsoon => [
                'text' => '#2F5872', 'tint' => '#E6EEF3',
                'panel' => '#F3F7FA', 'panelText' => '#16261D', 'panelSubtext' => '#5C6660',
                'accent' => '#3B6A88', 'pillBackground' => '#3B6A88', 'pillText' => '#FFFFFF',
                'tickBackground' => '#FFFFFF', 'line' => '#D6E2EA',
            ],
            self::Neutral => [
                'text' => '#5C6660', 'tint' => '#F3F5F4',
                'panel' => '#FFFFFF', 'panelText' => '#16261D', 'panelSubtext' => '#5C6660',
                'accent' => '#5C6660', 'pillBackground' => '#16261D', 'pillText' => '#FFFFFF',
                'tickBackground' => '#F3F5F4', 'line' => '#E2E6E2',
            ],
        };
    }
}
