<?php

namespace App\Filament\Resources\Pages\Schemas;

use App\Enums\PageStatus;
use App\Shortcodes\PageBodyValidator;
use App\Support\Checkout\BuiltInField;
use App\Support\Checkout\CheckoutConfig;
use Closure;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class PageForm
{
    public static function configure(Schema $schema): Schema
    {
        /**
         * Five columns, not the two the resource hands out: the authoring tabs
         * take four and Publishing takes the fifth, so the sidebar runs the
         * full height beside whichever tab is open. Every Tab and Section
         * states its own columns because the default is invisible in the PHP —
         * see .ai/rules/resources.md.
         */
        return $schema
            ->columns(5)
            ->components([
                Tabs::make()
                    ->columnSpan(4)
                    ->tabs([
                        Tab::make('Page')
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
                                    ->helperText('A complete HTML document. Nothing is wrapped around it and no stylesheet of ours is injected — what you paste is what a visitor receives. Write [checkout] where the order form should appear.'),
                            ]),

                        Tab::make('Checkout')
                            ->columns(1)
                            ->schema([
                                /**
                                 * Shows the live links only, and leaves the rest
                                 * alone: Filament detaches what its own relationship
                                 * query returned and is missing from the selection
                                 * (Select::saveRelationshipsAfterStateUpdated), and a
                                 * soft-deleted Product is in neither. So saving an
                                 * unrelated field does not quietly drop the link the
                                 * Pages list is flagging as lost.
                                 */
                                Select::make('products')
                                    ->relationship('products', 'name')
                                    ->multiple()
                                    ->preload()
                                    ->searchable()
                                    ->helperText('[checkout] sells everything linked here, each with its own quantity. Link at least one to publish.'),

                                /**
                                 * The built-in fields, and only them. A key and a type
                                 * are what a column is waiting for, so neither is
                                 * offered as a control and no row can be added, removed
                                 * or reordered: a merchant words a question here, they
                                 * do not invent one. Both ride along hidden — with the
                                 * required flag, which has no control of its own yet —
                                 * so a Config comes back the shape it went in.
                                 *
                                 * The default is what puts rows on the create screen,
                                 * where there is no record to read them from. It calls
                                 * the same `CheckoutConfig::default()` PageObserver
                                 * seeds with, so the two cannot disagree; the observer
                                 * still covers every Page that never passes through
                                 * this form.
                                 */
                                Repeater::make('checkout_config.fields')
                                    ->label('Fields')
                                    ->helperText('What this page\'s order form asks for, in the words it asks. Switch one off and it disappears from the form and stops being checked — its wording waits here for the day it comes back.')
                                    ->default(fn (): array => CheckoutConfig::default()->fieldsToArray())
                                    ->addable(false)
                                    ->deletable(false)
                                    ->reorderable(false)
                                    /** Says which questions this page has stopped asking without opening a row. */
                                    ->itemLabel(fn (array $state): string => (string) ($state['label'] ?? '')
                                        .(($state['enabled'] ?? true) ? '' : ' — not asked'))
                                    ->columns(4)
                                    ->schema([
                                        Hidden::make('key'),
                                        Hidden::make('required'),

                                        /**
                                         * Phone's is held shut: a customer is their phone
                                         * number, and a cash-on-delivery order nobody can
                                         * call is not fulfillable. Disabled here and
                                         * refused again in CheckoutField, because this tab
                                         * is not the only way a Config gets written.
                                         */
                                        Toggle::make('enabled')
                                            ->label('Ask for this')
                                            ->default(true)
                                            ->disabled(fn (Get $get): bool => self::isMandatory($get))
                                            ->helperText(fn (Get $get): ?string => self::isMandatory($get)
                                                ? 'Always asked. A cash-on-delivery order nobody can phone cannot be fulfilled.'
                                                : null)
                                            ->live(),

                                        TextInput::make('label')
                                            ->required()
                                            ->maxLength(255)
                                            ->helperText('What the customer is asked.'),

                                        TextInput::make('placeholder')
                                            ->maxLength(255)
                                            ->helperText('Greyed-out hint inside the box. Leave it empty for none.'),

                                        TextInput::make('help')
                                            ->label('Help text')
                                            ->maxLength(255)
                                            ->helperText('A line under the question, for anything that needs explaining.'),
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
                            ->helperText('Only a published page is reachable at its slug.')
                            ->rules([
                                /**
                                 * Why this page cannot go live is asked and answered in
                                 * the same place. The body and the linked products sit
                                 * in different tabs, so an error raised on either is
                                 * invisible from the other; this sidebar is on screen
                                 * whichever tab is open.
                                 *
                                 * Publishing is what puts a Page in front of paid
                                 * traffic, so that is where the linter blocks. A draft
                                 * may hold a half-written shortcode.
                                 */
                                fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                    /**
                                     * The status arrives as a raw string on create and as
                                     * a cast enum on edit, so neither comparison is safe
                                     * on its own.
                                     */
                                    $status = $value instanceof PageStatus
                                        ? $value
                                        : PageStatus::tryFrom((string) $value);

                                    if ($status !== PageStatus::Published) {
                                        return;
                                    }

                                    /** Get resolves against the whole schema, so a tab is no barrier. */
                                    $errors = app(PageBodyValidator::class)
                                        ->errors((string) $get('body'), count($get('products') ?? []));

                                    foreach ($errors as $error) {
                                        $fail($error);
                                    }
                                },
                            ]),

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

    /**
     * Whether the repeater row being rendered holds a field a Checkout may not
     * stop asking for.
     *
     * Read from the row's own hidden key rather than its position, because the
     * rows are a stored list and nothing here may assume which one is which.
     */
    private static function isMandatory(Get $get): bool
    {
        return BuiltInField::tryFrom((string) $get('key'))?->isMandatory() ?? false;
    }
}
