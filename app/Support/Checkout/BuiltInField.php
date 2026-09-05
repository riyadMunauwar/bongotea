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
 * be written there. That is why a built-in's key and type never appear as
 * controls on the Checkout tab, and why none of them can be deleted. See
 * ADR-0006.
 *
 * A Config also decides whether a field is asked at all — with one exception,
 * kept here rather than in the admin so no route to a Config can get around it.
 *
 * Case order is the order a Checkout asks in by default. Email sits where it
 * belongs among the questions rather than at the end, and starts switched off,
 * so the rendered form is still exactly the one that stood here before any of
 * this was configurable.
 */
enum BuiltInField: string
{
    case Name = 'name';
    case Phone = 'phone';
    case Email = 'email';
    case District = 'district';
    case Address = 'address';

    public function type(): CheckoutFieldType
    {
        return match ($this) {
            self::Name, self::Phone, self::Email => CheckoutFieldType::Text,
            self::District => CheckoutFieldType::Select,
            self::Address => CheckoutFieldType::Textarea,
        };
    }

    /**
     * Whether a Checkout may stop asking this at all.
     *
     * Phone alone may not. A Customer *is* their phone number — it is the
     * identity every Order is filed under — and a cash-on-delivery Order nobody
     * can call is not a fulfillable Order, it is a lead. So the switch is
     * refused here rather than only hidden in the admin, because a Config is
     * editable by other routes than the Checkout tab. See ADR-0006.
     */
    public function isMandatory(): bool
    {
        return $this === self::Phone;
    }

    /**
     * Whether a new Page asks this to begin with.
     *
     * The four the form has always asked for stay on, so a Merchant who never
     * opens the Checkout tab sees no change. Email is the one addition, and it
     * arrives off: adding a question to every live campaign's form is not
     * something a deployment gets to do on a Merchant's behalf.
     */
    public function defaultsToEnabled(): bool
    {
        return $this !== self::Email;
    }

    /**
     * Whether an answer is compulsory to begin with, on the day the field is
     * switched on.
     *
     * Independent of whether it is asked at all: a Merchant switching Email on
     * gets an optional box, because a COD buyer who has no email address must
     * still be able to order. Compulsory is theirs to decide afterwards.
     */
    public function defaultsToRequired(): bool
    {
        return $this !== self::Email;
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
            self::Email => 'Email address',
            self::District => 'District',
            self::Address => 'Delivery address',
        };
    }

    /**
     * The hint a Page starts with: the unselectable first option of a Select,
     * and nothing at all for anything else.
     *
     * A default rather than a fixture. A Merchant may reword it or empty it
     * from the Checkout tab, and what they leave is what renders.
     */
    public function defaultPlaceholder(): ?string
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
     * Bangladesh replaces the list wholesale. Each is its own wording, which is
     * why they are made rather than read as a value and a label — a district is
     * chosen by its name, and there is no second name to record.
     *
     * @return list<FieldOption>
     */
    public function options(): array
    {
        return match ($this) {
            self::District => array_map(
                fn (string $district): FieldOption => FieldOption::of($district),
                array_values((array) config('giopages.districts')),
            ),
            default => [],
        };
    }

    /**
     * HTML the control always carries: the autofill hint, the keyboard a phone
     * should open, and the length the server enforces anyway.
     *
     * Never a `placeholder`. That word is the Merchant's, and CheckoutField
     * merges theirs in as a default — one declared here would outrank it and
     * could not be reworded from the Checkout tab.
     *
     * @return array<string, string>
     */
    public function controlAttributes(): array
    {
        return match ($this) {
            self::Name => ['type' => 'text', 'autocomplete' => 'name', 'maxlength' => '255'],
            self::Phone => ['type' => 'tel', 'autocomplete' => 'tel', 'inputmode' => 'tel', 'maxlength' => '32'],
            self::Email => ['type' => 'email', 'autocomplete' => 'email', 'inputmode' => 'email', 'maxlength' => '255'],
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

            /**
             * Checked for shape only, never for deliverability: a Merchant who
             * asks for an email wants a receipt address, and refusing a real
             * buyer at the one moment they are ready to order costs more than a
             * mistyped address does.
             */
            self::Email => ['string', 'email:filter', 'max:255'],

            self::District => ['string', Rule::in(FieldOption::values($this->options()))],
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
            self::Email => [
                $this->value.'.required' => 'Please enter your email address.',
                $this->value.'.email' => 'Please enter an email address we can reach you at.',
            ],
            self::District => [
                $this->value.'.required' => 'Please choose your district.',
                $this->value.'.in' => 'Please choose your district from the list.',
            ],
            self::Address => [$this->value.'.required' => 'Please enter the address we should deliver to.'],
        };
    }
}
