<?php

namespace App\Support\Pages\Blocks;

use App\Models\Page;
use App\Support\Pages\PageBlock;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

/**
 * Recipes, each a card that opens to what goes in it and how it is made: its
 * facts at a glance, the ingredients and their quantities, numbered steps
 * and the tips under their own headings.
 *
 * Long lists of recipes are split across several of these, a group to each
 * — milk teas, plain teas, iced teas — so no one Block is unwieldy to edit.
 */
class RecipeCards extends PageBlock
{
    public static function type(): string
    {
        return 'recipe_cards';
    }

    public static function label(): string
    {
        return 'Recipe cards';
    }

    public static function icon(): Heroicon
    {
        return Heroicon::OutlinedBeaker;
    }

    public static function fields(): array
    {
        return [
            TextInput::make('eyebrow')->maxLength(120),
            TextInput::make('heading')->maxLength(160),
            Textarea::make('text')->rows(2)->maxLength(600),

            Repeater::make('recipes')
                ->minItems(1)
                ->maxItems(15)
                ->defaultItems(1)
                ->collapsible()
                ->collapsed()
                ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(120),
                    TextInput::make('english_name')->label('English name')->maxLength(120),
                    TextInput::make('anchor')
                        ->helperText('Lets a link point at this recipe, as page#anchor.')
                        ->maxLength(64)
                        ->regex('/^'.Page::SLUG.'$/'),
                    TextInput::make('tea')->label('Tea to use')->maxLength(120),
                    TextInput::make('serves')->maxLength(60),
                    TextInput::make('time')->maxLength(60),
                    TextInput::make('taste')->maxLength(200)->columnSpanFull(),

                    Repeater::make('ingredients')
                        ->columnSpanFull()
                        ->columns(2)
                        ->maxItems(20)
                        ->schema([
                            TextInput::make('item')->required()->maxLength(120),
                            TextInput::make('quantity')->maxLength(80),
                        ]),

                    Repeater::make('steps')
                        ->columnSpanFull()
                        ->simple(Textarea::make('text')->rows(2)->required()->maxLength(400))
                        ->maxItems(15),

                    Repeater::make('tips')
                        ->columnSpanFull()
                        ->columns(2)
                        ->maxItems(5)
                        ->schema([
                            TextInput::make('label')->helperText('টিপস, পরিবেশন পরামর্শ, ব্যবসায়িক টিপস…')->maxLength(60),
                            Textarea::make('text')->rows(2)->required()->maxLength(600),
                        ]),
                ]),
        ];
    }

    public static function props(array $data): array
    {
        return [
            'eyebrow' => self::text($data, 'eyebrow'),
            'heading' => self::text($data, 'heading'),
            'text' => self::text($data, 'text'),
            'recipes' => self::rows($data, 'recipes', fn (array $recipe): ?array => self::text($recipe, 'name') === null ? null : [
                'name' => self::text($recipe, 'name'),
                'englishName' => self::text($recipe, 'english_name'),
                'anchor' => self::anchor($recipe),
                'tea' => self::text($recipe, 'tea'),
                'serves' => self::text($recipe, 'serves'),
                'time' => self::text($recipe, 'time'),
                'taste' => self::text($recipe, 'taste'),
                'ingredients' => self::rows($recipe, 'ingredients', fn (array $ingredient): ?array => self::text($ingredient, 'item') === null ? null : [
                    'item' => self::text($ingredient, 'item'),
                    'quantity' => self::text($ingredient, 'quantity'),
                ]),
                'steps' => self::strings($recipe, 'steps'),
                'tips' => self::rows($recipe, 'tips', fn (array $tip): ?array => self::text($tip, 'text') === null ? null : [
                    'label' => self::text($tip, 'label'),
                    'text' => self::text($tip, 'text'),
                ]),
            ]),
        ];
    }
}
