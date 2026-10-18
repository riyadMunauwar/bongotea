<?php

namespace App\Support\Pages\Blocks;

use App\Enums\BlockGroup;
use App\Support\Pages\PageBlock;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

/**
 * Words laid out in rows and columns — a grade at a glance, how much tea for
 * how much water — drawn as a table on a wide screen and as stacked cards on
 * a phone.
 *
 * It holds only what the Merchant typed, so it is never where prices go: a
 * price table is the Price Matrix, which reads the Tiers as they are now.
 */
class Table extends PageBlock
{
    public const MAX_COLUMNS = 4;

    public static function type(): string
    {
        return 'table';
    }

    public static function label(): string
    {
        return 'Table';
    }

    public static function icon(): Heroicon
    {
        return Heroicon::OutlinedTableCells;
    }

    public static function group(): BlockGroup
    {
        return BlockGroup::Information;
    }

    public static function description(): string
    {
        return 'Words in rows and columns: a table on wide screens, stacked cards on phones.';
    }

    public static function fields(): array
    {
        return [
            TextInput::make('eyebrow')->maxLength(120),
            TextInput::make('heading')->maxLength(160),
            Textarea::make('text')->rows(2)->maxLength(400),

            Repeater::make('columns')
                ->label('Column headings')
                ->simple(TextInput::make('label')->required()->maxLength(60))
                ->minItems(2)
                ->maxItems(self::MAX_COLUMNS)
                ->defaultItems(2),

            Repeater::make('rows')
                ->minItems(1)
                ->maxItems(30)
                ->collapsible()
                ->itemLabel(fn (array $state): ?string => is_array($state['cells'] ?? null) ? (string) (array_values($state['cells'])[0] ?? '') : null)
                ->schema([
                    Repeater::make('cells')
                        ->helperText('One per column, in the same order as the headings.')
                        ->simple(TextInput::make('text')->maxLength(200))
                        ->minItems(1)
                        ->maxItems(self::MAX_COLUMNS),
                ]),

            Textarea::make('footnote')->rows(2)->maxLength(400),
            TextInput::make('button_label')->label('Button')->maxLength(60),
            self::linkInput('button_link', 'Button link'),
        ];
    }

    /**
     * Every row is cut or padded to the headings' width, so the component
     * never meets a ragged table.
     */
    public static function props(array $data): array
    {
        $columns = array_slice(self::strings($data, 'columns'), 0, self::MAX_COLUMNS);
        $width = count($columns);

        return [
            'eyebrow' => self::text($data, 'eyebrow'),
            'heading' => self::text($data, 'heading'),
            'text' => self::text($data, 'text'),
            'columns' => $columns,
            'rows' => $width === 0 ? [] : self::rows($data, 'rows', function (array $row) use ($width): ?array {
                $cells = array_map(
                    fn (mixed $cell): string => is_string($cell) ? trim($cell) : '',
                    array_values(is_array($row['cells'] ?? null) ? $row['cells'] : []),
                );

                if (implode('', $cells) === '') {
                    return null;
                }

                return ['cells' => array_pad(array_slice($cells, 0, $width), $width, '')];
            }),
            'footnote' => self::text($data, 'footnote'),
            'button' => self::button($data, 'button_label', 'button_link'),
        ];
    }
}
