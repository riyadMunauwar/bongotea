<?php

namespace App\Support\Pages\Blocks;

use App\Enums\ImageSlot;
use App\Support\Pages\PageBlock;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

/**
 * The hero: slides that advance on their own, swipe on a phone and carry
 * dots — or, laid out as a banner, one dark page header like About's.
 */
class HeroCarousel extends PageBlock
{
    public static function type(): string
    {
        return 'hero_carousel';
    }

    public static function label(): string
    {
        return 'Hero carousel';
    }

    public static function icon(): Heroicon
    {
        return Heroicon::OutlinedPhoto;
    }

    public static function fields(): array
    {
        return [
            Select::make('layout')
                ->options(['carousel' => 'Carousel', 'banner' => 'Page header banner (first slide only)'])
                ->default('carousel')
                ->selectablePlaceholder(false)
                ->required(),

            Repeater::make('slides')
                ->minItems(1)
                ->maxItems(6)
                ->defaultItems(1)
                ->collapsible()
                ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                ->columns(2)
                ->schema([
                    TextInput::make('eyebrow')->maxLength(120),
                    TextInput::make('title')->required()->maxLength(160),
                    Textarea::make('text')->rows(2)->maxLength(400)->columnSpanFull(),
                    self::imageInput('image', ImageSlot::HeroSlide)->columnSpanFull(),
                    TextInput::make('primary_label')->label('Main button')->maxLength(60),
                    self::linkInput('primary_link', 'Main button link'),
                    TextInput::make('secondary_label')->label('Second button')->maxLength(60),
                    self::linkInput('secondary_link', 'Second button link'),
                    self::moreButtonsInput(),
                ]),
        ];
    }

    public static function props(array $data): array
    {
        return [
            'layout' => ($data['layout'] ?? null) === 'banner' ? 'banner' : 'carousel',
            'slides' => self::rows($data, 'slides', fn (array $slide): ?array => self::text($slide, 'title') === null ? null : [
                'eyebrow' => self::text($slide, 'eyebrow'),
                'title' => self::text($slide, 'title'),
                'text' => self::text($slide, 'text'),
                'imageUrl' => self::imageUrl($slide, 'image'),
                'primary' => self::button($slide, 'primary_label', 'primary_link'),
                'secondary' => self::button($slide, 'secondary_label', 'secondary_link'),
                'more' => self::moreButtons($slide),
            ]),
        ];
    }
}
