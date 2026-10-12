<?php

namespace App\Support\Pages\Blocks;

use App\Support\Pages\PageBlock;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

/**
 * The dark band that asks for the order: a question, a line, and up to four
 * buttons.
 */
class CallToActionBand extends PageBlock
{
    public static function type(): string
    {
        return 'cta_band';
    }

    public static function label(): string
    {
        return 'Call-to-action band';
    }

    public static function icon(): Heroicon
    {
        return Heroicon::OutlinedMegaphone;
    }

    public static function fields(): array
    {
        return [
            TextInput::make('heading')->required()->maxLength(160),
            TextInput::make('text')->maxLength(300),
            TextInput::make('primary_label')->label('Main button')->maxLength(60),
            self::linkInput('primary_link', 'Main button link'),
            TextInput::make('secondary_label')->label('Second button')->maxLength(60),
            self::linkInput('secondary_link', 'Second button link'),
            self::moreButtonsInput(),
        ];
    }

    public static function props(array $data): array
    {
        return [
            'heading' => self::text($data, 'heading'),
            'text' => self::text($data, 'text'),
            'primary' => self::button($data, 'primary_label', 'primary_link'),
            'secondary' => self::button($data, 'secondary_label', 'secondary_link'),
            'more' => self::moreButtons($data),
        ];
    }
}
