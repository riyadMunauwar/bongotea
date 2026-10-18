<?php

namespace App\Support\Pages\Blocks;

use App\Enums\BlockGroup;
use App\Support\Pages\PageBlock;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

/**
 * Where to find the store: a Google Maps embed beside the Store settings'
 * address and the opening hours, day by day.
 */
class MapAndAddress extends PageBlock
{
    /** Only Google's own embed address is ever framed. */
    public const MAP_EMBED = '#^https://(www\.)?google\.com/maps/embed\?\S+$#';

    public static function type(): string
    {
        return 'map';
    }

    public static function label(): string
    {
        return 'Map and address';
    }

    public static function icon(): Heroicon
    {
        return Heroicon::OutlinedMapPin;
    }

    public static function group(): BlockGroup
    {
        return BlockGroup::ContactAndAccount;
    }

    public static function description(): string
    {
        return 'A Google Map beside the store\'s address and opening hours.';
    }

    public static function fields(): array
    {
        return [
            TextInput::make('embed_url')
                ->label('Google Maps embed link')
                ->helperText('In Google Maps: Share → Embed a map, and copy the address inside src="…".')
                ->maxLength(2000)
                ->regex(self::MAP_EMBED),

            Repeater::make('hours')
                ->label('Opening hours')
                ->helperText('Leave empty to show the hours from Store settings.')
                ->maxItems(7)
                ->defaultItems(0)
                ->columns(2)
                ->schema([
                    TextInput::make('days')->required()->placeholder('শনি – বৃহস্পতি')->maxLength(60),
                    TextInput::make('hours')->required()->placeholder('সকাল ৯টা – রাত ৯টা')->maxLength(60),
                ]),
        ];
    }

    public static function props(array $data): array
    {
        $embed = self::text($data, 'embed_url');

        return [
            'embedUrl' => $embed !== null && preg_match(self::MAP_EMBED, $embed) === 1 ? $embed : null,
            'hours' => self::rows($data, 'hours', fn (array $row): ?array => self::text($row, 'days') === null ? null : [
                'days' => self::text($row, 'days'),
                'hours' => self::text($row, 'hours') ?? '',
            ]),
        ];
    }
}
