<?php

namespace App\Support\Pages\Blocks;

use App\Support\Pages\PageBlock;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

/**
 * Words: a short line set large on one side and rich text on the other, as
 * About tells the store's story. With no line, the text runs alone.
 */
class Story extends PageBlock
{
    public static function type(): string
    {
        return 'story';
    }

    public static function label(): string
    {
        return 'Story / rich text';
    }

    public static function icon(): Heroicon
    {
        return Heroicon::OutlinedDocumentText;
    }

    public static function fields(): array
    {
        return [
            TextInput::make('eyebrow')->maxLength(120),
            TextInput::make('quote')->label('Large line')->maxLength(200),
            RichEditor::make('body')
                ->toolbarButtons([['bold', 'italic', 'link'], ['h2', 'h3'], ['bulletList', 'orderedList', 'blockquote'], ['undo', 'redo']]),
        ];
    }

    /**
     * The body is drawn as HTML, so it is sanitised on the way out whatever
     * was stored.
     */
    public static function props(array $data): array
    {
        $body = $data['body'] ?? null;
        $html = is_string($body) || is_array($body) ? RichContentRenderer::make($body)->toHtml() : '';

        return [
            'eyebrow' => self::text($data, 'eyebrow'),
            'quote' => self::text($data, 'quote'),
            'bodyHtml' => trim(strip_tags($html)) === '' ? null : $html,
        ];
    }
}
