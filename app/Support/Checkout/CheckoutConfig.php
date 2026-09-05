<?php

namespace App\Support\Checkout;

use App\Support\Money;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;

/**
 * Everything one Page decides about its own Checkout.
 *
 * Self-contained and per Page: nothing is inherited at render time, so two
 * Pages asking the same questions hold two copies and editing one changes one.
 * ADR-0008 records why every form of sharing was rejected.
 *
 * One definition, two renderings. The same field list produces the form a
 * visitor fills in and the rules the server checks their answers against, so
 * the two cannot drift into a Customer being blocked from typing something the
 * server would have accepted.
 *
 * @implements Arrayable<string, mixed>
 */
class CheckoutConfig implements Arrayable
{
    /**
     * @param  list<CheckoutField>  $fields
     * @param  list<DeliveryOption>  $deliveryOptions
     */
    private function __construct(
        public readonly array $fields,
        public readonly array $deliveryOptions,

        /**
         * Whether the Customer chooses which of this Page's Products to buy.
         *
         * The Merchant's switch and nothing more. Whether a Checkout actually
         * asks the question depends on how many Products the Page still sells,
         * which is Page's to answer — see `Page::offersProductChoice()`.
         */
        public readonly bool $productPicker,
    ) {}

    /**
     * The Checkout a Page starts with, and the one every existing Page was
     * backfilled to: the four details the form has always asked for, in the
     * order it has always asked them, every one of them on and required, and a
     * single Delivery Option carrying the installation's charge.
     *
     * Email is in the list too and switched off, so a new Page holds a complete
     * Config while rendering the form a Merchant already knows.
     */
    public static function default(): self
    {
        return new self(
            array_map(BuiltInCheckoutField::default(...), BuiltInField::cases()),
            [self::seededDeliveryOption()],

            /**
             * Off, so a Page sells everything it links the way it always has.
             * A picker is a decision a Merchant makes about one campaign, and
             * switching it on for every Page at once would put an unticked box
             * between paid traffic and an order nobody asked us to change.
             */
            productPicker: false,
        );
    }

    /**
     * The one Delivery Option a Page is born with.
     *
     * One option is exactly today's behaviour — an amount added to the total
     * with no question asked — so a Merchant who never opens the Checkout tab
     * sees no change (ADR-0008). The figure comes from installation config
     * because that is where the single global charge lived; from here on it is
     * a starting point rather than the answer, and a Page that edits it is
     * unaffected by anyone changing it.
     */
    private static function seededDeliveryOption(): DeliveryOption
    {
        return new DeliveryOption('Delivery', Money::toMinorUnits(config('giopages.delivery.charge')));
    }

    /**
     * Rebuild a Config from its stored shape.
     *
     * Total on purpose: an unusable field is dropped, a built-in the stored
     * shape never mentioned is appended with its defaults, and something
     * unreadable yields the default Config. A Checkout asking one question more
     * than a Merchant meant is fixed at their desk; a Checkout that throws is a
     * landing page answering paid traffic with a 500.
     *
     * The stored order is the order asked in, whichever kind of field holds a
     * position: what makes a Merchant's own question sit between phone and
     * address is that it was written between them, and nothing here sorts the
     * built-ins back to the front. Only a built-in the stored shape is missing
     * is appended, because it has to exist somewhere and the end is the one
     * place that moves nothing else.
     *
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored): self
    {
        $fields = [];

        foreach ((array) ($stored['fields'] ?? []) as $field) {
            if (! is_array($field)) {
                continue;
            }

            $key = $field['key'] ?? null;

            /** A key twice over would ask twice and store once. */
            if (! is_string($key) || isset($fields[$key])) {
                continue;
            }

            $builtIn = BuiltInField::tryFrom($key);

            $field = $builtIn !== null
                ? BuiltInCheckoutField::fromArray($builtIn, $field)
                : CustomCheckoutField::fromArray($key, $field);

