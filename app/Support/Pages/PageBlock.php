<?php

namespace App\Support\Pages;

use App\Models\Page;
use App\Support\Storefront\StorefrontMenus;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

/**
 * One kind of Block: what the editor asks for, and what the storefront is
 * handed to draw it.
 *
 * The editor's answers are stored as typed; `props()` turns them into the
 * component's props — image paths into addresses, links checked again,
 * rich text sanitised — and never passes a stored key through unread, so
 * nothing the component does not expect reaches the page. Every kind is
 * also given a place: an anchor a Menu can link to, and whether it is hidden
 * on a phone or on a wider screen.
 */
abstract class PageBlock
{
    /** The public disk a Block's images are stored on (ADR-0011); the Block keeps only the path. */
    public const IMAGE_DISK = 'local';

    public const IMAGE_DIRECTORY = 'pages';

    /** A YouTube video id, or a watch, share, embed or Shorts link carrying one. */
    public const YOUTUBE = '#^(?:https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/)|youtu\.be/))?([A-Za-z0-9_-]{11})(?:[?&\#].*)?$#';

    /** The stored name of this kind, and of its React component. */
    abstract public static function type(): string;

    abstract public static function label(): string;

    abstract public static function icon(): Heroicon;

    /**
     * @return list<Component>
     */
    abstract public static function fields(): array;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    abstract public static function props(array $data): array;

    public static function builderBlock(): Block
    {
        return Block::make(static::type())
            ->label(static::label())
            ->icon(static::icon())
            ->schema([...static::fields(), self::placementFields()]);
    }

    private static function placementFields(): Fieldset
    {
        return Fieldset::make('Placement')
            ->columns(3)
            ->schema([
                TextInput::make('anchor')
                    ->helperText('Lets a Menu link straight to this Block, as page#anchor.')
                    ->maxLength(64)
                    ->regex('/^'.Page::SLUG.'$/'),

                Toggle::make('hide_on_mobile')
                    ->label('Hide on phones')
                    ->inline(false),

                Toggle::make('hide_on_desktop')
                    ->label('Hide on wider screens')
                    ->inline(false),
            ]);
    }

    /**
     * A plain field's text, or null when it was left blank.
     *
     * @param  array<string, mixed>  $data
     */
    protected static function text(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * A repeater's rows, each read through `$row`; a row that comes back
     * null is left out.
     *
     * @param  array<string, mixed>  $data
     * @param  callable(array<string, mixed>): ?array<string, mixed>  $row
     * @return list<array<string, mixed>>
     */
    protected static function rows(array $data, string $key, callable $row): array
    {
        $rows = is_array($data[$key] ?? null) ? $data[$key] : [];

        return array_values(array_filter(array_map(
            fn (mixed $item): ?array => is_array($item) ? $row($item) : null,
            array_values($rows),
        )));
    }

    /**
     * A button: its label and where it leads, or null unless both were given
     * and the address is one a Menu could hold.
     *
     * @param  array<string, mixed>  $data
     * @return array{label: string, href: string}|null
     */
    protected static function button(array $data, string $labelKey, string $linkKey): ?array
    {
        $label = self::text($data, $labelKey);
        $href = self::text($data, $linkKey);

        if ($label === null || $href === null || preg_match(StorefrontMenus::ALLOWED_URL, $href) !== 1) {
            return null;
        }

        return ['label' => $label, 'href' => $href];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function imageUrl(array $data, string $key): ?string
    {
        $path = self::text($data, $key);

        return $path === null ? null : url(Storage::disk(self::IMAGE_DISK)->url($path));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function youtubeId(array $data, string $key): ?string
    {
        $value = self::text($data, $key);

        return $value !== null && preg_match(self::YOUTUBE, $value, $match) === 1 ? $match[1] : null;
    }

    protected static function linkInput(string $name, string $label = 'Link'): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->placeholder('/bulk-order')
            ->maxLength(255)
            ->regex(StorefrontMenus::ALLOWED_URL)
            ->helperText('A path here (/pricing), or an http(s), tel: or mailto: address.');
    }

    protected static function imageInput(string $name, string $label = 'Image'): FileUpload
    {
        return FileUpload::make($name)
            ->label($label)
            ->disk(self::IMAGE_DISK)
            ->directory(self::IMAGE_DIRECTORY)
            ->visibility('public')
            ->image()
            ->maxSize(4096)
            ->imagePreviewHeight('80');
    }

    protected static function videoInput(string $name, string $label = 'YouTube video'): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->maxLength(255)
            ->regex(self::YOUTUBE)
            ->helperText('A YouTube link or its 11-character video id.');
    }
}
