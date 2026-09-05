<?php

namespace App\Support\Checkout;

use Illuminate\View\ComponentAttributeBag;

/**
 * One thing a Checkout asks a Customer for.
 *
 * A field is a built-in paired with what a Merchant decides about it: how it is
 * worded, what it hints at, what it explains, and whether an answer is
 * compulsory. Everything else — the key, the control, the rules — comes from
 * the built-in and cannot be configured away.
 *
 * It answers for its built-in rather than handing it out, so a form and a rule
 * set are written against "a field" and not against what kind of field it
 * happens to be. That is what lets the Merchant's own fields join this list
 * later without either caller learning about the difference (ADR-0006).
 *
 * What the Merchant decided is a property; what the built-in dictates is a
 * method. Reading `$field->label` and calling `$field->options()` in one breath
 * is that split, not an inconsistency.
 */
class CheckoutField
{
    public function __construct(
        public readonly BuiltInField $builtIn,
        public readonly string $label,
        public readonly bool $required,
        public readonly ?string $placeholder = null,
        public readonly ?string $help = null,
    ) {}

    public static function default(BuiltInField $builtIn): self
    {
        return new self(
            $builtIn,
            $builtIn->defaultLabel(),
            required: true,
            placeholder: $builtIn->defaultPlaceholder(),
        );
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(BuiltInField $builtIn, array $stored): self
    {
        $label = $stored['label'] ?? null;

        return new self(
            $builtIn,
            /** A blank label would render a form nobody can read. */
            is_string($label) && trim($label) !== '' ? $label : $builtIn->defaultLabel(),
            (bool) ($stored['required'] ?? true),
            /**
             * Absent and blank mean different things. A Config written before
             * placeholders were configurable has no such key, and must keep
             * rendering the wording the field has always carried; a Merchant who
             * empties the box means they want no hint at all.
             */
            array_key_exists('placeholder', $stored)
                ? self::wording($stored['placeholder'])
                : $builtIn->defaultPlaceholder(),
            self::wording($stored['help'] ?? null),
        );
    }

    public function key(): string
    {
        return $this->builtIn->value;
    }

    public function type(): CheckoutFieldType
    {
        return $this->builtIn->type();
    }

    /**
     * @return list<string>
     */
    public function options(): array
    {
        return $this->builtIn->options();
    }

    /**
     * The HTML the control carries: what the built-in always says, plus the
     * merchant's hint wherever the control is one that can show a hint.
     *
     * A Select spends its placeholder on its unpickable first option instead,
     * so it must never also carry the attribute — a browser ignores it on a
     * closed list and the merchant would have written the same hint twice. That
     * decision lives here rather than in the view for the same reason the rules
     * do: a caller writes against "a field" and never learns what kind of field
     * it is.
     *
     * Merged in rather than constructed with, because merging is what escapes:
     * the bag's own `__toString` does not, and this is the one attribute here a
     * merchant types.
     */
    public function controlAttributes(): ComponentAttributeBag
    {
        return (new ComponentAttributeBag($this->builtIn->controlAttributes()))
            ->merge(array_filter([
                'placeholder' => $this->type() === CheckoutFieldType::Select ? null : $this->placeholder,
            ]));
    }

    /**
     * @return list<mixed>
     */
    public function rules(): array
    {
        /**
         * `nullable` rather than simply leaving the rules off: a blank answer
         * reaches validation as null, and without it an optional District left
         * alone would be checked for membership of a list it is not in.
         */
        return [$this->required ? 'required' : 'nullable', ...$this->builtIn->rules()];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->builtIn->messages();
    }

    /**
     * @return array{key: string, label: string, required: bool, placeholder: string|null, help: string|null}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key(),
            'label' => $this->label,
            'required' => $this->required,
            'placeholder' => $this->placeholder,
            'help' => $this->help,
        ];
    }

    /**
     * Wording a Merchant typed, or null where they typed nothing.
     *
     * Whitespace collapses to null so an accidental space does not render an
     * empty help paragraph or a hint made of one blank character.
     */
    private static function wording(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
