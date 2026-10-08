<?php

namespace App\Filament\Resources\Categories\Schemas;

use App\Enums\CategoryTheme;
use App\Filament\Concerns\HasSeoFields;
use App\Filament\Concerns\HasSlugField;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class CategoryForm
{
    use HasSeoFields, HasSlugField;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Category')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        self::nameInput(),

                        self::slugInput(url('/category').'/'),

                        TextInput::make('note')
                            ->helperText('The short line under the name in menus.')
                            ->maxLength(255),

                        TextInput::make('quality_headline')
                            ->label('Quality headline')
                            ->maxLength(255),

                        Textarea::make('about')
                            ->rows(3)
                            ->columnSpanFull(),

                        Repeater::make('why_points')
                            ->label('Why choose it')
                            ->simple(TextInput::make('point')->required()->maxLength(255))
                            ->defaultItems(3)
                            ->maxItems(3)
                            ->reorderable()
                            ->columnSpanFull(),

                        TextInput::make('sort_order')
                            ->label('Sort order')
                            ->integer()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                    ]),

                Section::make('Category Theme')
                    ->description('The colours this Category wears wherever it is drawn. Themes are designed whole; pick one.')
                    ->columnSpanFull()
                    ->schema([
                        Radio::make('theme_key')
                            ->hiddenLabel()
                            ->options(self::themeSwatches())
                            ->required()
                            ->inline(),
                    ]),

                self::seoSection(),
            ]);
    }

    /**
     * Each theme as a small panel in its own colours — its name on its tint,
     * its accent and its pill — so the choice is made by eye.
     *
     * @return array<string, HtmlString>
     */
    private static function themeSwatches(): array
    {
        return collect(CategoryTheme::cases())
            ->mapWithKeys(function (CategoryTheme $theme): array {
                $tokens = $theme->tokens();
                $name = e($theme->getLabel());

                return [$theme->value => new HtmlString(
                    '<span style="display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:10px;'
                    ."background:{$tokens['panel']};border:1px solid {$tokens['line']};color:{$tokens['panelText']}\">"
                    ."<span style=\"padding:1px 8px;border-radius:999px;background:{$tokens['tint']};color:{$tokens['text']};font-size:12px\">{$name}</span>"
                    ."<span style=\"width:12px;height:12px;border-radius:999px;background:{$tokens['accent']}\"></span>"
                    ."<span style=\"padding:1px 8px;border-radius:999px;background:{$tokens['pillBackground']};color:{$tokens['pillText']};font-size:12px\">৳</span>"
                    .'</span>'
                )];
            })
            ->all();
    }
}
