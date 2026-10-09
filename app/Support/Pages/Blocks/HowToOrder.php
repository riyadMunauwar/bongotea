<?php

namespace App\Support\Pages\Blocks;

use App\Support\Pages\PageBlock;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Support\Icons\Heroicon;

/**
 * The numbered how-to-order steps, a button to start, a video that plays in
 * a modal, and the hotline for whoever would rather phone.
 */
class HowToOrder extends PageBlock
{
    public static function type(): string
    {
        return 'how_to_order';
    }

    public static function label(): string
    {
        return 'How-to-order steps';
    }

    public static function icon(): Heroicon
    {
        return Heroicon::OutlinedListBullet;
    }

    public static function fields(): array
    {
        return [
            TextInput::make('eyebrow')->maxLength(120),
            TextInput::make('heading')->required()->maxLength(160),
            Textarea::make('text')->rows(2)->maxLength(400),

            Repeater::make('steps')
                ->minItems(1)
                ->maxItems(6)
                ->defaultItems(3)
                ->columns(3)
                ->schema([
                    TextInput::make('title')->required()->maxLength(120),
                    TextInput::make('text')->maxLength(300),
                    TextInput::make('chip')->helperText('A short tag under the step.')->maxLength(60),
                ]),

            TextInput::make('cta_label')->label('Button')->maxLength(60),
            self::linkInput('cta_link', 'Button link'),
            self::videoInput('video', 'How-to video'),

            Toggle::make('show_hotline')
                ->label('Offer the hotline underneath')
                ->default(true),
        ];
    }

    public static function props(array $data): array
    {
        return [
            'eyebrow' => self::text($data, 'eyebrow'),
            'heading' => self::text($data, 'heading'),
            'text' => self::text($data, 'text'),
            'steps' => self::rows($data, 'steps', fn (array $step): ?array => self::text($step, 'title') === null ? null : [
                'title' => self::text($step, 'title'),
                'text' => self::text($step, 'text'),
                'chip' => self::text($step, 'chip'),
            ]),
            'cta' => self::button($data, 'cta_label', 'cta_link'),
            'videoId' => self::youtubeId($data, 'video'),
            'showHotline' => (bool) ($data['show_hotline'] ?? false),
        ];
    }
}
