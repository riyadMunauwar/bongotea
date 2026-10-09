<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Models\Product;
use App\Support\Bangla;
use App\Support\Orders\Quote;
use App\Support\PhoneNumber;
use App\Support\Settings\PaymentSettings;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the Checkout: the delivery details, the Cart's lines and the
 * Quote the buyer was shown.
 *
 * Nothing here decides a price. The lines are which Products and how many
 * Kilograms; the Quote is only shape-checked, because comparing it is
 * Capture's job. Every message is the buyer's, so every message is Bangla.
 */
class CheckoutRequest extends FormRequest
{
    /** Whole Kilograms of one tea, as the Cart allows. */
    public const MAX_KILOGRAMS = 999;

    /** Nobody signs in to order; there is no user to authorize. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('phone'))) {
            $this->merge(['phone' => Bangla::latinDigits(trim($this->input('phone')))]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:32', function (string $attribute, mixed $value, Closure $fail): void {
                if (! PhoneNumber::isValid((string) $value)) {
                    $fail('সঠিক ১১ সংখ্যার মোবাইল নম্বর দিন।');
                }
            }],
            'district' => ['required', 'string', Rule::in(config('giopages.districts'))],
            'address' => ['required', 'string', 'max:1000'],
            'shop_name' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],

            /** Asked only where there is a choice; a single method is simply the one used. */
            'payment_method' => $this->offersAChoice()
                ? ['required', 'string', Rule::in(PaymentMethod::names($this->offeredMethods()))]
                : ['nullable'],

            'lines' => ['required', 'array', 'list'],
            'lines.*.productId' => ['required', 'integer', 'distinct', Rule::exists(Product::class, 'id')],
            'lines.*.kilograms' => ['required', 'integer', 'min:1', 'max:'.self::MAX_KILOGRAMS],

            'quote' => ['required', 'array'],
            'quote.lines' => ['required', 'array', 'list'],
            'quote.lines.*.productId' => ['required', 'integer', 'distinct'],
            'quote.lines.*.tierPrice' => ['required', 'integer', 'min:0'],
            'quote.deliveryCharge' => ['required', 'integer', 'min:0'],
            'quote.total' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'আপনার নাম লিখুন।',
            'phone.required' => 'মোবাইল নম্বর লিখুন।',
            'district.required' => 'জেলা বেছে নিন।',
            'district.in' => 'তালিকা থেকে জেলা বেছে নিন।',
            'address.required' => 'ঠিকানা লিখুন।',
            'payment_method.required' => 'কীভাবে টাকা দেবেন বেছে নিন।',
            'payment_method.in' => 'তালিকা থেকে টাকা দেওয়ার উপায় বেছে নিন।',
            'lines.required' => 'কার্টে কোনো চা নেই।',
            'lines.*' => 'কার্টের পরিমাণ ঠিক নেই। কার্ট দেখে আবার চেষ্টা করুন।',
            'quote.*' => 'দামের হিসাব পাওয়া যায়নি। পাতাটি আবার খুলুন।',
            'max' => 'লেখাটি অনেক বড়।',
        ];
    }

    /**
     * The Cart's lines, each Product found whether or not a buyer can still
     * have it: whether they can is Capture's to answer, with the figures as
     * they are now.
     *
     * @return list<array{product: Product, kilograms: int}>
     */
    public function cartLines(): array
    {
        $lines = $this->validated('lines');

        $products = Product::withTrashed()
            ->whereKey(array_column($lines, 'productId'))
            ->get()
            ->keyBy('id');

        return array_map(fn (array $line): array => [
            'product' => $products[(int) $line['productId']],
            'kilograms' => (int) $line['kilograms'],
        ], $lines);
    }

    /**
     * @return array{name: string, phone: string, address: string, district: string, shop_name: string|null, note: string|null, payment_method: PaymentMethod}
     */
    public function details(): array
    {
        return [
            'name' => trim($this->validated('name')),
            'phone' => $this->validated('phone'),
            'address' => trim($this->validated('address')),
            'district' => $this->validated('district'),
            'shop_name' => $this->validated('shop_name'),
            'note' => $this->validated('note'),
            'payment_method' => $this->offersAChoice()
                ? PaymentMethod::from($this->validated('payment_method'))
                : $this->offeredMethods()[0],
        ];
    }

    public function quote(): Quote
    {
        return Quote::fromArray($this->validated('quote'));
    }

    private function offersAChoice(): bool
    {
        return count($this->offeredMethods()) > 1;
    }

    /**
     * @return non-empty-list<PaymentMethod>
     */
    private function offeredMethods(): array
    {
        return app(PaymentSettings::class)->offeredMethods();
    }
}
