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
    /**
     * How wide the Checkout sits, which is a token and not a layout.
     *
     * Boxed at whatever maximum the Merchant names, or `100%` to fill the
     * element they dropped the shortcode into. Both are the same declaration
     * reading the same property, which is why choosing between them adds no
     * rule, no class and no bytes — and why a Page arranged either way is
     * styled by the same sheet.
     */
    case MaxWidth = 'max_width';

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
     * What a refusal is written in: the words beside a box, and the ground the
     * banner across the top paints for itself.
     *
     * A token because the default is a red drawn for a white Page, and on a
     * Page styled dark it is the one thing in the form a Customer cannot read
     * — the message telling them why their Order did not go through. The
     * banner does not depend on it being readable against the Page: it fills
     * itself with this colour and writes in white, so the whole-form case is
     * legible before a Merchant has touched anything.
     */
    case Error = 'error';

    /**
     * What a picked thing is painted in: the tick, the row's border and a light
     * tint of its ground, for a picked Product and a chosen Delivery Option
     * alike.
     *
     * One token because on a cash-on-delivery Page an item nobody meant to
     * choose is a refused parcel and courier fees both ways, so a Customer has
     * to be able to tell at a glance what they have committed to — and a
     * Merchant has to be able to make that state louder in one place rather
     * than three. Its default is the accent rather than a colour of its own, so
     * a Merchant who set only an accent gets a selected state to match and a
     * Page published before it existed looks as it did.
     */
    case Selected = 'selected';

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
            /** The width the card has been boxed at since it was written. */
            self::MaxWidth => '30rem',

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

            /** The red a refusal has always been written in. */
            self::Error => '#b91c1c',

            /**
             * The accent, read rather than copied: a `var()` here is resolved
             * on the root against whatever the Merchant set the accent to, so
             * an unset token follows the accent instead of freezing today's
             * default blue. A Merchant may not type this — `refusal()` turns
             * a `var()` away like any other non-colour — because a value
             * naming another token is one the tab cannot show the effect of.
             */
            self::Selected => 'var(--gio-accent)',
        };
    }

    public function kind(): StyleTokenKind
    {
        return match ($this) {
            self::Accent, self::Text, self::Border, self::Background,
            self::ButtonBackground, self::ButtonText, self::Error, self::Selected => StyleTokenKind::Colour,

            self::Radius, self::BorderWidth, self::FontSize,
            self::ButtonRadius => StyleTokenKind::Length,

            self::InputSize => StyleTokenKind::Scale,

            self::MaxWidth => StyleTokenKind::Width,
        };
    }

    /**
     * Whether one field can be styled with this token on its own.
     *
     * True of every token something inside a field wrapper reads. False of the
     * three that dress the order button, because a field holds no button, and
     * false of the width, because a field's own is the grid's to decide and is
     * chosen from two — see `FieldWidth`. Redeclaring any of the four on a
     * question would emit a declaration nothing consumes, and a control that
     * provably does nothing is worse than no control.
     *
     * This is a statement about what a wrapper contains, not a restriction: the
     * cascade is what confines an override, and nothing here generates a
     * selector either way. A stored Config carrying one of the three on a field
     * is read and emitted like any other; it simply has no effect.
     */
    public function appliesToOneField(): bool
    {
        return ! in_array($this, [self::MaxWidth, self::ButtonBackground, self::ButtonText, self::ButtonRadius], true);
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
            self::MaxWidth => 'Width',
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
            self::Error => 'Error colour',
            self::Selected => 'Selected colour',
        };
    }

    /**
     * What the token does, said in terms of what the Merchant will see change.
     */
    public function helperText(): string
    {
        return match ($this) {
            self::MaxWidth => 'The widest the form gets before it stops growing, centred in whatever you put [checkout] inside. Use 100% to fill that element completely.',
            self::Accent => 'The outline on the box being typed in. Until a selected colour is set, it is also what a picked product or delivery row is marked in.',
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
            self::Error => 'What a refusal is written in, beside the box it is about, and what the banner across the top is filled with when the whole order was turned away. The banner writes on itself in white, so it reads on any page; on a dark page, lighten this so the words beside a box do too.',
            self::Selected => 'What a picked product or a chosen delivery row is marked in — the tick, the row\'s border and a light tint behind it — so a customer can see at a glance what they have committed to. Left empty it follows the accent colour.',
        };
    }

    /**
     * What the box shows when the Merchant has left it alone, so the value in
     * force is readable without saving to find out.
     *
     * The selected colour's default is a reference rather than a value, and a
     * `var()` in a placeholder tells a Merchant nothing; what they need to know
     * is which box it is following.
     */
    public function defaultHint(): string
    {
        if ($this === self::Selected) {
            return 'Follows the accent colour';
        }

        return $this->options()[$this->defaultValue()] ?? $this->defaultValue();
    }
}