            if ($field !== null) {
                $fields[$key] = $field;
            }
        }

        foreach (BuiltInField::cases() as $builtIn) {
            $fields[$builtIn->value] ??= BuiltInCheckoutField::default($builtIn);
        }

        return new self(
            array_values($fields),
            self::deliveryOptionsFromArray($stored),

            /**
             * Absent means off, which is what every Config written before the
             * picker existed holds and what those Pages have always done: sell
             * everything they link.
             */
            productPicker: (bool) ($stored['product_picker'] ?? false),
        );
    }

    /**
     * The Delivery Options of a stored Config.
     *
     * Absent and empty say different things, so they are told apart. A Config
     * written before a Page could price its own delivery has no such key and
     * keeps charging what it always charged; a Merchant who deleted every row
     * on the Checkout tab means a Page that charges nothing for delivery, and
     * an empty list is how they say so.
     *
     * @param  array<string, mixed>  $stored
     * @return list<DeliveryOption>
     */
    private static function deliveryOptionsFromArray(array $stored): array
    {
        if (! array_key_exists('delivery_options', $stored)) {
            return [self::seededDeliveryOption()];
        }

        $options = [];

        foreach ((array) $stored['delivery_options'] as $option) {
            if (! is_array($option)) {
                continue;
            }

            $option = DeliveryOption::fromArray($option);

            /** A label twice over would offer a choice that decides nothing. */
            if ($option === null || isset($options[$option->label])) {
                continue;
            }

            $options[$option->label] = $option;
        }

        return array_values($options);
    }

    /**
     * The fields a visitor is actually asked, in order.
     *
     * The one list both renderings are built from, so a question the Merchant
     * switched off is gone from the form and gone from the rules together. A
     * field that is off still exists — it keeps its wording and its rules in
     * `fields` for the day it is switched back on — it is simply not asked.
     *
     * @return list<CheckoutField>
     */
    public function enabledFields(): array
    {
        return array_values(array_filter($this->fields, fn (CheckoutField $field): bool => $field->enabled));
    }

    /**
     * Whether this Page delivers at a price at all.
     *
     * False only where the Merchant offers no arrangement whatsoever, which is
     * them saying there is nothing to charge for. An arrangement that happens
     * to cost nothing is not this: it is an option priced at zero, agreed to
     * like any other and recorded on the Order by name.
     */
    public function offersDelivery(): bool
    {
        return $this->deliveryOptions !== [];
    }

    /**
     * Whether this Page asks the Customer how they want it delivered.
     *
     * Only a Page offering more than one arrangement has anything to ask. None
     * charges nothing and one is added straight to the total, and in neither
     * case is there a question worth putting in front of a Customer whose only
     * possible answer is the one they would be given.
     */
    public function offersDeliveryChoice(): bool
    {
        return count($this->deliveryOptions) > 1;
    }

    /**
     * The arrangements a choice is made between, by name.
     *
     * @return list<string>
     */
    public function deliveryLabels(): array
    {
        return array_map(fn (DeliveryOption $option): string => $option->label, $this->deliveryOptions);
    }

    /**
     * The Delivery Option a Capture is charged for, given what the Customer
     * chose — or nothing, where this Page charges for no delivery at all.
     *
     * This is the whole of "the delivery charge is derived from the Page and
     * never accepted from the request": what arrives is a name, and the amount
     * against it is read here. A Page offering one arrangement charges it
     * whatever the submission says, because there was no choice to make and a
     * form claiming otherwise is a form nobody was shown.
     *
     * Null where two or more are offered and the name matches none of them.
     * The request is refused before it reaches a Capture, so that is the shape
     * of a Merchant renaming an option under a visitor mid-form — a refusal,
     * never a Customer charged for an arrangement they never picked.
     */
    public function deliveryFor(?string $label): ?DeliveryOption
    {
        if (! $this->offersDelivery()) {
            return null;
        }

        if (! $this->offersDeliveryChoice()) {
            return $this->deliveryOptions[0];
        }

        foreach ($this->deliveryOptions as $option) {
            if ($option->label === $label) {
                return $option;
            }
        }

        return null;
    }

    /**
     * The server side of the one definition.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = [];

        foreach ($this->enabledFields() as $field) {
            $rules[$field->key()] = $field->rules();
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [];

        foreach ($this->enabledFields() as $field) {
            $messages = [...$messages, ...$field->messages()];
        }

        return $messages;
    }

    /**
     * What a refusal calls each question when no message of ours words it.
     *
     * The Merchant's own wording rather than the key they filed it under, so a
     * Customer is never told that "the gift_wrap field" is wrong. Lower-cased
     * because Laravel drops the name mid-sentence.
     *
     * @return array<string, string>
     */
    public function validationAttributes(): array
    {
        $attributes = [];

        foreach ($this->enabledFields() as $field) {
            $attributes[$field->key()] = Str::lower($field->label);
        }

        return $attributes;
    }

    /**
     * @return array{fields: list<array<string, mixed>>, delivery_options: list<array{label: string, amount: int}>, product_picker: bool}
     */
    public function toArray(): array
    {
        return [
            'fields' => $this->fieldsToArray(),
            'delivery_options' => $this->deliveryOptionsToArray(),
            'product_picker' => $this->productPicker,
        ];
    }

    /**
     * The field list in its stored shape, which is also the shape the Checkout
     * tab edits a row at a time. Named so the admin can ask for it without
     * knowing which key it is filed under here.
     *
     * @return list<array<string, mixed>>
     */
    public function fieldsToArray(): array
    {
        return array_map(fn (CheckoutField $field): array => $field->toArray(), $this->fields);
    }

    /**
     * The Delivery Options in their stored shape, which is also the shape the
     * Checkout tab edits a row at a time.
     *
     * @return list<array{label: string, amount: int}>
     */
    public function deliveryOptionsToArray(): array
    {
        return array_map(fn (DeliveryOption $option): array => $option->toArray(), $this->deliveryOptions);
    }
}
