<?php

namespace App\Support\Pages\Blocks;

use App\Enums\BlockGroup;
use App\Enums\ImageSlot;
use App\Support\Pages\PageBlock;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Support\Icons\Heroicon;

/**
 * The tea's journey as numbered pictures, garden to shop.
 */
class Journey extends PageBlock
{
    public static function type(): string
    {
        return 'journey';
    }

    public static function label(): string
    {
        return 'Journey';
    }

    public static function icon(): Heroicon
    {
        return Heroicon::OutlinedMap;
    }

    public static function group(): BlockGroup
    {
        return BlockGroup::Trust;
    }

    public static function description(): string
    {
        return 'The tea\'s journey from garden to shop, as numbered pictures.';
    }

    public static function fields(): array
    {
        return [
            TextInput::make('eyebrow')->maxLength(120),
            TextInput::make('heading')->maxLength(160),

            Repeater::make('steps')
                ->minItems(1)
                ->maxItems(8)
                ->defaultItems(4)
                ->columns(2)
                ->schema([
                    Group::make([
                        TextInput::make('title')->required()->maxLength(120),
                        TextInput::make('text')->maxLength(300),
                    ]),
                    self::imageInput('image', ImageSlot::PortraitCard),
                ]),
        ];
    }

    public static function props(array $data): array
    {
        return [
            'eyebrow' => self::text($data, 'eyebrow'),
            'heading' => self::text($data, 'heading'),
            'steps' => self::rows($data, 'steps', fn (array $step): ?array => self::text($step, 'title') === null ? null : [
                'title' => self::text($step, 'title'),
                'text' => self::text($step, 'text'),
                'imageUrl' => self::imageUrl($step, 'image'),
            ]),
        ];
    }
}
