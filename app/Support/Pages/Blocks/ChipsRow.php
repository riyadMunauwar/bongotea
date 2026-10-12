<?php

namespace App\Support\Pages\Blocks;

use App\Support\Pages\PageBlock;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

/**
 * A centred row of words in pills — who the store is for, or, each pill
 * linked, where to read on.
 *
 * Chips were once stored as bare words; those are read as unlinked chips,
 * in the editor and on the storefront alike.
 */
class ChipsRow extends PageBlock
{
    public static function type(): string
    {
        return 'chips_row';
    }

    public static function label(): string
    {
        return 'Chips row';
    }

    public static function icon(): Heroicon
    {
        return Heroicon::OutlinedTag;
    }

    public static function fields(): array
    {
        return [
            TextInput::make('heading')->maxLength(120),

            Repeater::make('chips')
                ->minItems(1)
                ->maxItems(20)
                ->columns(2)
                ->afterStateHydrated(function (Repeater $component, mixed $state): void {
                    $component->rawState(is_array($state) ? self::chips($state) : $state);
                    $component->hydrateItems();
                })
                ->schema([
                    TextInput::make('label')->required()->maxLength(60),
                    self::linkInput('link', 'Link (optional)'),
                ]),
        ];
    }

    /**
     * A chip whose link no Menu could hold is still drawn, as a plain word.
     */
    public static function props(array $data): array
    {
        return [
            'heading' => self::text($data, 'heading'),
            'chips' => self::rows(['chips' => self::chips(is_array($data['chips'] ?? null) ? $data['chips'] : [])], 'chips', fn (array $chip): ?array => self::text($chip, 'label') === null ? null : [
                'label' => self::text($chip, 'label'),
                'href' => self::button($chip, 'label', 'link')['href'] ?? null,
            ]),
        ];
    }

    /**
     * The chips as rows, a bare word stored before chips could link read as
     * a chip with no link.
     *
     * @param  array<array-key, mixed>  $chips
     * @return array<array-key, mixed>
     */
    private static function chips(array $chips): array
    {
        return array_map(fn (mixed $chip): mixed => is_string($chip) ? ['label' => $chip] : $chip, $chips);
    }
}
