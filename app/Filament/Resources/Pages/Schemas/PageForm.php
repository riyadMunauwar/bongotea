<?php

namespace App\Filament\Resources\Pages\Schemas;

use App\Enums\PageStatus;
use App\Filament\Concerns\HasSeoFields;
use App\Models\Page;
use App\Support\Pages\CartPlacements;
use App\Support\Pages\PageBlocks;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * A Page is its title, slug and status, the Blocks it is built from, and
 * what it says to search engines.
 *
 * A strip across the top holds what the Page is called and whether it is
 * live; the Blocks take the whole width under it, because they are the Page
 * and their rows of cards, links and images need the room.
 *
 * A System Page's role is never on the form: it is set when the store is
 * installed and can never change, so the form only says which one this is.
 */
class PageForm
{
    use HasSeoFields;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 3])
            ->components([
                Section::make('Page')
                    ->columnSpan(['lg' => 2])
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                if (blank($get('slug')) && filled($state)) {
                                    $set('slug', Str::slug($state));
                                }
                            }),

                        TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->rule('regex:/^'.Page::SLUG.'$/')
                            ->notIn(Page::RESERVED_SLUGS)
                            ->validationMessages(['not_in' => 'The store already answers at this address; choose another slug.'])
                            ->prefix(url('/').'/')
                            ->helperText(fn (?Page $record): string => $record?->isHome()
                                ? 'Home is always served at the bare domain.'
                                : 'Flat, lowercase, hyphen-separated.'),
                    ]),

                Section::make('Publishing')
                    ->description(fn (?Page $record): ?string => $record?->isSystemPage()
                        ? "This is the store's {$record->system_role->getLabel()} page. Its Blocks can change, but it can never be deleted or given another job."
                        : null)
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        Select::make('status')
                            ->options(PageStatus::class)
                            ->default(PageStatus::Draft->value)
                            ->required()
                            ->helperText('Only a published page is reachable at its slug.'),
                    ]),

                Section::make('Blocks')
                    ->description('The page from top to bottom. Every Block can carry an anchor and be hidden on phones or wider screens.')
                    ->columnSpanFull()
                    ->schema([
                        BlocksBuilder::make('blocks')
                            ->hiddenLabel()
                            /** A Cart stands on one Page at most (ADR-0038), and once on it: each Block's own field refuses another Page, this the same Page twice. */
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                $cartIds = CartPlacements::cartIdsIn(array_values(is_array($value) ? $value : []));

                                if (count($cartIds) !== count(array_unique($cartIds))) {
                                    $fail('This Page draws the same Cart twice. A Cart stands on one Page, once.');
                                }
                            })
                            ->blocks(PageBlocks::builderBlocks())
                            ->collapsed()
                            ->cloneable()
                            ->addActionLabel('Add a Block'),
                    ]),

                self::seoSection(acrossThePage: true),
            ]);
    }
}
