<?php

namespace App\Filament\Resources\Pages\Schemas;

use App\Enums\PageStatus;
use App\Shortcodes\PageBodyValidator;
use Closure;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class PageForm
{
    public static function configure(Schema $schema): Schema
    {
        /**
         * Five columns, not the two the resource hands out: the authoring stack
         * takes four and Publishing takes one. The Grid is what makes Publishing
         * a sidebar spanning both sections rather than a box beside the lower
         * one. Every Section states its own columns because the default is
         * invisible in the PHP — see .ai/rules/resources.md.
         */
        return $schema
            ->columns(5)
            ->components([
                Grid::make(1)
                    ->columnSpan(4)
                    ->schema([
                        /**
                         * Collapsible but never persisted: Filament does not
                         * reopen a collapsed Section to show a validation error,
                         * and the body linter's "link exactly one product" is
                         * raised on `body`, in the section below this one. A
                         * remembered collapse would hide the field that fixes an
                         * error the operator can see.
                         */
                        Section::make('Checkout')
                            ->collapsible()
                            ->columns(1)
                            ->schema([
                                Select::make('products')
                                    ->relationship('products', 'name')
                                    ->multiple()
                                    ->preload()
                                    ->searchable()
                                    ->helperText('[checkout] sells exactly one product, so link exactly one.'),
                            ]),

                        Section::make('Page')
                            ->description('The body is a complete HTML document. Nothing is wrapped around it and no stylesheet of ours is injected — what you paste is what a visitor receives.')
                            ->columns(1)
                            ->schema([
                                TextInput::make('title')
                                    ->required()
                                    ->maxLength(255)
                                    ->helperText('An admin label only. The document\'s own <title> is what a visitor and Facebook see.')
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                        if (blank($get('slug')) && filled($state)) {
                                            $set('slug', Str::slug($state));
                                        }
                                    }),

                                /**
                                 * Filament's unique rule queries the raw table, so a
                                 * soft-deleted page still holds its slug — which is the
                                 * intent: a slug that was ever live is baked into ad
                                 * creatives and must never be reissued.
                                 */
                                TextInput::make('slug')
                                    ->required()
                                    ->maxLength(255)
                                    ->unique(ignoreRecord: true)
                                    ->rule('regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                                    ->prefix(url('/').'/')
                                    ->helperText('Flat, lowercase, hyphen-separated. A slug stays claimed even after the page is deleted.'),

                                CodeEditor::make('body')
                                    ->language(Language::Html)
                                    ->required()
                                    ->columnSpanFull()
                                    ->helperText('Write [checkout] where the order form should appear.')
                                    ->rules([
                                        /**
                                         * Publishing is what puts a Page in front of paid
                                         * traffic, so that is where the linter blocks. A
                                         * draft may hold a half-written shortcode.
                                         */
                                        fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                            /**
                                             * The status arrives as a raw string on create and as
                                             * a cast enum on edit, so neither comparison is safe
                                             * on its own.
                                             */
                                            $status = $get('status');
                                            $status = $status instanceof PageStatus
                                                ? $status
                                                : PageStatus::tryFrom((string) $status);

                                            if ($status !== PageStatus::Published) {
                                                return;
                                            }

                                            /**
                                             * `products` lives in the Checkout section, but Get
                                             * resolves against the whole schema, so the linter
                                             * reads it from here unchanged.
                                             */
                                            $errors = app(PageBodyValidator::class)
                                                ->errors((string) $value, count($get('products') ?? []));

                                            foreach ($errors as $error) {
                                                $fail($error);
                                            }
                                        },
                                    ]),
                            ]),
                    ]),

                Section::make('Publishing')
                    ->columnSpan(1)
                    ->columns(1)
                    ->schema([
                        Select::make('status')
                            ->options(PageStatus::class)
                            ->default(PageStatus::Draft->value)
                            ->required()
                            ->live()
                            ->helperText('Only a published page is reachable at its slug.'),

                        /**
                         * Filled automatically the first time a Page is published
                         * (see PageObserver) and never overwritten, so what is
                         * typed here survives.
                         */
                        DateTimePicker::make('published_at')
                            ->label('Published at')
                            ->seconds(false),
                    ]),
            ]);
    }
}
