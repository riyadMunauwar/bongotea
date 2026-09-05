<?php

namespace App\Http\Requests;

use App\Models\Page;
use App\Models\Product;
use App\Support\Checkout\BuiltInField;
use App\Support\Checkout\CheckoutConfig;
use App\Support\Orders\CapturedAnswer;
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

            ...$this->selectionRules(),

            ...$this->quantityRules(),
        ];
    }

    /**
     * Which Products the Customer chose, where this Page asks them to choose.
     *
     * Names and never prices, like the delivery arrangement: what arrives is a
     * list of Product ids and everything about them is read off the Page. A
     * name for something this Page does not sell carries no rule and sells
     * nothing, which is the same treatment a quantity for a departed Product
     * gets — the Page is what decides, not the payload.
     *
     * Required, because an Order for nothing is not an Order. A submission that
     * was never asked to choose — the picker switched off, or one Product on
     * offer — carries no such rule and ignores whatever a stale form posts.
     *
     * @return array<string, list<mixed>>
     */
    private function selectionRules(): array
    {
        if (! $this->wasAskedToChoose()) {
            return [];
        }

        return ['selected' => ['required', 'array']];
    }

    /**
     * Whether the form this submission came from asked the Customer which
     * Products to buy.
     *
     * The Merchant's switch, and how many Products the submission was offered —
     * which its quantity keys say, because the form posts one per Product it
     * drew a row for, ticked or not.
     *
     * Counted from the submission rather than from the Page for the same reason
     * the quantities are keyed by id: the Page may have changed since these
     * bytes were written. A Page whose second Product was deleted mid-form
     * still asked this Customer to choose, and selling them whatever is left
     * because it is now the only one would put an item they never ticked into a
     * cash-on-delivery parcel.
     */
    private function wasAskedToChoose(): bool
    {
        return $this->checkoutConfig()->productPicker && count($this->quantities()) > 1;
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
     * One quantity per Product this submission would sell, keyed by Product id.
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
     * Only what was ticked, where the Page asks the Customer to tick. The
     * stepper beside an unticked Product is hidden rather than removed, so its
     * box still posts a figure that decides nothing — checking it would refuse
     * an Order over a box the Customer never opened.
     *
     * @return array<string, list<string>>
     */
    private function quantityRules(): array
    {
        $rules = ['quantities' => ['required', 'array']];

        foreach ($this->soldProducts() as $product) {
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
     * What a refusal calls a question this Page's Merchant worded themselves,
     * where no message of ours words the refusal itself.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->checkoutConfig()->validationAttributes();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $nothingChosen = 'Please choose at least one item to order.';

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
             * Nothing ticked, no tick list at all, and a tick list that is not
             * one are a single refusal in a single wording: an unticked
             * checkbox is simply absent from a form post, so a Customer who
             * chose nothing and a payload that says nothing arrive the same way
             * and mean the same thing.
             */
            'selected.required' => $nothingChosen,
            'selected.array' => $nothingChosen,

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
                 * Already refused for having chosen nothing, which is the
                 * Customer's to fix at the boxes. What follows is about the
                 * Page having moved under them, and saying both would tell them
                 * to reload a form they can still put right themselves.
                 */
                if ($validator->errors()->has('selected')) {
                    return;
                }

                /**
                 * Two ways for a form to have gone out of date under a visitor,
                 * and one thing either of them can be told to do about it.
                 * ADR-0007 keeps a refusal nobody can edit their way out of in
                 * the page-level slot, ahead of the field error that has
                 * nowhere to land.
                 */
                if ($this->unofferedProducts()->isNotEmpty() || $this->nothingChosenIsOnSale()) {
                    $validator->errors()->add('page', 'This page has changed since you opened it. Please reload and try again.');
                }
            },
        ];
    }

    /**
     * Whether a submission that chose Products named nothing this Page sells.
     *
     * Every one of them soft-deleted since the form was written, in practice.
     * The Page is not off the air — it still sells whatever is left — but
     * nothing the Customer agreed to buy survives, and an Order with no lines
     * is a courier collecting a delivery charge for an empty parcel.
     *
     * It is also the shape of a hand-made post naming Products from somewhere
     * else, which is refused here rather than left to fail as a Capture with
     * nothing to sell.
     *
     * Having chosen nothing at all is a different thing, refused on `selected`
     * where the boxes are; this one has no box to hang on.
     */
    private function nothingChosenIsOnSale(): bool
    {
        return $this->wasAskedToChoose() && $this->soldProducts()->isEmpty();
    }

    /**
     * The Products this submission would sell: what the Customer ticked, or
     * everything the Page sells where they were never asked to tick anything.
     *
     * Asked of the Page, which is the same place the Capture asks, so the rules
     * cannot check one parcel and the Order be written for another.
     *
     * @return Collection<int, Product>
     */
    private function soldProducts(): Collection
    {
        return $this->page()?->productsChosen($this->selected()) ?? new Collection;
    }

    /**
     * The Product ids the Customer ticked, as they arrived.
     *
     * Ids and nothing else: anything a hand-made post puts here that could not
     * be one is dropped rather than turned into a zero that names no Product.
     *
     * Empty where they ticked nothing, which is the same payload a form that
     * never drew a checkbox sends — `wasAskedToChoose()` is what tells the two
     * apart.
     *
     * @return list<int>
     */
    private function selection(): array
    {
        $ticked = array_filter(
            (array) $this->input('selected', []),
            fn (mixed $id): bool => is_scalar($id) && ctype_digit((string) $id),
        );

        return array_values(array_map(intval(...), $ticked));
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
     * @return array{name: string|null, phone: string, email: string|null, address: string|null, district: string|null, answers: list<CapturedAnswer>, delivery_option: string|null, selected: list<int>|null, quantities: array<int, int>}
     */
    public function details(): array
    {
        return [
            ...$this->answers(),
            'answers' => $this->capturedAnswers(),
            'delivery_option' => $this->deliveryOption(),
            'selected' => $this->selected(),
            'quantities' => $this->quantities(),
        ];
    }

    /**
     * Which Products the Customer chose, where they were asked to choose.
     *
     * Null everywhere else — a Page selling one Product, or selling several
     * without a picker, sells all of them — and null rather than whatever a
     * stale form posted, for the same reason the delivery arrangement is: a
     * choice that was never offered decides nothing, and carrying it forward
     * would invite a reader to think it might.
     *
     * @return list<int>|null
     */
    private function selected(): ?array
    {
        return $this->wasAskedToChoose() ? $this->selection() : null;
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
     * What the visitor typed into the questions this Page actually asked that
     * an Order has a column for.
     *
     * A question the Merchant added of their own is not one of them; its answer
     * goes to the snapshot instead, which is the one property that differs
     * between the two kinds of field (ADR-0006).
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
            if ($field->snapshotsAnswer()) {
                continue;
            }

            $answers[$field->key()] = $field->answerFrom($this->input($field->key()));
        }

        return $answers;
    }

    /**
     * The answers to the Merchant's own questions, in the order they were
     * asked, each carrying the question with it.
     *
     * Every question this Checkout asked is recorded, including one the visitor
     * left blank: an Order that says "we asked and they said nothing" is worth
     * telling from one that never asked, and only the first is worth a call.
     *
     * Compiled from the Config that rendered the form and checked the answers,
     * so nothing a stale form or a hand-made post volunteers for a question
     * this Page has stopped asking can reach an Order.
     *
     * @return list<CapturedAnswer>
     */
    private function capturedAnswers(): array
    {
        $answers = [];

        foreach ($this->checkoutConfig()->enabledFields() as $field) {
            if (! $field->snapshotsAnswer()) {
                continue;
            }

            $answers[] = $field->capture($field->answerFrom($this->input($field->key())));
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
