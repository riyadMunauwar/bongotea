<?php

namespace App\Support\Pages\Blocks;

use App\Support\Pages\PageBlock;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

/**
 * What the store stands for, as cards in a row of three.
 */
class ValueCards extends PageBlock
{
    public static function type(): string
    {
        return 'value_cards';
    }

    public static function label(): string
    {
        return 'Value cards';
    }

    public static function icon(): Heroicon
    {
        return Heroicon::OutlinedSquares2x2;
    }

    public static function fields(): array
    {
        return [
            TextInput::make('eyebrow')->maxLength(120),
            TextInput::make('heading')->maxLength(160),

            Repeater::make('cards')
                ->minItems(1)
                ->maxItems(9)
                ->defaultItems(3)
                ->columns(3)
                ->schema([
                    TextInput::make('icon')->helperText('One glyph: ✓ ৳ →')->maxLength(2),
                    TextInput::make('title')->required()->maxLength(120),
                    TextInput::make('text')->maxLength(300),
                ]),
        ];
    }

    public static function props(array $data): array
    {
        return [
            'eyebrow' => self::text($data, 'eyebrow'),
            'heading' => self::text($data, 'heading'),
            'cards' => self::rows($data, 'cards', fn (array $card): ?array => self::text($card, 'title') === null ? null : [
                'icon' => self::text($card, 'icon'),
                'title' => self::text($card, 'title'),
                'text' => self::text($card, 'text'),
            ]),
        ];
    }
}
