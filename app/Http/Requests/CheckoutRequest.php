<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Models\Pack;
use App\Models\Product;
use App\Support\Bangla;
use App\Support\Orders\Quote;
use App\Support\Orders\RetailQuote;
use App\Support\PhoneNumber;
use App\Support\Settings\PaymentSettings;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the Checkout: the delivery details, the Cart's lines and the
 * Quote the buyer was shown, for whichever Cart it was opened for — the bulk
 * Cart by default, or the packet basket (ADR-0034). A request carrying both
 * kinds of line is refused: an Order is one kind or the other.
 *
 * Nothing here decides a price. The lines are which Products and how many
 * Kilograms; the Quote is only shape-checked, because comparing it is
 * Capture's job. Every message is the buyer's, so every message is Bangla.
 */
class CheckoutRequest extends FormRequest
{
    /** Whole Kilograms of one tea, as the Cart allows. */
    public const MAX_KILOGRAMS = 999;

    /** Packs of one size, as the packet basket allows. */
    public const MAX_PACKS = 99;

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

            'cart' => ['nullable', 'string', Rule::in(['bulk', 'retail'])],

            ...($this->isRetail() ? self::retailRules() : self::bulkRules()),

            'quote' => ['required', 'array'],
            'quote.lines' => ['required', 'array', 'list'],
            'quote.deliveryCharge' => ['required', 'integer', 'min:0'],
            'quote.total' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    private static function bulkRules(): array
    {
        return [
            'packs' => ['prohibited'],
            'lines' => ['required', 'array', 'list'],
            'lines.*.productId' => ['required', 'integer', 'distinct', Rule::exists(Product::class, 'id')],
            'lines.*.kilograms' => ['required', 'integer', 'min:1', 'max:'.self::MAX_KILOGRAMS],

            /** Not checked to exist here: a Pack removed since it was chosen is Capture's to refuse, with the Cart as it is now. */
            'lines.*.packId' => ['nullable', 'integer', 'min:1'],
            'quote.lines.*.productId' => ['required', 'integer', 'distinct'],
            'quote.lines.*.tierPrice' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * A Pack is not checked to exist here: one removed since it was added is
     * Capture's to refuse, with the basket as it is now.
     *
     * @return array<string, list<mixed>>
     */
    private static function retailRules(): array
    {
        return [
            'lines' => ['prohibited'],
            'packs' => ['required', 'array', 'list'],
            'packs.*.packId' => ['required', 'integer', 'min:1', 'distinct'],
            'packs.*.productId' => ['required', 'integer', 'min:1'],
            'packs.*.count' => ['required', 'integer', 'min:1', 'max:'.self::MAX_PACKS],
            'quote.lines.*.packId' => ['required', 'integer', 'distinct'],
            'quote.lines.*.packPrice' => ['required', 'integer', 'min:0'],
        ];
    }

    /** Whether the Checkout was opened for the packet basket rather than the bulk Cart. */
    public function isRetail(): bool
    {
        return $this->input('cart') === 'retail';
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
            'lines.required' => 'পাইকারি কার্টে কোনো চা নেই।',
            'lines.*' => 'পাইকারি কার্টের পরিমাণ ঠিক নেই। কার্ট দেখে আবার চেষ্টা করুন।',
            'packs.required' => 'খুচরা কার্টে কোনো প্যাকেট নেই।',
            'packs.*' => 'খুচরা কার্টের হিসাব ঠিক নেই। কার্ট দেখে আবার চেষ্টা করুন।',
            'lines.prohibited' => 'পাইকারি ও খুচরা একসাথে অর্ডার করা যায় না।',
            'packs.prohibited' => 'পাইকারি ও খুচরা একসাথে অর্ডার করা যায় না।',
            'quote.*' => 'দামের হিসাব পাওয়া যায়নি। পাতাটি আবার খুলুন।',
            'max' => 'লেখাটি অনেক বড়।',
        ];
    }

    /**
     * The Cart's lines, each Product found whether or not a buyer can still
     * have it: whether they can is Capture's to answer, with the figures as
     * they are now. A line naming a Pack carries it, or null where it no
     * longer exists; a line naming none is left to Capture, which supplies it
     * in the Product's default পাইকারি Pack. Only the Product, the Pack and
     * the Kilograms are read: a packet count the browser sends is ignored, and
     * Capture works out its own.
     *
     * @return list<array{product: Product, kilograms: int, pack?: Pack|null}>
     */
    public function cartLines(): array
    {
        $lines = $this->validated('lines');

        $products = Product::withTrashed()
            ->whereKey(array_column($lines, 'productId'))
            ->get()
            ->keyBy('id');

        $packs = Pack::query()
            ->with('product')
            ->whereKey(array_filter(array_column($lines, 'packId')))
            ->get()
            ->keyBy('id');

        return array_map(fn (array $line): array => [
            'product' => $products[(int) $line['productId']],
            'kilograms' => (int) $line['kilograms'],
            ...(isset($line['packId']) ? ['pack' => $packs->get((int) $line['packId'])] : []),
        ], $lines);
    }

    /**
     * The packet basket's lines, each with its Pack where it still exists:
     * whether a buyer can order it is Capture's to answer.
     *
     * @return list<array{packId: int, productId: int, pack: Pack|null, count: int}>
     */
    public function retailLines(): array
    {
        $lines = $this->validated('packs');

        $packs = Pack::query()
            ->with('product')
            ->whereKey(array_column($lines, 'packId'))
            ->get()
            ->keyBy('id');

        return array_map(fn (array $line): array => [
            'packId' => (int) $line['packId'],
            'productId' => (int) $line['productId'],
            'pack' => $packs->get((int) $line['packId']),
            'count' => (int) $line['count'],
        ], $lines);
    }

    public function retailQuote(): RetailQuote
    {
        return RetailQuote::fromArray($this->validated('quote'));
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
