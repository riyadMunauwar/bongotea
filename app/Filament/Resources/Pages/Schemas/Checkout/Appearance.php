<?php

namespace App\Filament\Resources\Pages\Schemas\Checkout;

use App\Support\Checkout\StyleToken;
use App\Support\Checkout\StyleTokenKind;
use Closure;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

/**
 * How the checkout looks, for the merchant who does not write CSS.
 *
 * Owns the whole named token set and what each token is asked with, in one
 * place, because the checkout as a whole and a single question are restyled
 * with the same controls: an override is that token redeclared with a smaller
 * reach rather than a second kind of styling. The per-question section stays
 * inside the question's own row, and asks here for its controls.
 */
class Appearance
{
    public static function section(): Section
    {
        /**
         * Every box left empty is the checkout as it
         * has always looked, so this section changes
         * nothing until something is typed into it. A
         * merchant who does write CSS loses nothing
         * either: what this emits is a redeclaration at
         * the specificity their own stylesheet already
         * beats.
         *
         * No heading of its own: the nested tab that holds it is
         * already headed Appearance, and a card repeating the
         * word underneath it says nothing the merchant has not
         * just clicked. The description is what is worth
         * keeping, which is why the section stays rather than
         * the controls being handed to the tab loose.
         */
        return Section::make()
            ->description('Colours, corners and sizes for this page\'s order form. Anything left empty keeps the checkout\'s own.')
            ->columns(3)
            ->schema(self::controls('checkout_config.style_tokens.', overridesOneField: false));
    }

    /**
     * The controls a merchant restyles with: the whole named token set, once
     * for the checkout and once inside every field row.
     *
     * One definition serving both, because an override is the same custom
     * property redeclared on that field's wrapper — the cascade is the whole of
     * what makes it apply to one question, and two lists here would be two
     * lists to keep in step for no gain.
     *
     * A field row is offered every token something inside a field reads, which
     * is all of them but the three that dress the order button: a question
     * holds no button, so those three would be controls that provably do
     * nothing. `StyleToken::appliesToOneField()` is where that is said.
     *
     * @return list<Field>
     */
    public static function controls(string $statePath, bool $overridesOneField): array
    {
        $tokens = array_filter(
            StyleToken::cases(),
            fn (StyleToken $token): bool => ! $overridesOneField || $token->appliesToOneField(),
        );

        return array_values(array_map(
            fn (StyleToken $token): Field => self::control($token, $statePath, $overridesOneField),
            $tokens,
        ));
    }

    /**
     * One token's control: the kind of value it holds decides what it is asked
     * with, and answers for what may be typed into it.
     *
     * Blank is never refused. An empty box on the checkout is the token at its
     * default, and an empty box on a field is that field following the
     * checkout; neither is something to stop a merchant saving over.
     *
     * The refusal is the same one the Config makes as it reads a stored value,
     * asked of the token so the two cannot disagree about what a colour or a
     * length is. Here it is worded and shown on the box that holds it; there it
     * is silent, because a Config written by some other route has nobody to
     * tell.
     */
    private static function control(StyleToken $token, string $statePath, bool $overridesOneField): Field
    {
        $control = match ($token->kind()) {
            StyleTokenKind::Colour => ColorPicker::make($statePath.$token->value)->hex(),
            /** A width is typed like a length, and refused like one — against its own units. */
            StyleTokenKind::Length, StyleTokenKind::Width => TextInput::make($statePath.$token->value)->maxLength(20),
            StyleTokenKind::Scale => Select::make($statePath.$token->value)->options($token->options()),
        };

        return $control
            ->label($token->label())
            /** What is in force while the box is empty, so it reads without saving to find out. */
            ->placeholder($overridesOneField ? 'Follows the checkout' : $token->defaultHint())
            ->helperText($overridesOneField ? null : $token->helperText())
            ->rules([
                fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($token): void {
                    $value = is_scalar($value) ? trim((string) $value) : '';

                    if ($value !== '' && ($refusal = $token->refusal($value)) !== null) {
                        $fail($refusal);
                    }
                },
            ]);
    }
}
