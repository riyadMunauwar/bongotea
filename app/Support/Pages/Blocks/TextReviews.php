<?php

namespace App\Support\Pages\Blocks;

use App\Enums\BlockGroup;
use App\Support\Pages\PageBlock;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Support\Icons\Heroicon;

/**
 * What shopkeepers say, as cards. "Verified buyer" is the Merchant's word
 * per review, never assumed: a review shows it only when switched on.
 */
class TextReviews extends PageBlock
{
    public static function type(): string
    {
        return 'text_reviews';
    }

    public static function label(): string
    {
        return 'Text reviews';
    }

    public static function icon(): Heroicon
    {
        return Heroicon::OutlinedChatBubbleLeftRight;
    }

    public static function group(): BlockGroup
    {
        return BlockGroup::Trust;
    }

    public static function description(): string
    {
        return 'What shopkeepers say, as review cards.';
    }

    public static function fields(): array
    {
        return [
            TextInput::make('heading')->required()->maxLength(160),
            TextInput::make('rating_summary')
                ->label('Rating line')
                ->helperText('Shown beside five stars under the heading; leave blank for none.')
                ->maxLength(120),
            TextInput::make('note')->helperText('The line on the right.')->maxLength(160),

            Repeater::make('reviews')
                ->minItems(1)
                ->maxItems(12)
                ->collapsible()
                ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                ->columns(3)
                ->schema([
                    TextInput::make('name')->required()->maxLength(80),
                    TextInput::make('place')->maxLength(120),
                    TextInput::make('product')->label('Tea they bought')->maxLength(80),
                    Textarea::make('text')->required()->rows(2)->maxLength(500)->columnSpanFull(),
                    Toggle::make('verified')->label('Verified buyer'),
                ]),
        ];
    }

    public static function props(array $data): array
    {
        return [
            'heading' => self::text($data, 'heading'),
            'ratingSummary' => self::text($data, 'rating_summary'),
            'note' => self::text($data, 'note'),
            'reviews' => self::rows($data, 'reviews', fn (array $review): ?array => self::text($review, 'text') === null ? null : [
                'name' => self::text($review, 'name') ?? '',
                'place' => self::text($review, 'place'),
                'product' => self::text($review, 'product'),
                'text' => self::text($review, 'text'),
                'verified' => (bool) ($review['verified'] ?? false),
            ]),
        ];
    }
}
