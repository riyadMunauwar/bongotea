<?php

namespace App\Filament\Resources\Pages\Schemas;

use App\Enums\PageStatus;
use App\Shortcodes\PageBodyValidator;
use App\Support\Checkout\BuiltInField;
use App\Support\Checkout\CheckoutConfig;
use App\Support\Checkout\CheckoutFieldType;
use App\Support\Checkout\CustomCheckoutField;
use App\Support\Money;
use Closure;
use Filament\Actions\Action;
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
                                    ->helperText('[checkout] sells everything linked here, each with its own quantity, unless you let the customer choose below. Link at least one to publish.'),

                                /**
                                 * Whether the customer chooses which of them to
                                 * buy. Off, so linking a second product sells
                                 * both together the way this page always has,
                                 * and offering a choice is something a merchant
                                 * asks for rather than something a link does to
                                 * them.
                                 *
                                 * No control decides how many products make a
                                 * choice worth asking about: a page down to one
                                 * sells it implicitly, which `Page` answers for
                                 * the form and the capture together.
                                 */
                                Toggle::make('checkout_config.product_picker')
                                    ->label('Let the customer choose')
                                    ->default(false)
                                    ->helperText('Each product gets a checkbox, unticked, and its quantity appears only once it is ticked. A page selling one product asks nothing and sells it.'),

                                /**
                                 * One list, both kinds of field. The built-ins the
                                 * system knows by name and the merchant's own questions
                                 * are added, reordered and worded here together, which
                                 * is what lets one of their own sit between phone and
                                 * address rather than being pushed to the end
                                 * (ADR-0006).
                                 *
                                 * What a built-in row may not do is what a column
                                 * decides: its key and its type are held shut and its
                                 * delete button is gone, because an answer is waiting
                                 * for that column and nothing else may be written
                                 * there. Every one of those refusals is made again as
                                 * the Config is read, because this tab is not the only
                                 * way one gets written.
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
                                    ->helperText('What this page\'s order form asks for, in the words and the order it asks. Switch one off and it disappears from the form and stops being checked — its wording waits here for the day it comes back.')
                                    ->default(fn (): array => CheckoutConfig::default()->fieldsToArray())
                                    ->addActionLabel('Add a question')
                                    ->reorderable()
                                    /**
                                     * The row's own delete button, minus the five that
                                     * have a column waiting for them. Deleting a
                                     * built-in would not remove the question — the
                                     * Config puts it back as it is read — it would
                                     * throw away the wording the merchant gave it.
                                     *
                                     * Read raw rather than through `getItemState()`,
                                     * which validates the row it reads: drawing a
                                     * button must not raise the refusal that belongs
                                     * to the merchant's next save.
                                     */
                                    ->deleteAction(fn (Action $action): Action => $action->visible(
                                        fn (array $arguments, Repeater $component): bool => ! ($component
                                            ->getRawItemState($arguments['item'])['built_in'] ?? false),
                                    ))
                                    /** Says which questions this page has stopped asking without opening a row. */
                                    ->itemLabel(fn (array $state): string => (string) ($state['label'] ?? '')
                                        .(($state['enabled'] ?? true) ? '' : ' — not asked'))
                                    ->columns(4)
                                    ->schema([
                                        /**
                                         * Which kind of field wrote this row, as the
                                         * Config derived it from the key. Carried so
                                         * that what a row may do never depends on the
                                         * key box beside it, which is a box the
                                         * merchant types in — see `isBuiltIn()`. A row
                                         * they have just added has none, and defaults
                                         * to theirs.
                                         */
                                        Hidden::make('built_in')
                                            ->default(false),

                                        TextInput::make('label')
                                            ->required()
                                            ->maxLength(255)
                                            ->columnSpan(2)
                                            ->helperText('What the customer is asked.')
                                            /**
                                             * A key is derived from the wording the way a
                                             * slug is from a title, and only while the box
                                             * is still empty — so a merchant never has to
                                             * invent one, and one already in use by orders
                                             * is never moved under them.
                                             */
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                                if (blank($get('key')) && filled($state)) {
                                                    $set('key', Str::slug($state, '_'));
                                                }
                                            }),

                                        /**
                                         * A built-in's is held shut rather than hidden:
                                         * it is worth seeing which column an answer lands
                                         * in, and read-only keeps it dehydrated so the
                                         * row comes back the shape it went in.
                                         */
                                        TextInput::make('key')
                                            ->required()
                                            ->maxLength(CustomCheckoutField::KEY_MAX_LENGTH)
                                            ->readOnly(fn (Get $get): bool => self::isBuiltIn($get))
                                            ->helperText(fn (Get $get): string => self::isBuiltIn($get)
                                                ? 'Fixed. Every order has a column waiting for this answer.'
                                                : 'What the answer is filed under on the order. Fixed once orders have used it.')
                                            ->rules([
                                                fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                                    self::refuseUnusableKey($get, $value, $fail);
                                                },
                                            ]),

                                        /**
                                         * A built-in's is held shut and rides along
                                         * dehydrated: a column can hold one kind of
                                         * answer, so the type is the built-in's to state
                                         * and the Config's to ignore.
                                         */
                                        Select::make('type')
                                            ->options(CheckoutFieldType::labels())
                                            /**
                                             * Asked of the merchant only where it
                                             * is theirs to answer. A built-in row
                                             * saved without one is not a Config
                                             * short of anything: the built-in
                                             * states its own type and the stored
                                             * one is ignored.
                                             */
                                            ->required(fn (Get $get): bool => ! self::isBuiltIn($get))
                                            ->default(CheckoutFieldType::Text->value)
                                            ->disabled(fn (Get $get): bool => self::isBuiltIn($get))
                                            ->dehydrated()
                                            ->live()
                                            ->helperText('How the question is asked.'),

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

                                        /**
                                         * Independent of whether the question is asked at
                                         * all: a required field that is switched off is
                                         * inert rather than broken, so a merchant trying a
                                         * shorter form keeps what they configured behind
                                         * it.
                                         */
                                        Toggle::make('required')
                                            ->label('Must be answered')
                                            ->default(false)
                                            ->disabled(fn (Get $get): bool => self::isMandatory($get))
                                            ->live(),

                                        /**
                                         * Gone from a row that has nowhere to put a
                                         * hint. A dropdown keeps it: theirs becomes
                                         * its unpickable first option rather than
                                         * greyed-out text.
                                         */
                                        TextInput::make('placeholder')
                                            ->maxLength(255)
                                            ->visible(fn (Get $get): bool => self::rowType($get)?->showsPlaceholder() ?? true)
                                            ->helperText('A hint before the customer answers. Leave it empty for none.'),

                                        TextInput::make('help')
                                            ->label('Help text')
                                            ->maxLength(255)
                                            ->helperText('A line under the question, for anything that needs explaining.'),

                                        /**
                                         * What a choice is made between, for the
                                         * merchant's own questions that are one. A
                                         * district's choices are installation
                                         * configuration rather than data a merchant
                                         * manages, so a built-in never shows this.
                                         *
                                         * At least one row, because a dropdown with
                                         * nothing in it is a question a customer cannot
                                         * answer and an order that can never be placed.
                                         */
                                        Repeater::make('options')
                                            ->label('Options')
                                            ->helperText('What the customer chooses between. An order records the value, and the wording beside it as it stood at the time.')
                                            ->visible(fn (Get $get): bool => self::rowOffersOptions($get))
                                            ->addActionLabel('Add an option')
                                            ->minItems(1)
                                            ->validationMessages(['min' => 'A dropdown or radio question needs at least one option to choose between.'])
                                            ->columnSpanFull()
                                            ->columns(2)
                                            ->itemLabel(fn (array $state): ?string => filled($state['label'] ?? null)
                                                ? (string) $state['label']
                                                : ($state['value'] ?? null))
                                            ->schema([
                                                TextInput::make('value')
                                                    ->required()
                                                    ->maxLength(255)
                                                    ->helperText('What the order records.'),

                                                TextInput::make('label')
                                                    ->label('Shown to the customer')
                                                    ->maxLength(255)
                                                    ->helperText('Leave it empty to show the value itself.'),
                                            ]),
                                    ]),

                                /**
                                 * What this Page charges to get a parcel to the
                                 * door, in the merchant's own vocabulary rather
                                 * than in districts. Add, rename, reprice,
                                 * reorder and delete are all theirs: none of
                                 * these rows is a built-in the way a field is,
                                 * because nothing is waiting for a particular
                                 * one and a Page may perfectly well offer no
                                 * delivery at all.
                                 *
                                 * The order is the order the customer reads
                                 * them in, and the first is the one already
                                 * chosen when the form is drawn, so it belongs
                                 * at the top rather than sorted by price.
                                 *
                                 * The default is what puts a row on the create
                                 * screen, where there is no record to read one
                                 * from. It calls the same
                                 * `CheckoutConfig::default()` PageObserver
                                 * seeds with, so the two cannot disagree.
                                 */
                                Repeater::make('checkout_config.delivery_options')
                                    ->label('Delivery options')
                                    ->helperText('What delivery costs on this page. None charges nothing and shows no delivery row; one is added to the total with no question asked; two or more are offered to the customer as a choice, in this order.')
                                    ->default(self::defaultDeliveryOptions(...))
                                    ->addActionLabel('Add a delivery option')
                                    ->reorderable()
                                    ->columns(2)
                                    ->itemLabel(fn (array $state): ?string => filled($state['label'] ?? null)
                                        ? (string) $state['label']
                                        : null)
                                    /**
                                     * On the repeater rather than on the row,
                                     * because it is the only place that can see
                                     * every row at once. A duplicate name is
                                     * refused rather than silently deduplicated
                                     * on read: two identical radio buttons are
                                     * a choice that decides nothing, and the
                                     * label is what an Order snapshots.
                                     */
                                    ->rules([
                                        fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                            $labels = array_map(
                                                fn (mixed $option): string => is_array($option) ? trim((string) ($option['label'] ?? '')) : '',
                                                array_values((array) $value),
                                            );

                                            /**
                                             * Compared against the empty string
                                             * rather than dropped by falsiness:
                                             * "0" is a name a merchant may give
                                             * an option, and a bare
                                             * `array_filter` would take both
                                             * copies of it out of the
                                             * comparison and let the pair
                                             * through. A blank one is left to
                                             * the row's own `required`.
                                             */
                                            $labels = array_filter($labels, fn (string $label): bool => $label !== '');

                                            if (count($labels) !== count(array_unique($labels))) {
                                                $fail('Two delivery options cannot share a name. The name is what the customer picks and what the order records.');
                                            }
                                        },
                                    ])
                                    ->schema([
                                        TextInput::make('label')
                                            ->label('Name')
                                            ->required()
                                            ->maxLength(255)
                                            ->helperText('What the customer picks, and what the order records — "Inside Dhaka", "Free".'),

                                        /**
                                         * Typed and shown in major units, stored
                                         * in minor ones like every other money
                                         * value. Zero is a real answer: free
                                         * delivery is an arrangement, not the
                                         * absence of one.
                                         */
                                        TextInput::make('amount')
                                            ->label('Amount')
                                            ->required()
                                            ->numeric()
                                            ->minValue(0)
                                            ->prefix(config('giopages.currency.symbol'))
                                            ->helperText('Added to the total, and collected at the door.')
                                            ->formatStateUsing(fn (mixed $state): ?string => blank($state)
                                                ? null
                                                : Money::toMajorString((int) $state))
                                            ->dehydrateStateUsing(fn (mixed $state): int => Money::toMinorUnits((string) $state)),
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
     * Whether the row being rendered holds a field a Checkout may not stop
     * asking for.
     */
    private static function isMandatory(Get $get): bool
    {
        return self::builtIn($get)?->isMandatory() ?? false;
    }

    /**
     * Whether the row being rendered holds one of the fields the system knows
     * by name — the ones whose key and type belong to a column rather than to
     * the merchant.
     *
     * Read from the flag the Config wrote the row with rather than from the key
     * box beside it, because that box is one the merchant types in and what a
     * row may do must not change under them as they type. A question of their
     * own that they key `district` stays theirs to edit and to delete, and is
     * refused at save; deciding on the key alone would turn it into a row they
     * could neither correct nor remove, and so could never save past.
     */
    private static function isBuiltIn(Get $get): bool
    {
        return (bool) $get('built_in');
    }

    private static function builtIn(Get $get): ?BuiltInField
    {
        return self::isBuiltIn($get) ? BuiltInField::tryFrom((string) $get('key')) : null;
    }

    /**
     * The control the row is currently set to be asked with.
     *
     * A built-in's own, whatever the row happens to hold: the stored type is
     * carried for this form's benefit and the built-in is what decides.
     */
    private static function rowType(Get $get): ?CheckoutFieldType
    {
        return self::builtIn($get)?->type()
            ?? CheckoutFieldType::tryFrom((string) $get('type'));
    }

    /**
     * Whether this row is a merchant's own question that needs a list to choose
     * between.
     */
    private static function rowOffersOptions(Get $get): bool
    {
        return ! self::isBuiltIn($get) && (self::rowType($get)?->hasOptions() ?? false);
    }

    /**
     * Refuse a key that would file two answers in one place, or file one where
     * something else is already read from.
     *
     * Raised on the row's own key box rather than on the repeater, because it
     * is one row that has to be corrected and the merchant should be told which.
     * Never on a built-in's row: its key is not typed and not the merchant's to
     * change, so an error there would name a box nobody can edit and a row
     * nobody can delete. Both rows of a pair the merchant keyed alike are
     * refused, since either one could be the one meant to be something else.
     */
    private static function refuseUnusableKey(Get $get, mixed $value, Closure $fail): void
    {
        /** Blank is `required`'s to refuse, and saying so twice helps nobody. */
        if (self::isBuiltIn($get) || ($key = trim((string) $value)) === '') {
            return;
        }

        /**
         * Shape, the built-ins and the names a Capture already uses, answered
         * by the Config so that the tab refusing a key and the Config dropping
         * one cannot disagree about which keys are allowed.
         */
        if (($refusal = CustomCheckoutField::keyRefusal($key)) !== null) {
            $fail($refusal);

            return;
        }

        $sharing = array_filter(
            array_filter((array) $get('../'), is_array(...)),
            fn (array $row): bool => trim((string) ($row['key'] ?? '')) === $key,
        );

        if (count($sharing) > 1) {
            $fail('Two questions cannot share the key "'.$key.'". It is what the answer is filed under on every order.');
        }
    }

    /**
     * The Delivery Option a new Page starts from, in the units this form types
     * in.
     *
     * Converted here rather than left to the amount field's `formatStateUsing`,
     * which a default never passes through — the row would arrive holding minor
     * units and be saved as a hundred times the charge. The same
     * `CheckoutConfig::default()` PageObserver seeds with, so the create screen
     * and a Page created anywhere else cannot disagree.
     *
     * @return list<array{label: string, amount: string}>
     */
    private static function defaultDeliveryOptions(): array
    {
        return array_map(
            fn (array $option): array => [
                'label' => $option['label'],
                'amount' => Money::toMajorString($option['amount']),
            ],
            CheckoutConfig::default()->deliveryOptionsToArray(),
        );
    }
}
