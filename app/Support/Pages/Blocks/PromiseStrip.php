<?php

namespace App\Support\Pages\Blocks;

use App\Support\Pages\PageBlock;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

/**
 * The strip of up to four promises under the hero: a glyph, a short claim
 * and a line saying what it means.
 */
class PromiseStrip extends PageBlock
{
    public static function type(): string
    {
        return 'promise_strip';
    }

    public static function label(): string
    {
        return 'Promise strip';
    }

    public static function icon(): Heroicon
    {
        return Heroicon::OutlinedCheckBadge;
    }

    public static function fields(): array
    {
        return [
            Repeater::make('items')
                ->label('Promises')
                ->minItems(1)
                ->maxItems(4)
                ->defaultItems(4)
                ->columns(3)
                ->schema([
                    TextInput::make('icon')->helperText('One glyph: ৳ ✓ ⇄ ☎')->maxLength(2),
                    TextInput::make('title')->required()->maxLength(60),
                    TextInput::make('text')->maxLength(120),
                ]),
        ];
    }

    public static function props(array $data): array
    {
        return [
            'items' => self::rows($data, 'items', fn (array $item): ?array => self::text($item, 'title') === null ? null : [
                'icon' => self::text($item, 'icon'),
                'title' => self::text($item, 'title'),
                'text' => self::text($item, 'text'),
            ]),
        ];
    }
}
