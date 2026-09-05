<?php

namespace App\Support\Checkout;

use Illuminate\View\ComponentAttributeBag;

/**
 * One thing a Checkout asks a Customer for.
 *
 * A field is a built-in paired with the two things a Merchant decides about it:
 * how it is worded, and whether an answer is compulsory. Everything else — the
 * key, the control, the rules — comes from the built-in and cannot be
 * configured away.
 */
final class CheckoutField
{
    public function __construct(
        public readonly BuiltInField $builtIn,
        public readonly string $label,
        public readonly bool $required,
    ) {}

    public static function default(BuiltInField $builtIn): self
    {
        return new self($builtIn, $builtIn->defaultLabel(), required: true);
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

    public function placeholder(): ?string
    {
        return $this->builtIn->placeholder();
    }

    /**
     * @return list<string>
     */
    public function options(): array
    {
        return $this->builtIn->options();
    }

    public function controlAttributes(): ComponentAttributeBag
    {
        return new ComponentAttributeBag($this->builtIn->controlAttributes());
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
     * @return array{key: string, label: string, required: bool}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key(),
            'label' => $this->label,
            'required' => $this->required,
        ];
    }
}
