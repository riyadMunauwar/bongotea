<?php

namespace App\Support\Checkout;

/**
 * The control a Checkout Field is asked with.
 *
 * The six a Merchant may reach for, and the three the built-ins use, are one
 * list: a field is presented and validated by its type, never by whose field it
 * is (ADR-0006). What differs between a built-in Select and a Merchant's own is
 * where the choices come from, not how the control behaves.
 */
enum CheckoutFieldType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Number = 'number';
    case Select = 'select';
    case Radio = 'radio';
    case Checkbox = 'checkbox';

    /**
     * Whether this control is a choice between listed options.
     *
     * The two that are carry a list a Customer may not answer outside of, and
     * are the two whose answers snapshot the chosen option's wording beside the
     * value submitted — a value alone stops meaning anything the moment the
     * Merchant rewords the option.
     */
    public function hasOptions(): bool
    {
        return match ($this) {
            self::Select, self::Radio => true,
            default => false,
        };
    }

    /**
     * Whether this control is answered by writing something.
     *
     * The two that are take a length and a shape from `FieldConstraints`. A
     * number is measured rather than counted and is bounded by its own pair,
     * and a choice or a box is answered by picking, where the only constraint
     * that means anything is the list itself.
     */
    public function holdsWriting(): bool
    {
        return match ($this) {
            self::Text, self::Textarea => true,
            default => false,
        };
    }

    public function holdsNumber(): bool
    {
        return $this === self::Number;
    }

    /**
     * The longest answer this control will take whatever a Merchant asks for.
     *
     * A ceiling rather than a default: a Merchant may cap a question further
     * down, never further up. It is stated once here so that the attribute the
     * browser is given and the rule a Capture checks come off one figure.
     */
    public function lengthCeiling(): ?int
    {
        return match ($this) {
            self::Text => 255,
            self::Textarea => 1000,
            default => null,
        };
    }

    /**
     * Whether the control can carry a shape as an HTML `pattern`.
     *
     * A textarea cannot: HTML has no such attribute on one. The shape is still
     * checked at Capture, which fails in the safe direction — the browser stops
     * nothing the server would have taken.
     */
    public function carriesPatternAttribute(): bool
    {
        return $this === self::Text;
    }

    /**
     * Whether a hint of the Merchant's is worth asking for at all.
     *
     * A radio group and a checkbox have nowhere to put one: every choice is on
     * screen already, and a greyed-out hint has no box to sit inside of.
     */
    public function showsPlaceholder(): bool
    {
        return match ($this) {
            self::Radio, self::Checkbox => false,
            default => true,
        };
    }

    /**
     * Whether the control shows that hint as an HTML `placeholder`.
     *
     * A Select spends it on its unpickable first option instead, so it must
     * never also carry the attribute — a browser ignores it on a closed list
     * and the Merchant would have written the same hint twice.
     */
    public function carriesPlaceholderAttribute(): bool
    {
        return $this->showsPlaceholder() && $this !== self::Select;
    }

    /**
     * How the Checkout tab words each type in the list a Merchant picks from.
     *
     * Not `options()`: that word is a Checkout Field's own, and means the
     * choices a Customer answers between.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Text->value => 'Single line',
            self::Textarea->value => 'Paragraph',
            self::Number->value => 'Number',
            self::Select->value => 'Dropdown',
            self::Radio->value => 'Radio buttons',
            self::Checkbox->value => 'Checkbox',
        ];
    }
}
