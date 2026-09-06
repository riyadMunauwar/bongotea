<?php

namespace App\Support\Checkout;

/**
 * How much of a row one Checkout Field takes.
 *
 * Two widths and no number, because the form is a grid of two columns and a
 * question either fills it or takes half of it. A figure a Merchant typed would
 * be a figure that has to resolve against a container whose width nobody here
 * knows — the element they dropped the shortcode into — and the arithmetic
 * would land differently on every Page it was copied to.
 *
 * Half is a promise about wide screens only. Below the breakpoint every
 * question is full width, unconditionally: the traffic these Pages answer is
 * overwhelmingly phone-sized, and a Merchant cannot be given a control whose
 * only use is to break the form for most of the people who see it. That is why
 * the pairing lives inside a media query in the one stylesheet and not in
 * anything a field emits — see the Checkout island.
 *
 * A screen and not a container, which is worth knowing before reading the
 * media query as more than it says: a Merchant who boxes the Checkout narrow
 * with `StyleToken::MaxWidth` and then opens it on a desktop gets two columns
 * inside that box. The alternative is a container query, which would mean
 * making the Checkout root a size container — containment imposed on a document
 * we do not own — for a case a Merchant produces deliberately and can undo by
 * widening the box or by leaving the questions full width.
 */
enum FieldWidth: string
{
    case Full = 'full';
    case Half = 'half';

    /**
     * What a stored field's width is, whatever it happens to hold.
     *
     * Total, like everything a Checkout Config is read from. A width nothing
     * renders, and a Config written before a question could sit beside another,
     * both mean the full row the form has always given every question.
     */
    public static function fromStored(mixed $stored): self
    {
        return (is_string($stored) ? self::tryFrom($stored) : null) ?? self::Full;
    }

    /**
     * What this width adds to the field wrapper's class attribute, leading
     * space and all.
     *
     * Blank for a full-width question, so a Page where nobody paired anything
     * renders the markup it always did. The class it does add is a flat one the
     * base stylesheet already carries: nothing is generated per field, and a
     * Merchant's own stylesheet still beats it at the specificity it always
     * did.
     */
    public function classSuffix(): string
    {
        return $this === self::Half ? ' gio-checkout-field-half' : '';
    }

    /**
     * What the Checkout tab calls this width.
     *
     * One wording read from two places, like `CheckoutFieldType::label()`: the
     * list a Merchant picks from, and the line a collapsed question row says
     * about itself.
     */
    public function label(): string
    {
        return match ($this) {
            self::Full => 'Full width',
            self::Half => 'Half width',
        };
    }

    /**
     * Both widths, in the order a Merchant is offered them.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = [];

        foreach (self::cases() as $width) {
            $labels[$width->value] = $width->label();
        }

        return $labels;
    }
}
