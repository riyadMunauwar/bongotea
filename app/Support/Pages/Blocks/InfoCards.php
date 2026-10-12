<?php

namespace App\Support\Pages\Blocks;

use App\Models\Page;
use App\Support\Pages\PageBlock;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

/**
 * Cards with room to explain — a Grade, a tea type, who a tea is for, a rule
 * for keeping it — each with its own lists, a note set apart and a button,
 * one, two or three across.
 *
 * A card may carry its own anchor, so a Menu or a button elsewhere can point
 * straight at one card (`/tea-grades#bop`) rather than at the whole Block.
 */
class InfoCards extends PageBlock
{
    public static function type(): string
    {
        return 'info_cards';
    }

    public static function label(): string
    {
        return 'Info cards';
    }

    public static function icon(): Heroicon
    {
        return Heroicon::OutlinedRectangleStack;
    }

    public static function fields(): array
    {
        return [
            TextInput::make('eyebrow')->maxLength(120),
            TextInput::make('heading')->maxLength(160),
            Textarea::make('text')->rows(2)->maxLength(600),
            Select::make('columns')
                ->options(['1' => 'One across', '2' => 'Two across', '3' => 'Three across'])
                ->default('2')
                ->selectablePlaceholder(false),

            Repeater::make('cards')
                ->minItems(1)
                ->maxItems(12)
                ->defaultItems(2)
                ->collapsible()
                ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                ->columns(2)
                ->schema([
                    TextInput::make('badge')->helperText('A number or one glyph: ১ ☕ ✓')->maxLength(4),
                    TextInput::make('anchor')
                        ->helperText('Lets a link point at this card, as page#anchor.')
                        ->maxLength(64)
                        ->regex('/^'.Page::SLUG.'$/'),
                    TextInput::make('eyebrow')->maxLength(120),
                    TextInput::make('title')->required()->maxLength(120),
                    TextInput::make('subtitle')->maxLength(200)->columnSpanFull(),
                    Textarea::make('text')->rows(3)->maxLength(1200)->columnSpanFull(),

                    Repeater::make('lists')
                        ->maxItems(3)
                        ->defaultItems(0)
                        ->columnSpanFull()
                        ->schema([
                            TextInput::make('label')->maxLength(80),
                            Repeater::make('items')
                                ->simple(TextInput::make('text')->required()->maxLength(200))
                                ->minItems(1)
                                ->maxItems(12),
                        ]),

                    TextInput::make('note_label')->label('Note heading')->maxLength(60),
                    Textarea::make('note')->rows(2)->maxLength(400),
                    TextInput::make('button_label')->label('Button')->maxLength(60),
                    self::linkInput('button_link', 'Button link'),
                ]),
        ];
    }

    public static function props(array $data): array
    {
        $columns = (int) ($data['columns'] ?? 2);

        return [
            'eyebrow' => self::text($data, 'eyebrow'),
            'heading' => self::text($data, 'heading'),
            'text' => self::text($data, 'text'),
            'columns' => in_array($columns, [1, 2, 3], true) ? $columns : 2,
            'cards' => self::rows($data, 'cards', fn (array $card): ?array => self::text($card, 'title') === null ? null : [
                'badge' => self::text($card, 'badge'),
                'anchor' => self::anchor($card),
                'eyebrow' => self::text($card, 'eyebrow'),
                'title' => self::text($card, 'title'),
                'subtitle' => self::text($card, 'subtitle'),
                'text' => self::text($card, 'text'),
                'lists' => self::rows($card, 'lists', function (array $list): ?array {
                    $items = self::strings($list, 'items');

                    return $items === [] ? null : ['label' => self::text($list, 'label'), 'items' => $items];
                }),
                'note' => self::text($card, 'note') === null ? null : [
                    'label' => self::text($card, 'note_label'),
                    'text' => self::text($card, 'note'),
                ],
                'button' => self::button($card, 'button_label', 'button_link'),
            ]),
        ];
    }
}
