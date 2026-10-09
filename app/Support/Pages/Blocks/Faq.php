<?php

namespace App\Support\Pages\Blocks;

use App\Support\Pages\PageBlock;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

/**
 * Questions that open to their answers.
 */
class Faq extends PageBlock
{
    public static function type(): string
    {
        return 'faq';
    }

    public static function label(): string
    {
        return 'FAQ';
    }

    public static function icon(): Heroicon
    {
        return Heroicon::OutlinedQuestionMarkCircle;
    }

    public static function fields(): array
    {
        return [
            TextInput::make('eyebrow')->maxLength(120),
            TextInput::make('heading')->maxLength(160),

            Repeater::make('items')
                ->label('Questions')
                ->minItems(1)
                ->maxItems(30)
                ->collapsible()
                ->itemLabel(fn (array $state): ?string => $state['question'] ?? null)
                ->schema([
                    TextInput::make('question')->required()->maxLength(200),
                    Textarea::make('answer')->required()->rows(3)->maxLength(2000),
                ]),
        ];
    }

    public static function props(array $data): array
    {
        return [
            'eyebrow' => self::text($data, 'eyebrow'),
            'heading' => self::text($data, 'heading'),
            'items' => self::rows($data, 'items', fn (array $item): ?array => self::text($item, 'question') === null || self::text($item, 'answer') === null ? null : [
                'question' => self::text($item, 'question'),
                'answer' => self::text($item, 'answer'),
            ]),
        ];
    }
}
