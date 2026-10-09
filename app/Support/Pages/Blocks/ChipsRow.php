<?php

namespace App\Support\Pages\Blocks;

use App\Support\Pages\PageBlock;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

/**
 * A centred row of words in pills — who the store is for.
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
                ->simple(TextInput::make('label')->required()->maxLength(60))
                ->minItems(1)
                ->maxItems(20),
        ];
    }

    public static function props(array $data): array
    {
        $chips = is_array($data['chips'] ?? null) ? $data['chips'] : [];

        return [
            'heading' => self::text($data, 'heading'),
            'chips' => array_values(array_filter(array_map(
                fn (mixed $chip): ?string => is_string($chip) && trim($chip) !== '' ? trim($chip) : null,
                array_values($chips),
            ))),
        ];
    }
}
