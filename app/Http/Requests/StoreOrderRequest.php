<?php

namespace App\Http\Requests;

use App\Models\Page;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a Capture.
 *
 * The visitor submits from a page that may have been cached on disk for days,
 * so everything the request asserts about that page is re-checked here against
 * the database rather than trusted from the payload. Nothing priced is
 * accepted at all: the request carries a slug and a quantity, and the price
 * comes from the Page's own Product.
 */
class StoreOrderRequest extends FormRequest
{
    private ?Page $page = null;

    /**
     * Taking an Order is the public act this whole system exists for. There is
     * no user to authorize.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /**
             * Scoped to published, undeleted pages, so a draft's slug or a
             * retired one cannot be used to take orders against a page no
             * visitor can see.
             */
            'page' => [
                'required',
                'string',
                Rule::exists('pages', 'slug')
                    ->where('status', 'published')
                    ->whereNull('deleted_at'),
            ],

            'name' => ['required', 'string', 'max:255'],

            'phone' => [
                'required',
                'string',
                'max:32',
                fn (string $attribute, mixed $value, callable $fail) => is_string($value) && PhoneNumber::isValid($value)
                    ? null
                    : $fail('Please enter a valid phone number we can call you on.'),
            ],

            /**
             * All four capture fields are required. An order without an address
             * is not an order, it is a lead — and mixing the two in one table
             * makes every downstream number ambiguous.
             */
            'address' => ['required', 'string', 'max:1000'],

            'district' => ['required', 'string', Rule::in(config('giopages.districts'))],

            'quantity' => [
                'required',
                'integer',
                'min:1',
                'max:'.config('giopages.checkout.max_quantity'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'page.exists' => 'This page is no longer taking orders.',
            'name.required' => 'Please enter your name.',
            'phone.required' => 'Please enter a phone number we can call you on.',
            'address.required' => 'Please enter the address we should deliver to.',
            'district.required' => 'Please choose your district.',
            'district.in' => 'Please choose your district from the list.',
            'quantity.min' => 'Please order at least one.',
            'quantity.max' => 'That is more than we can take in a single order.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('page')) {
                    return;
                }

                /**
                 * The page is published but has nothing unambiguously for sale
                 * — its Product was soft-deleted, or a second one was linked,
                 * after the visitor's copy of the page was rendered. Reported
                 * rather than captured: taking cash for something that cannot
                 * ship is the one outcome worse than a lost order.
                 */
                if ($this->page()?->soleProduct() === null) {
                    $validator->errors()->add('page', 'This page is no longer taking orders.');
                }
            },
        ];
    }

    /**
     * The Page being ordered from, resolved once.
     */
    public function page(): ?Page
    {
        return $this->page ??= Page::query()
            ->published()
            ->where('slug', $this->string('page')->toString())
            ->first();
    }

    /**
     * @return array{name: string, phone: string, address: string, district: string, quantity: int}
     */
    public function details(): array
    {
        return [
            'name' => $this->string('name')->trim()->toString(),
            'phone' => $this->string('phone')->trim()->toString(),
            'address' => $this->string('address')->trim()->toString(),
            'district' => $this->string('district')->toString(),
            'quantity' => $this->integer('quantity'),
        ];
    }
}
