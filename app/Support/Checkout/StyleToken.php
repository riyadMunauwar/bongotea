<?php

namespace App\Support\Checkout;

/**
 * One thing a Merchant can restyle about a Checkout without writing CSS.
 *
 * The named set is the whole of the styling model, and there are eleven. Each
 * token is a custom property declared once on the Checkout root and read by the
 * flat rules that were already there, so restyling is a redeclaration rather
 * than a new rule — which is what keeps the Merchant's own stylesheet able to
 * win an argument with us, and what keeps the emitted styles the same length
 * on a Checkout asking twenty questions as on one asking two.
 *
 * Every token is set once, for the whole Checkout, and applies to every
 * Checkout Layout alike. No single Checkout Field restyles itself: a form is
 * styled in exactly one place, the Look tab, and a stored Config that once
 * restyled one question simply follows the Checkout now.
 *
 * There is no token for anything a Merchant cannot see the effect of, and no
 * token for a figure the design fixes — borders are one pixel, the type is one
 * size, and how wide a form sits is the layout's to say.
 */
enum StyleToken: string
{
    case Accent = 'accent';
    case Text = 'text';
    case Muted = 'muted';
    case Border = 'border';
    case Surface = 'surface';
    case Background = 'background';
    case Radius = 'radius';
    case Font = 'font';
    case ButtonBackground = 'button_background';
    case ButtonText = 'button_text';
    case ButtonRadius = 'button_radius';

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
     * The design's own tokens (the handoff README's `--gp-*` set): the accent
     * blue, ink for text and its third shade for what is meant to recede, the
     * input border, white surfaces, the grey the designs paint behind the
     * card, and the two-pixel radius the designs draw on every input and
     * button. A Merchant whose Page already paints a ground types
     * `transparent` into the background to let it show through.
     */
    public function defaultValue(): string
    {
        return match ($this) {
            self::Accent => '#2451d6',
            self::Text => '#111318',
            self::Muted => '#6b7280',
            self::Border => '#d9dde5',
            self::Surface => '#ffffff',
            self::Background => '#f7f8fa',
            self::Radius => '2px',
            self::Font => CheckoutFont::stack(),
            self::ButtonBackground => '#2451d6',
            self::ButtonText => '#ffffff',
            self::ButtonRadius => '2px',
        };
    }

    public function kind(): StyleTokenKind
    {
        return match ($this) {
            self::Accent, self::Text, self::Muted, self::Border, self::Surface,
            self::Background, self::ButtonBackground, self::ButtonText => StyleTokenKind::Colour,

            self::Radius, self::ButtonRadius => StyleTokenKind::Length,

            self::Font => StyleTokenKind::Font,
        };
    }

    /**
     * Why a Merchant may not style with this value, or nothing where they may.
     *
     * Blank is not this method's to refuse: an empty box is a Merchant saying
     * nothing, which is how a token goes back to its default.
     */
    public function refusal(string $value): ?string
    {
        return $this->kind()->refusal($value);
    }

    /**
     * What the Look tab calls this token.
     */
    public function label(): string
    {
        return match ($this) {
            self::Accent => 'Accent colour',
            self::Text => 'Text colour',
            self::Muted => 'Muted text colour',
            self::Border => 'Border colour',
            self::Surface => 'Surface colour',
            self::Background => 'Background colour',
            self::Radius => 'Corner radius',
            self::Font => 'Font',
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
            self::Accent => 'The outline on the box being typed in, and what a picked product or delivery row is marked in — the tick, the row\'s border and a light tint behind it.',
            self::Text => 'Every word in the form.',
            self::Muted => 'The quieter words: help under a question, a product\'s description, and the line beneath the order button.',
            self::Border => 'Every line the form draws — around the boxes and the rows, and between the sections.',
            self::Surface => 'What the card, the boxes a customer types into and the rows they pick from are filled with.',
            self::Background => 'The ground the whole form sits on, behind the card. Type transparent to let the page show through.',
            self::Radius => 'How round the corners are on the boxes a customer types into, the stepper and the pictures. The card and the rows stay square, as designed.',
            self::Font => 'The typeface the whole form is set in, as a list of names to try in order. Hind Siliguri is served from this site, so Bangla reads well without a request to anyone else. Type inherit to use the page\'s own font instead.',
            self::ButtonBackground => 'The order button.',
            self::ButtonText => 'The words on the order button.',
            self::ButtonRadius => 'How round the order button\'s corners are.',
        };
    }
}
