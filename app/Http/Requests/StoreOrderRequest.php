<?php

namespace App\Http\Requests;

use App\Models\Page;
use App\Support\Checkout\CheckoutConfig;
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
 *
 * The details it asks about are not listed here either. They are compiled from
 * the Checkout Config of the Page being ordered from — the same list that
 * rendered the form — so what the visitor was asked and what the server checks
 * are one definition with two renderings.
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

            /** Whatever this Page's Checkout asks for, checked as it asks it. */
            ...$this->checkoutConfig()->rules(),

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
            ...$this->checkoutConfig()->messages(),
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
     * The Checkout the visitor filled in — or the default one when the payload
     * names no Page this installation can serve.
     *
     * That fallback never decides anything: such a request is refused on `page`
     * regardless. It keeps the rules one shape rather than branching on a Page
     * that is already on its way to being reported missing.
     */
    private function checkoutConfig(): CheckoutConfig
    {
        return $this->page()?->checkout_config ?? CheckoutConfig::default();
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
     * The answers, in the shape an Order is written from.
     *
     * Still the four built-ins rather than whatever the Config asked for: the
     * columns behind them are non-nullable, so a Page that stops asking for an
     * address has nowhere to put the absence yet. Widening these columns and
     * capturing a null instead of a blank is what makes a field genuinely
     * optional, and it lands with the setting that lets a Merchant switch one
     * off.
     *
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
