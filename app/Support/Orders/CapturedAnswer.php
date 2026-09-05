<?php

namespace App\Support\Orders;

use App\Support\Checkout\CheckoutFieldType;

/**
 * What a Customer said to one of the questions a Merchant added of their own,
 * as an Order records it.
 *
 * The question is copied in beside the answer — its key, the wording it was
 * asked in, the control it was asked with, and for a choice the wording of the
 * option picked. An Order records everything it needs to be fulfilled without
 * consulting anything else, so relabelling the field, deleting it or
 * force-deleting the Page must leave this reading exactly as it did (ADR-0006).
 *
 * A question that was asked and left unanswered is recorded too, with nothing
 * for a value. "We never asked" and "they had nothing to say" are worth telling
 * apart, and only the second is worth calling a Customer about.
 */
class CapturedAnswer
{
    /**
     * The answer as a person reads it.
     *
     * Computed here rather than at the screen, because both Order screens want
     * the same sentence and neither may reach back to the Page to work it out.
     * A choice reads as the wording it was picked under, a checkbox as yes or
     * no, and an unanswered question as nothing at all — which is what puts the
     * screen's own placeholder there.
     */
    public readonly string $display;

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly CheckoutFieldType $type,
        public readonly string|bool|null $value,
        public readonly ?string $optionLabel = null,
    ) {
        $this->display = match (true) {
            $this->type === CheckoutFieldType::Checkbox => $this->value === true ? 'Yes' : 'No',
            $this->optionLabel !== null => $this->optionLabel,
            is_string($this->value) => $this->value,
            default => '',
        };
    }

    /**
     * Rebuild an answer from its stored shape, or nothing where there is no
     * answer to rebuild.
     *
     * Total on purpose, like every other read of stored JSON here: an Order
     * screen that throws on one unreadable row is a fulfiller who cannot work
     * any of the others.
     *
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored): ?self
    {
        $key = $stored['key'] ?? null;
        $type = $stored['type'] ?? null;
        $type = is_string($type) ? CheckoutFieldType::tryFrom($type) : null;

        if (! is_string($key) || $key === '' || $type === null) {
            return null;
        }

        $label = $stored['label'] ?? null;
        $value = $stored['value'] ?? null;
        $optionLabel = $stored['option_label'] ?? null;

        return new self(
            $key,
            /** A snapshot with no wording still names its question. */
            is_string($label) && $label !== '' ? $label : $key,
            $type,
            match (true) {
                is_bool($value) => $value,
                is_string($value) => $value,
                is_int($value) || is_float($value) => (string) $value,
                default => null,
            },
            is_string($optionLabel) && $optionLabel !== '' ? $optionLabel : null,
        );
    }

    /**
     * @return array{key: string, label: string, type: string, value: string|bool|null, option_label: string|null}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type->value,
            'value' => $this->value,
            'option_label' => $this->optionLabel,
        ];
    }
}
