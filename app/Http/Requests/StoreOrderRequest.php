<?php

namespace App\Http\Requests;

use App\Models\Page;
use App\Models\Product;
use App\Support\Checkout\BuiltInField;
use App\Support\Checkout\CheckoutConfig;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a Capture.
 *
 * The visitor submits from a page that may have been cached on disk for days,
 * so everything the request asserts about that page is re-checked here against
 * the database rather than trusted from the payload. Nothing priced is
 * accepted at all: the request carries a slug, a quantity per item and the name
 * of a delivery arrangement, and every figure comes from the Page's own
 * Products and Delivery Options.
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

            ...$this->deliveryRules(),

            ...$this->quantityRules(),
        ];
    }

    /**
     * The delivery arrangement, where this Page offers a choice of them.
     *
     * A name and never an amount. What it costs is read off the Page at
     * Capture, so a submission naming an arrangement it was never offered is
     * refused here rather than priced at whatever it claims.
     *
     * No rule at all where there is nothing to choose between: a Page with one
     * arrangement charges it and a Page with none charges nothing, and in both
     * cases whatever a stale form posts is ignored rather than checked.
     *
     * @return array<string, list<mixed>>
     */
    private function deliveryRules(): array
    {
        if (! $this->checkoutConfig()->offersDeliveryChoice()) {
            return [];
        }

        return [
            'delivery_option' => [
                'required',
                'string',
                Rule::in($this->checkoutConfig()->deliveryLabels()),
            ],
        ];
    }

    /**
     * One quantity per Product the Page still sells, keyed by Product id.
     *
     * Keyed rather than positional so a reorder in the admin can never point a
     * form already on someone's phone at a different item.
     *
     * A key naming a Product this Page no longer sells carries no rule and is
     * dropped at Capture — that is how a Product soft-deleted under a live
     * campaign leaves a form written to disk before it went, while the rest
     * keep selling. A Product with no quantity is refused rather than sold at
     * one, because a COD parcel holding an item nobody chose is refused at the
     * door; `after()` is what tells the visitor to reload, since their form has
     * no box for that Product to complain about.
     *
     * @return array<string, list<string>>
     */
    private function quantityRules(): array
    {
        $rules = ['quantities' => ['required', 'array']];

        foreach ($this->page()?->products ?? [] as $product) {
            $rules['quantities.'.$product->getKey()] = [
                'required',
                'integer',
                'min:1',
                'max:'.config('giopages.checkout.max_quantity'),
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'page.exists' => 'This page is no longer taking orders.',
            ...$this->checkoutConfig()->messages(),

            /**
             * The second reads as a stale form rather than as a mistake,
             * because that is what it is: nothing on screen offers an
             * arrangement the Page does not.
             */
            'delivery_option.required' => 'Please choose how you would like it delivered.',
            'delivery_option.in' => 'Please choose one of the delivery options listed.',

            /**
             * Wildcards rather than a message per Product: the keys are ids,
             * so there is nothing to write them out against, and every line is
             * refused for the same reasons in the same words.
             */
            'quantities.required' => 'Please choose how many of each item you want.',
            'quantities.*.required' => 'Please order at least one.',
            'quantities.*.min' => 'Please order at least one.',
            'quantities.*.max' => 'That is more than we can take in a single order.',
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
                 * The page is published but has nothing left to sell — every
                 * Product it links was soft-deleted after the visitor's copy of
                 * the page was rendered. Losing one of several is not this case:
                 * those Products simply carry no rules and no lines. Reported
                 * rather than captured: taking cash for something that cannot
                 * ship is the one outcome worse than a lost order.
                 */
                $page = $this->page();

                if ($page === null || $page->products->isEmpty()) {
                    $validator->errors()->add('page', 'This page is no longer taking orders.');

                    return;
                }

                /**
                 * The Page has gained a Product since the visitor's copy was
                 * written to disk, so their form has no box for it. The rules
                 * already refuse the Capture — nothing unagreed is ever sold —
                 * but that refusal is keyed to a box they cannot see, and a
                 * visitor told to "order at least one" of an item that is not
                 * on their screen is being asked to fix something they cannot
                 * reach.
                 *
                 * So it is reported as what it is: the page changed underneath
                 * them, and reloading is the fix. ADR-0007 keeps this in the
                 * page-level slot, ahead of the field error that has nowhere to
                 * land.
                 */
                if ($this->unofferedProducts()->isNotEmpty()) {
                    $validator->errors()->add('page', 'This page has changed since you opened it. Please reload and try again.');
                }
            },
        ];
    }

    /**
     * The Products this Page sells that the submission never mentioned.
     *
     * Empty for every form rendered from the Page as it stands. A Product
     * appears here only when it was linked after the visitor's copy of the page
     * was written to disk.
     *
     * @return Collection<int, Product>
     */
    private function unofferedProducts(): Collection
    {
        $offered = array_map(strval(...), array_keys($this->quantities()));

        return $this->page()?->products
            ->reject(fn (Product $product): bool => in_array((string) $product->getKey(), $offered, true))
            ->values()
            ?? new Collection;
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
     * One key per built-in, whether or not this Page asked for it, so Capture
     * writes the same shape for every Page. What differs is the value: a
     * question this Checkout does not ask is null, and so is one it asked and
     * the visitor left blank.
     *
     * Null rather than a blank throughout, because the two say different things
     * to whoever reads the Order afterwards — "we never asked" against "they
     * had nothing to say" — and only the first is worth telling apart. Anything
     * posted for a field that is switched off is dropped here rather than
     * stored: it was never asked for and never checked.
     *
     * @return array{name: string|null, phone: string, email: string|null, address: string|null, district: string|null, delivery_option: string|null, quantities: array<int, int>}
     */
    public function details(): array
    {
        return [
            ...$this->answers(),
            'delivery_option' => $this->deliveryOption(),
            'quantities' => $this->quantities(),
        ];
    }

    /**
     * The delivery arrangement the visitor named, where they were given a
     * choice of them.
     *
     * Null everywhere else, and null rather than whatever a stale form posted:
     * a Page offering one arrangement charges that one, so a name in the
     * payload would decide nothing and carrying it forward would only invite a
     * reader to think it might.
     */
    private function deliveryOption(): ?string
    {
        if (! $this->checkoutConfig()->offersDeliveryChoice()) {
            return null;
        }

        return $this->string('delivery_option')->trim()->toString();
    }

    /**
     * What the visitor typed into the questions this Page actually asked.
     *
     * @return array<string, string|null>
     */
    private function answers(): array
    {
        $answers = [];

        foreach (BuiltInField::cases() as $builtIn) {
            $answers[$builtIn->value] = null;
        }

        foreach ($this->checkoutConfig()->enabledFields() as $field) {
            $answer = $this->string($field->key())->trim()->toString();

            $answers[$field->key()] = $answer === '' ? null : $answer;
        }

        return $answers;
    }

    /**
     * How many of each Product, keyed by Product id.
     *
     * Cast on the way through because a form post arrives as strings and
     * Capture writes these straight onto an Order Line. A key for a Product the
     * Page no longer sells survives this and is dropped there.
     *
     * @return array<int, int>
     */
    private function quantities(): array
    {
        $quantities = [];

        foreach ((array) $this->input('quantities', []) as $productId => $quantity) {
            $quantities[(int) $productId] = (int) $quantity;
        }

        return $quantities;
    }
}
