<?php

namespace App\Support\Pages;

use App\Enums\ImageSlot;
use App\Enums\OrderKind;
use App\Models\Cart;
use App\Models\Page;
use App\Support\Storefront\StorefrontMenus;
use Closure;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
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
     * An anchor a link can point at inside the Block, or null unless it is
     * shaped like a slug.
     *
     * @param  array<string, mixed>  $data
     */
    protected static function anchor(array $data): ?string
    {
        $anchor = self::text($data, 'anchor');

        return $anchor !== null && preg_match('/^'.Page::SLUG.'$/', $anchor) === 1 ? $anchor : null;
    }

    /**
     * A simple repeater's words, trimmed, with the blank ones left out.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    protected static function strings(array $data, string $key): array
    {
        $values = is_array($data[$key] ?? null) ? array_values($data[$key]) : [];

        return array_values(array_filter(array_map(
            fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null,
            $values,
        )));
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
     * The buttons after the first two, each drawn like the second.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{label: string, href: string}>
     */
    protected static function moreButtons(array $data): array
    {
        return self::rows($data, 'more_buttons', fn (array $button): ?array => self::button($button, 'label', 'link'));
    }

    protected static function moreButtonsInput(): Repeater
    {
        return Repeater::make('more_buttons')
            ->label('More buttons')
            ->maxItems(2)
            ->defaultItems(0)
            ->columns(2)
            ->columnSpanFull()
            ->schema([
                TextInput::make('label')->required()->maxLength(60),
                self::linkInput('link')->required(),
            ]);
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

    /** Sized to where the Block draws it: the slot says the shape and opens the crop editor at it. */
    protected static function imageInput(string $name, ImageSlot $slot, string $label = 'Image'): FileUpload
    {
        return $slot->fit(FileUpload::make($name))
            ->label($label)
            ->disk(self::IMAGE_DISK)
            ->directory(self::IMAGE_DIRECTORY)
            ->visibility('public')
            ->image()
            ->maxSize(4096);
    }

    /**
     * Which Cart of a kind the Block draws. A Cart stands on one Page at most
     * (ADR-0038), so one another Page already draws is refused, naming it.
     */
    protected static function cartInput(OrderKind $kind): Select
    {
        return Select::make('cart_id')
            ->label('Cart')
            ->required()
            ->options(fn (): array => Cart::query()->where('kind', $kind)->orderBy('sort_order')->orderBy('id')->pluck('name', 'id')->all())
            ->rule(fn (?Page $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                $other = is_numeric($value) ? CartPlacements::pageOf((int) $value, $record?->getKey()) : null;

                if ($other !== null) {
                    $fail("This Cart already stands on the Page \"{$other->title}\". A Cart stands on one Page at most.");
                }
            });
    }

    /**
     * The Cart a Block draws, as its component needs it, or null where none
     * of that kind is named or it no longer exists at all. A deleted Cart is
     * still found, and drawn closed.
     *
     * @param  array<string, mixed>  $data
     * @return array{id: int, name: string, kind: string, isOpen: bool}|null
     */
    protected static function cart(array $data, OrderKind $kind): ?array
    {
        $cartId = $data['cart_id'] ?? null;
        $cart = is_numeric($cartId) ? Cart::withTrashed()->where('kind', $kind)->find((int) $cartId) : null;

        return $cart === null ? null : [
            'id' => $cart->getKey(),
            'name' => $cart->name,
            'kind' => $cart->kind->value,
            'isOpen' => $cart->isOpen(),
        ];
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
