<?php

namespace App\Support\Checkout;

use App\Support\PhoneNumber;
use Illuminate\Validation\Rule;

/**
 * The Checkout Fields the system knows by name.
 *
 * Everything immutable about one of them lives here: its key, the control it is
 * asked with, the markup that opens the right keyboard on a phone, and what the
 * server checks the answer against. A Page's Checkout Config decides how each
 * is worded and whether an answer is compulsory; it can never decide what a
 * `phone` is, because a column is waiting for the answer and nothing else may
 * be written there. See ADR-0006.
 *
 * Case order is the order a Checkout asks in by default, and it reproduces the
 * form exactly as it stood before any of this was configurable.
 */
enum BuiltInField: string
{
    case Name = 'name';
    case Phone = 'phone';
    case District = 'district';
    case Address = 'address';

    public function type(): CheckoutFieldType
    {
        return match ($this) {
            self::Name, self::Phone => CheckoutFieldType::Text,
            self::District => CheckoutFieldType::Select,
            self::Address => CheckoutFieldType::Textarea,
        };
    }

    /**
     * The wording a Page starts with, which is the wording every Page used
     * before a Config could change it.
     */
    public function defaultLabel(): string
    {
        return match ($this) {
            self::Name => 'Your name',
            self::Phone => 'Phone number',
            self::District => 'District',
            self::Address => 'Delivery address',
        };
    }

    /**
     * The unselectable first option of a Select, and null for anything else.
     */
    public function placeholder(): ?string
    {
        return match ($this) {
            self::District => 'Choose your district',
            default => null,
        };
    }

    /**
     * What a Select offers.
     *
     * District's choices are installation configuration rather than part of the
     * Config: they are not data a Merchant manages, and a Merchant outside
     * Bangladesh replaces the list wholesale.
     *
     * @return list<string>
     */
    public function options(): array
    {
        return match ($this) {
            self::District => array_values((array) config('giopages.districts')),
            default => [],
        };
    }

    /**
     * HTML the control always carries: the autofill hint, the keyboard a phone
     * should open, and the length the server enforces anyway.
     *
     * @return array<string, string>
     */
    public function controlAttributes(): array
    {
        return match ($this) {
            self::Name => ['type' => 'text', 'autocomplete' => 'name', 'maxlength' => '255'],
            self::Phone => ['type' => 'tel', 'autocomplete' => 'tel', 'inputmode' => 'tel', 'maxlength' => '32'],
            self::District => [],
            self::Address => ['rows' => '3', 'autocomplete' => 'street-address', 'maxlength' => '1000'],
        };
    }

    /**
     * What an answer must look like, beyond whether it was given at all.
     *
     * Whether it was given at all belongs to the Config, so nothing here says
     * `required` or `nullable`; CheckoutField puts one of the two in front.
     *
     * @return list<mixed>
     */
    public function rules(): array
    {
        return match ($this) {
            self::Name => ['string', 'max:255'],

            /**
             * Not a format check for its own sake: a cash-on-delivery Order
             * nobody can call is not fulfillable.
             */
            self::Phone => ['string', 'max:32', fn (string $attribute, mixed $value, callable $fail) => is_string($value) && PhoneNumber::isValid($value)
                ? null
                : $fail('Please enter a valid phone number we can call you on.')],

            self::District => ['string', Rule::in($this->options())],
            self::Address => ['string', 'max:1000'],
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        /** Keyed off the case's own value, so a message cannot outlive its key. */
        return match ($this) {
            self::Name => [$this->value.'.required' => 'Please enter your name.'],
            self::Phone => [$this->value.'.required' => 'Please enter a phone number we can call you on.'],
            self::District => [
                $this->value.'.required' => 'Please choose your district.',
                $this->value.'.in' => 'Please choose your district from the list.',
            ],
            self::Address => [$this->value.'.required' => 'Please enter the address we should deliver to.'],
        };
    }
}
