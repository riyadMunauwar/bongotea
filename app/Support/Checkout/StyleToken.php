<?php

namespace App\Support\Checkout;

/**
 * One thing a Merchant can restyle about a Checkout without writing CSS.
 *
 * The named set is the whole of the styling model. Each token is a custom
 * property declared on the Checkout root and read by the flat rules that were
 * already there, so restyling is a redeclaration rather than a new rule — which
 * is what keeps the Merchant's own stylesheet able to win an argument with us,
 * and what keeps the emitted styles the same length on a Checkout asking twenty
 * questions as on one asking two.
 *
 * A field overrides a token by redeclaring it on its own wrapper. That is why
 * there is one list rather than a Checkout list and a field list: an override
 * is the same property with a smaller reach, and the cascade does the rest.
 *
 * There is no token for anything a Merchant cannot see the effect of. In
 * particular there is no separate control for an input's padding: `InputSize`
 * is one scale driving its padding and its text together, because an input
 * sized up without its text sized with it is a box a Customer cannot read what
 * they typed in.
 */
enum StyleToken: string
{
    case Accent = 'accent';
    case Text = 'text';
    case Border = 'border';
    case Background = 'background';
    case Radius = 'radius';
    case BorderWidth = 'border_width';
    case InputSize = 'input_size';
    case FontSize = 'font_size';
    case ButtonBackground = 'button_background';
    case ButtonText = 'button_text';
    case ButtonRadius = 'button_radius';

    /**
     * The scales an input may be sized at, by the wording the Checkout tab
     * offers them under.
     *
     * A short list rather than a number a Merchant types: the scale multiplies
     * a padding and a font size at once, and the range over which that stays
     * legible is narrow enough that offering the whole number line would only
     * be offering ways to get it wrong.
     */
    private const INPUT_SIZES = [
        '0.875' => 'Small',
        '1' => 'Medium',
        '1.125' => 'Large',
    ];

    /**
     * The custom property this token is emitted as.
     *
     * Derived from the case rather than listed, so a token cannot be declared
     * under one name and read under another.
     */
    public function property(): string
    {
        return '--gio-'.str_replace('_', '-', $this->value);
    }

    /**
     * What the Checkout is styled with where the Merchant has said nothing.
     *
     * Every one of these is the value the rule carried before it read a token,
     * so a Page nobody has restyled renders as it did — with three exceptions
     * worth knowing, none of which any Merchant asked for and all of which
     * change what an already-published Page looks like:
     *
     * - one radius now rounds the card as well as the controls, where the card
     *   was a shade rounder than everything inside it;
     * - one border colour draws the dividers as well as the boxes, where the
     *   dividers were a shade lighter;
     * - a checkbox or radio is now ticked in the accent colour rather than the
     *   browser's own blue, which is what makes that token cover the ticks it
     *   says it covers.
     *
     * A Checkout is one object and it was drawn like three. The alternative was
     * a token per shade, which is a vocabulary a Merchant has to learn before
     * they can change a colour.
     */
    public function defaultValue(): string
    {
        return match ($this) {
            self::Accent => '#2563eb',

            /** The Page's own text colour, which is what the form has always taken. */
            self::Text => 'inherit',

            self::Border => '#d1d5db',
            self::Background => '#fff',
            self::Radius => '.375rem',
            self::BorderWidth => '1px',
            self::InputSize => '1',
            self::FontSize => '1rem',
            self::ButtonBackground => '#16a34a',
            self::ButtonText => '#fff',
            self::ButtonRadius => '.375rem',
        };
    }

    public function kind(): StyleTokenKind
    {
        return match ($this) {
            self::Accent, self::Text, self::Border, self::Background,
            self::ButtonBackground, self::ButtonText => StyleTokenKind::Colour,

            self::Radius, self::BorderWidth, self::FontSize,
            self::ButtonRadius => StyleTokenKind::Length,

            self::InputSize => StyleTokenKind::Scale,
        };
    }

    /**
     * Whether one field can be styled with this token on its own.
     *
     * True of every token something inside a field wrapper reads. False of the
     * three that dress the order button, because a field holds no button —
     * redeclaring one on a question would emit a declaration nothing consumes,
     * and a control that provably does nothing is worse than no control.
     *
     * This is a statement about what a wrapper contains, not a restriction: the
     * cascade is what confines an override, and nothing here generates a
     * selector either way. A stored Config carrying one of the three on a field
     * is read and emitted like any other; it simply has no effect.
     */
    public function appliesToOneField(): bool
    {
        return ! in_array($this, [self::ButtonBackground, self::ButtonText, self::ButtonRadius], true);
    }

    /**
     * What a value is chosen from, for a token that is chosen rather than
     * typed. Empty for every other kind.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return $this->kind() === StyleTokenKind::Scale ? self::INPUT_SIZES : [];
    }

    /**
     * Why a Merchant may not style with this value, or nothing where they may.
     *
     * Asked of the token rather than of the kind, because a scale is refused
     * against the list it is chosen from and every other kind against a
     * pattern. Blank is not this method's to refuse: an empty box is a Merchant
     * saying nothing, which is how a token goes back to its default and how a
     * field stops overriding one.
     */
    public function refusal(string $value): ?string
    {
        if ($this->kind() !== StyleTokenKind::Scale) {
            return $this->kind()->refusal($value);
        }

        return array_key_exists($value, $this->options())
            ? null
            : StyleTokenKind::SCALE_REFUSAL;
    }

    /**
     * What the Checkout tab calls this token.
     */
    public function label(): string
    {
        return match ($this) {
            self::Accent => 'Accent colour',
            self::Text => 'Text colour',
            self::Border => 'Border colour',
            self::Background => 'Input background',
            self::Radius => 'Corner radius',
            self::BorderWidth => 'Border width',
            self::InputSize => 'Input size',
            self::FontSize => 'Font size',
            self::ButtonBackground => 'Button colour',
            self::ButtonText => 'Button text colour',
            self::ButtonRadius => 'Button corner radius',
        };
    }

    /**
     * What the token does, said in terms of what the Merchant will see change.
     */
    public function helperText(): string
    {
        return match ($this) {
            self::Accent => 'The outline on the box being typed in, and the tick in a checkbox or radio.',
            self::Text => 'Every word in the form. Left empty it takes the page\'s own.',
            self::Border => 'Every line the form draws — around the boxes, and between the rows.',
            self::Background => 'What the boxes a customer types into are filled with. Nothing else is painted, so the page shows through the form itself and through the rows a customer picks from.',
            self::Radius => 'How round the corners are, on the form and on everything in it.',
            self::BorderWidth => 'How heavy the lines are.',
            self::InputSize => 'One size for everything a customer answers in — the boxes and the rows they pick from. It sets their padding and their text together, so a big box never comes with small type.',
            self::FontSize => 'The size everything in the form is measured against. Raise it and the whole form grows with it.',
            self::ButtonBackground => 'The order button.',
            self::ButtonText => 'The words on the order button.',
            self::ButtonRadius => 'How round the order button\'s corners are.',
        };
    }

    /**
     * What the box shows when the Merchant has left it alone, so the value in
     * force is readable without saving to find out.
     */
    public function defaultHint(): string
    {
        return $this->options()[$this->defaultValue()] ?? $this->defaultValue();
    }
}
