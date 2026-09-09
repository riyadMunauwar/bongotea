<?php

namespace App\Filament\Resources\Pages\Schemas\Checkout;

use App\Filament\Resources\Pages\Schemas\RefusedBy;
use App\Support\Checkout\StyleToken;
use App\Support\Checkout\StyleTokenKind;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

/**
 * How the checkout looks, for the merchant who does not write CSS.
 *
 * The eleven Style Tokens, once, for every layout. This is the one place a
 * checkout is styled: a question row carries no styling of its own, so a
 * merchant changing a colour changes it here and nowhere else, and finds it
 * without scrolling past every question they have ever authored.
 */
class Look
{
    public static function section(): Section
    {
        /**
         * Every box left empty is the checkout as the design draws it, so this
         * section changes nothing until something is typed into it. A merchant
         * who does write CSS loses nothing either: what this emits is a
         * redeclaration at the specificity their own stylesheet already beats.
         *
         * No heading of its own: the nested tab that holds it is already headed
         * Look, and a card repeating the word underneath it says nothing the
         * merchant has not just clicked. The description is what is worth
         * keeping, which is why the section stays rather than the controls
         * being handed to the tab loose.
         */
        return Section::make()
            ->description('The Style Tokens for this page\'s order form: its colours, corners and typeface, applied to whatever layout you choose. Anything left empty keeps the design\'s own.')
            ->columns(3)
            ->schema(self::controls());
    }

    /**
     * One control per Style Token, in the token set's own order.
     *
     * @return list<Field>
     */
    public static function controls(): array
    {
        return array_map(self::control(...), StyleToken::cases());
    }

    /**
     * One token's control: the kind of value it holds decides what it is asked
     * with, and answers for what may be typed into it.
     *
     * Blank is never refused. An empty box is the token at its default, which is
     * not something to stop a merchant saving over.
     *
     * The refusal is the same one the Config makes as it reads a stored value,
     * asked of the token so the two cannot disagree about what a colour, a
     * length or a font is. Here it is worded and shown on the box that holds
     * it; there it is silent, because a Config written by some other route has
     * nobody to tell.
     */
    private static function control(StyleToken $token): Field
    {
        $statePath = 'checkout_config.style_tokens.'.$token->value;

        $control = match ($token->kind()) {
            StyleTokenKind::Colour => ColorPicker::make($statePath)->hex(),
            StyleTokenKind::Length => TextInput::make($statePath)->maxLength($token->kind()->maxLength()),
            /** A list of names, so it takes the row: three short boxes beside it would be too narrow to read one back. */
            StyleTokenKind::Font => TextInput::make($statePath)->maxLength($token->kind()->maxLength())->columnSpanFull(),
        };

        return $control
            ->label($token->label())
            /** What is in force while the box is empty, so it reads without saving to find out. */
            ->placeholder($token->defaultValue())
            ->helperText($token->helperText())
            ->rules([RefusedBy::rule($token->refusal(...))]);
    }
}
