<?php

namespace App\Support\Checkout;

/**
 * One choice a Checkout Field offers.
 *
 * A value and the wording it is offered under. They are separate because only
 * the value is submitted, and a Merchant rewording "Sky blue" to "Light blue"
 * next month must not change what a Customer chose today — so an Order
 * snapshots the wording beside the value rather than looking it up afterwards.
 *
 * The value is the option's identity. A field cannot offer two options under
 * one value, which is refused on the Checkout tab and deduplicated here.
 */
class FieldOption
{
    public function __construct(
        public readonly string $value,
        public readonly string $label,
    ) {}

    /**
     * The same word twice, for a list whose values are their own wording —
     * installation-configured Districts, and anything else a caller has as a
     * plain list of strings.
     */
    public static function of(string $value): self
    {
        return new self($value, $value);
    }

    /**
     * Rebuild an option from its stored shape, or nothing where there is no
     * option to rebuild.
     *
     * Total on purpose, like everything a Checkout Config is read from: a
     * valueless option is dropped rather than rendered as a choice that submits
     * nothing. A blank wording falls back to the value, so an option is never
     * offered as an empty line a Customer cannot read.
     *
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored): ?self
    {
        $value = $stored['value'] ?? null;
        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            return null;
        }

        $label = $stored['label'] ?? null;
        $label = is_string($label) ? trim($label) : '';

        return new self($value, $label === '' ? $value : $label);
    }

    /**
     * @return array{value: string, label: string}
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label,
        ];
    }
}
