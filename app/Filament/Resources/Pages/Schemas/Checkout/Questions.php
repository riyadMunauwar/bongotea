<?php

namespace App\Filament\Resources\Pages\Schemas\Checkout;

use App\Support\Checkout\BuiltInField;
use App\Support\Checkout\CheckoutConfig;
use App\Support\Checkout\CheckoutFieldType;
use App\Support\Checkout\CustomCheckoutField;
use App\Support\Checkout\FieldConstraints;
use App\Support\Checkout\FieldPattern;
use App\Support\Checkout\FieldWidth;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;

/**
 * What this Page's order form asks for, in the words and the order it asks.
 *
 * Every reading of a row is a helper here rather than an expression inside the
 * schema, because what a row may do turns on which kind of field wrote it and
 * that question is asked from a dozen places.
 */
class Questions
{
    /**
     * The attribute the admin theme finds a switched-off row by.
     *
     * Carried by the "Ask for this" toggle, whose `aria-checked` Alpine keeps
     * in step with the switch, so the row dims as it is thrown rather than on
     * the next save. `forms.css` holds the rule; nothing else may use the name.
     */
    public const ASKED_MARKER = 'data-gp-question-asked';

    public static function asked(): Repeater
    {
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
        return Repeater::make('checkout_config.fields')
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
            /**
             * A checkout asking ten questions is ten lines to scan and
             * reorder rather than a page to scroll. Everything a merchant
             * chooses a question by — its wording, how it is asked, whether an
             * answer is compulsory, how wide it sits — is in the row's summary,
             * so opening one is a decision to edit it rather than the only way
             * to find out what it is.
             *
             * A row carrying a refusal still opens itself: Filament walks up
             * from the first `[data-validation-error]` on a failed save
             * dispatching `expand`, which a repeater item listens for. That is
             * a repeater item only — the `Answers` and `Appearance` sections
             * inside one stay shut, which is why neither may raise a refusal a
             * merchant cannot see (see .ai/rules/resources.md).
             */
            ->collapsible()
            ->collapsed()
            ->itemLabel(self::summarise(...))
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
                 * How much of a row the question takes,
                 * chosen from two rather than typed as
                 * a figure: the form is a grid of two
                 * columns, and a number would have to
                 * resolve against a container nobody
                 * here knows the width of.
                 *
                 * Offered on a built-in as well. Where
                 * an answer goes is what a built-in has
                 * no say over; name and phone side by
                 * side is not that, and it is the
                 * pairing a merchant reaches for first.
                 */
                Select::make('width')
                    ->label('Width')
                    ->options(FieldWidth::labels())
                    ->default(FieldWidth::Full->value)
                    /**
                     * Not `required`. The list has no
                     * empty option to pick, so nothing
                     * on screen can leave it blank —
                     * and a row that arrives without
                     * one from some other route is a
                     * full-width question, the way
                     * every question was before one
                     * could sit beside another. A
                     * refusal here would only be a
                     * refusal the Config does not make.
                     */
                    ->selectablePlaceholder(false)
                    /** Read by the row's summary, which has to say what the box says now. */
                    ->live()
                    ->helperText('Two half-width questions in a row sit side by side on a wide screen. On a phone every question is full width, whichever you pick.'),

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
                    /**
                     * What dims the row around it. A question the page has
                     * stopped asking has to look inert from the collapsed
                     * list, or it is one a merchant can mistake for a live one
                     * — and its wording is still here, so there is nothing
                     * else on the row to tell them apart.
                     */
                    ->extraAttributes([self::ASKED_MARKER => 'true'])
                    ->disabled(fn (Get $get): bool => self::isMandatory($get))
                    /**
                     * Held shut on phone, and riding along dehydrated for
                     * the reason the type does: a disabled control is
                     * dropped from the row otherwise, and the row's own
                     * summary would then read the absence as an answer and
                     * call the field something it is not. What phone is
                     * remains the built-in's to say, and the Config forces
                     * it whatever arrives.
                     */
                    ->dehydrated()
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
                    /** Dehydrated for the reason the switch above it is. */
                    ->dehydrated()
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

                /**
                 * What this question will take, from a
                 * vocabulary rather than from a box a
                 * merchant writes a rule into.
                 *
                 * Every control here is a number or a
                 * list to pick from, and there is
                 * nowhere to type a rule string or an
                 * expression of their own — see
                 * `FieldConstraints`. A malformed rule
                 * would not show up here as an error,
                 * it would show up as a conversion rate
                 * going to zero on a page that is
                 * spending money.
                 *
                 * Only the merchant's own questions get
                 * this. What a built-in will take is
                 * the column's to say, and a Config
                 * decides its wording and its two flags
                 * and no more (ADR-0006).
                 *
                 * Collapsed for the reason Appearance
                 * is: nearly every question takes any
                 * answer it is given. A refusal inside
                 * cannot be one nobody sees — the pair
                 * that raises it has to be typed with
                 * the section open, and nothing
                 * collapses it again on a failed save
                 * (see .ai/rules/resources.md).
                 */
                Section::make('Answers')
                    ->description('What this question will accept. Anything left empty is a bound it does not have.')
                    ->collapsible()
                    ->collapsed()
                    ->columnSpanFull()
                    ->columns(3)
                    ->visible(fn (Get $get): bool => self::rowTakesConstraints($get))
                    ->schema([
                        /**
                         * Refused against the longest,
                         * and raised on the shortest's
                         * own box: it is the box on the
                         * offending row that the
                         * merchant can bring down, and
                         * naming the row alone would
                         * leave them looking for which
                         * of two figures is the wrong
                         * one. Asked of
                         * `FieldConstraints` so the tab
                         * and the read cannot disagree
                         * about which pairs are
                         * impossible.
                         */
                        TextInput::make('constraints.min_length')
                            ->label('Shortest answer')
                            ->integer()
                            ->minValue(1)
                            ->visible(self::rowConstrainsWriting(...))
                            ->helperText('Characters. Empty accepts an answer of any length.')
                            ->rules([
                                fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                    $refusal = FieldConstraints::lengthRefusal($value, $get('constraints.max_length'));

                                    if ($refusal !== null) {
                                        $fail($refusal);
                                    }
                                },
                            ]),

                        TextInput::make('constraints.max_length')
                            ->label('Longest answer')
                            ->integer()
                            ->minValue(1)
                            /**
                             * The control's own ceiling
                             * is the most it will ever
                             * take, so a figure above it
                             * is refused here rather
                             * than quietly capped at
                             * save.
                             */
                            ->maxValue(fn (Get $get): ?int => self::rowType($get)?->lengthCeiling())
                            ->visible(self::rowConstrainsWriting(...))
                            ->helperText(fn (Get $get): string => 'Characters, up to '
                                .(self::rowType($get)?->lengthCeiling() ?? 0)
                                .'. Empty takes the most this kind of question will hold.'),

                        /**
                         * A closed list. A paragraph is
                         * offered one too, and it is
                         * checked at capture — HTML has
                         * no `pattern` on a textarea, so
                         * the browser simply stops
                         * nothing rather than stopping
                         * the wrong thing.
                         *
                         * Said on the box rather than
                         * left for the merchant to
                         * discover, because "the
                         * customer is told as they
                         * type" and "the customer is
                         * told when they submit" are
                         * different forms and they are
                         * choosing between them.
                         */
                        Select::make('constraints.pattern')
                            ->label('Answer shape')
                            ->options(FieldPattern::labels())
                            ->placeholder('Anything')
                            ->visible(self::rowConstrainsWriting(...))
                            ->helperText(fn (Get $get): string => 'What the answer may be made of.'
                                .(self::rowType($get)?->carriesPatternAttribute() === false
                                    ? ' On a paragraph this is checked when the customer submits, not as they type.'
                                    : '')),

                        TextInput::make('constraints.min')
                            ->label('Smallest')
                            ->numeric()
                            ->visible(self::rowConstrainsNumber(...))
                            ->helperText('The lowest number that will be accepted.')
                            ->rules([
                                fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                    $refusal = FieldConstraints::boundsRefusal($value, $get('constraints.max'));

                                    if ($refusal !== null) {
                                        $fail($refusal);
                                    }
                                },
                            ]),

                        TextInput::make('constraints.max')
                            ->label('Largest')
                            ->numeric()
                            ->visible(self::rowConstrainsNumber(...))
                            ->helperText('The highest number that will be accepted.'),

                        Toggle::make('constraints.whole_numbers')
                            ->label('Whole numbers only')
                            ->visible(self::rowConstrainsNumber(...))
                            ->helperText('A floor number is a whole number; a weight is not.'),
                    ]),

                /**
                 * How this one question differs from the
                 * rest, for the field a merchant wants to
                 * draw attention to.
                 *
                 * The same token set the checkout as a
                 * whole is styled with, because an
                 * override is that token redeclared with
                 * a smaller reach rather than a second
                 * kind of styling. Every box left empty
                 * is the question following the
                 * checkout, which is nearly all of them
                 * — hence collapsed.
                 *
                 * A refusal inside a collapsed section
                 * would be a refusal nobody can see. It
                 * cannot arise from one: a value only
                 * gets typed with the section open, and
                 * nothing collapses it again on a failed
                 * save (see .ai/rules/resources.md).
                 */
                Section::make('Appearance')
                    ->description('Leave these empty and the question looks like the rest of the checkout.')
                    ->collapsible()
                    ->collapsed()
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema(Appearance::controls('style_tokens.', overridesOneField: true)),
            ]);
    }

    /**
     * The one line a collapsed row says about the question inside it.
     *
     * Enough to pick a question out of ten without opening it, and no more:
     * what the customer is asked, how they are asked it, whether they may
     * leave it, and how much of the row it takes.
     *
     * Whether the page still asks it and whether an answer is compulsory are
     * said separately and in different words, because they are different
     * things — a switched-off question keeps whatever the merchant configured
     * behind it, so "Not asked" and "Must be answered" are both true of a row
     * that is waiting to come back. Collapsing the two into one word would
     * lose the setting the merchant would find again on switching it on.
     *
     * Read from the row's state rather than from the field the Config would
     * make of it: this runs while the form is being drawn, on whatever the
     * merchant has typed so far, and a half-typed row is not a field yet. That
     * is why every control the tab holds shut is `dehydrated()` — a row this
     * reads has to be whole, and an absent switch is not an answer.
     *
     * Which is also why a part with nothing behind it is left out rather than
     * guessed at. A merchant who has cleared the wording or the type is
     * looking at the empty box that says so, two lines below, and cannot save
     * past it; a summary filling the gap in with a default would be the line
     * disagreeing with the box. Only the width is always said, because that is
     * one of two and a row can never be between them.
     *
     * @param  array<string, mixed>  $state
     */
    private static function summarise(array $state): string
    {
        $builtIn = self::builtInFrom($state['built_in'] ?? false, $state['key'] ?? '');

        $type = self::typeFrom($builtIn, $state['type'] ?? '');

        return implode(' · ', array_filter([
            trim((string) ($state['label'] ?? '')),
            ($state['enabled'] ?? true) ? null : 'Not asked',
            $type?->label(),
            ($state['required'] ?? false) ? 'Must be answered' : 'Optional',
            FieldWidth::fromStored($state['width'] ?? null)->label(),
        ]));
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
        return self::builtInFrom($get('built_in'), $get('key'));
    }

    /**
     * Which of the fields the system knows by name wrote a row, from the two
     * values the row carries.
     *
     * Taken as figures rather than through `Get` because `summarise()` is
     * handed a plain array and the schema asks through a `Get` — one reading
     * under both, so a row cannot be one thing to the controls inside it and
     * another to the line above them.
     */
    private static function builtInFrom(mixed $isBuiltIn, mixed $key): ?BuiltInField
    {
        return $isBuiltIn ? BuiltInField::tryFrom((string) $key) : null;
    }

    /**
     * The control the row is currently set to be asked with.
     *
     * A built-in's own, whatever the row happens to hold: the stored type is
     * carried for this form's benefit and the built-in is what decides.
     */
    private static function rowType(Get $get): ?CheckoutFieldType
    {
        return self::typeFrom(self::builtIn($get), $get('type'));
    }

    /** The same reading, for the summary that is handed a row rather than a `Get`. */
    private static function typeFrom(?BuiltInField $builtIn, mixed $stored): ?CheckoutFieldType
    {
        return $builtIn?->type() ?? CheckoutFieldType::tryFrom((string) $stored);
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
     * Whether this row is a merchant's own question that there is anything to
     * constrain about.
     *
     * A choice and a box are answered by picking, so the list they offer is the
     * only bound worth having and it is not configurable. A built-in is
     * excluded whatever it is asked with: what its column will take is the
     * built-in's to state (ADR-0006).
     */
    private static function rowTakesConstraints(Get $get): bool
    {
        return self::rowConstrainsWriting($get) || self::rowConstrainsNumber($get);
    }

    /**
     * Whether this row is a merchant's own question answered by writing
     * something, which is what a length and a shape bound.
     */
    private static function rowConstrainsWriting(Get $get): bool
    {
        return ! self::isBuiltIn($get) && (self::rowType($get)?->holdsWriting() ?? false);
    }

    /**
     * Whether this row is a merchant's own question answered with a number,
     * which is what a smallest, a largest and whole numbers bound.
     */
    private static function rowConstrainsNumber(Get $get): bool
    {
        return ! self::isBuiltIn($get) && (self::rowType($get)?->holdsNumber() ?? false);
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
}
