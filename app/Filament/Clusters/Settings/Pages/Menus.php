<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enums\MenuLinkType;
use App\Models\Page;
use App\Support\Settings\MenuSettings;
use App\Support\Storefront\StorefrontMenus;
use BackedEnum;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * The storefront's footer links, until the footer becomes a place under
 * Pages that Menus are assigned to. The header's navigation is a Menu,
 * built under Pages → Menus and assigned on Pages → Header.
 *
 * Each entry names what it points at — a Page by reference, a section anchor,
 * or a typed URL — and the storefront works out the address, so renaming a
 * Page's slug never breaks a link and unpublishing it takes the entry off.
 */
class Menus extends SettingsPage
{
    protected static string $group = MenuSettings::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBars3;

    protected static ?string $navigationLabel = 'Footer links';

    protected static ?string $title = 'Footer links';

    protected static ?int $navigationSort = 2;

    /**
     * @return array<Section>
     */
    protected function formComponents(): array
    {
        return [
            Section::make('Footer')
                ->description('The blurb beside the store name, the link columns, and the links on the very last line.')
                ->schema([
                    Textarea::make('footerBlurb')
                        ->label('Blurb')
                        ->rows(2)
                        ->maxLength(240),

                    Repeater::make('footerColumns')
                        ->label('Columns')
                        ->addActionLabel('Add a column')
                        ->defaultItems(0)
                        ->reorderable()
                        ->collapsible()
                        ->itemLabel(fn (array $state): ?string => $state['heading'] ?? null)
                        ->schema([
                            TextInput::make('heading')
                                ->label('Heading')
                                ->maxLength(40),

                            self::linkList('links', 'Links'),
                        ]),

                    self::linkList('footerBottomLinks', 'Bottom line links'),
                ]),
        ];
    }

    private static function linkList(string $name, string $label): Repeater
    {
        return Repeater::make($name)
            ->label($label)
            ->addActionLabel('Add a link')
            ->defaultItems(0)
            ->reorderable()
            ->columns(2)
            ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
            ->schema(self::linkFields(MenuLinkType::targets()));
    }

    /**
     * A label, what it points at, and the one field that type reads.
     *
     * @param  list<MenuLinkType>  $types
     * @return list<Component>
     */
    private static function linkFields(array $types): array
    {
        $options = [];

        foreach ($types as $type) {
            $options[$type->value] = $type->getLabel();
        }

        return [
            TextInput::make('label')
                ->label('Label')
                ->required()
                ->maxLength(40),

            Select::make('type')
                ->label('Points at')
                ->options($options)
                ->default(MenuLinkType::Page->value)
                ->required()
                ->live(),

            Select::make('page_id')
                ->label(fn (Get $get): string => $get('type') === MenuLinkType::Anchor->value ? 'On page' : 'Page')
                ->options(fn (): array => Page::query()->orderBy('title')->pluck('title', 'id')->all())
                ->searchable()
                ->visible(self::typeIsOneOf(MenuLinkType::Page, MenuLinkType::Anchor))
                ->required(self::typeIsOneOf(MenuLinkType::Page))
                ->helperText(fn (Get $get): ?string => $get('type') === MenuLinkType::Anchor->value ? 'Leave empty for the home page.' : 'Hidden on the storefront while the page is a draft.'),

            TextInput::make('anchor')
                ->label('Anchor')
                ->prefix('#')
                ->regex('/^#?[A-Za-z0-9_-]+$/')
                ->validationMessages(['regex' => 'Letters, digits, hyphens and underscores only.'])
                ->visible(self::typeIsOneOf(MenuLinkType::Anchor))
                ->required(self::typeIsOneOf(MenuLinkType::Anchor))
                ->helperText('The anchor a block on that page was given.'),

            TextInput::make('url')
                ->label('URL')
                ->regex(StorefrontMenus::ALLOWED_URL)
                ->validationMessages(['regex' => 'Start with / for this site, or https://, tel: or mailto:.'])
                ->maxLength(500)
                ->visible(self::typeIsOneOf(MenuLinkType::Url))
                ->required(self::typeIsOneOf(MenuLinkType::Url))
                ->columnSpanFull(),
        ];
    }

    /**
     * A visibility or requirement condition: true while the row's type is one
     * of these.
     */
    private static function typeIsOneOf(MenuLinkType ...$types): Closure
    {
        $values = array_map(fn (MenuLinkType $type): string => $type->value, $types);

        return fn (Get $get): bool => in_array($get('type'), $values, true);
    }
}
