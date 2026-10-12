<?php

namespace App\Support\Pages\Blocks;

use App\Enums\ImageSlot;
use App\Support\Pages\PageBlock;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

/**
 * Buyers on camera: a row of tall cards that swipes sideways, each opening
 * its video in a modal.
 */
class VideoReviews extends PageBlock
{
    public static function type(): string
    {
        return 'video_reviews';
    }

    public static function label(): string
    {
        return 'Video reviews';
    }

    public static function icon(): Heroicon
    {
        return Heroicon::OutlinedVideoCamera;
    }

    public static function fields(): array
    {
        return [
            TextInput::make('eyebrow')->maxLength(120),
            TextInput::make('heading')->required()->maxLength(160),
            TextInput::make('hint')->helperText('The line beside the arrows.')->maxLength(160),

            Repeater::make('reviews')
                ->minItems(1)
                ->maxItems(12)
                ->collapsible()
                ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(80),
                    TextInput::make('place')->helperText('Their shop and town.')->maxLength(120),
                    TextInput::make('product')->label('Tea they buy')->maxLength(80),
                    TextInput::make('duration')->placeholder('১:২৪')->maxLength(10),
                    TextInput::make('quote')->required()->maxLength(120)->columnSpanFull(),
                    self::videoInput('video')->required(),
                    self::imageInput('image', ImageSlot::PortraitCard, 'Cover image'),
                ]),
        ];
    }

    public static function props(array $data): array
    {
        return [
            'eyebrow' => self::text($data, 'eyebrow'),
            'heading' => self::text($data, 'heading'),
            'hint' => self::text($data, 'hint'),
            'reviews' => self::rows($data, 'reviews', fn (array $review): ?array => self::text($review, 'name') === null ? null : [
                'name' => self::text($review, 'name'),
                'place' => self::text($review, 'place'),
                'product' => self::text($review, 'product'),
                'duration' => self::text($review, 'duration'),
                'quote' => self::text($review, 'quote'),
                'videoId' => self::youtubeId($review, 'video'),
                'imageUrl' => self::imageUrl($review, 'image'),
            ]),
        ];
    }
}
