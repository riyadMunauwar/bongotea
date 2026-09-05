<?php

namespace App\Support\Checkout;

use Illuminate\Contracts\Support\Arrayable;

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
     */
    private function __construct(public readonly array $fields) {}

    /**
     * The Checkout a Page starts with, and the one every existing Page was
     * backfilled to: the four details the form has always asked for, in the
     * order it has always asked them, every one of them required.
     */
    public static function default(): self
    {
        return new self(array_map(CheckoutField::default(...), BuiltInField::cases()));
    }

    /**
     * Rebuild a Config from its stored shape.
     *
     * Total on purpose: an unrecognised key is dropped, a built-in the stored
     * shape never mentioned is appended with its defaults, and something
     * unreadable yields the default Config. A Checkout asking one question more
     * than a Merchant meant is fixed at their desk; a Checkout that throws is a
     * landing page answering paid traffic with a 500.
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

            $builtIn = is_string($key) ? BuiltInField::tryFrom($key) : null;

            /** A key twice over would ask twice and store once. */
            if ($builtIn === null || isset($fields[$builtIn->value])) {
                continue;
            }

            $fields[$builtIn->value] = CheckoutField::fromArray($builtIn, $field);
        }

        foreach (BuiltInField::cases() as $builtIn) {
            $fields[$builtIn->value] ??= CheckoutField::default($builtIn);
        }

        return new self(array_values($fields));
    }

    /**
     * The server side of the one definition.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = [];

        foreach ($this->fields as $field) {
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

        foreach ($this->fields as $field) {
            $messages = [...$messages, ...$field->messages()];
        }

        return $messages;
    }

    /**
     * @return array{fields: list<array{key: string, label: string, required: bool, placeholder: string|null, help: string|null}>}
     */
    public function toArray(): array
    {
        return ['fields' => $this->fieldsToArray()];
    }

    /**
     * The field list in its stored shape, which is also the shape the Checkout
     * tab edits a row at a time. Named so the admin can ask for it without
     * knowing which key it is filed under here.
     *
     * @return list<array{key: string, label: string, required: bool, placeholder: string|null, help: string|null}>
     */
    public function fieldsToArray(): array
    {
        return array_map(fn (CheckoutField $field): array => $field->toArray(), $this->fields);
    }
}
