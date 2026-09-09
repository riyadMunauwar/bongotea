<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Models\Page;
use App\Models\Product;
use App\Support\Checkout\BuiltInField;
use App\Support\Checkout\CheckoutConfig;
use App\Support\Checkout\PricingFingerprint;
use App\Support\Orders\CapturedAnswer;
use App\Support\Settings\GeneralSettings;
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
 * That leaves one thing the payload has to be believed about, which is what the
 * form quoted. It carries a fingerprint of the figures it was drawn from, and a
 * submission whose fingerprint is not the Page's current one is refused outright
 * rather than captured at figures nobody was shown (ADR-0007).
 *
 * The details it asks about are not listed here either. They are compiled from
 * the Checkout Config of the Page being ordered from — the same list that
 * rendered the form — so what the visitor was asked and what the server checks
 * are one definition with two renderings.
 */
class StoreOrderRequest extends FormRequest
{
    /**
     * The one thing a visitor can do about a Page that has moved under them.
     *
     * Worded once and reported in the page-level slot, because none of the ways
     * of getting here is a mistake the visitor made or can put right at a box
     * — the form on their screen is simply out of date (ADR-0007).
     */
    private const PAGE_HAS_CHANGED = 'This page has changed since you opened it. Reload it to see the current prices, then order again.';

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

            ...$this->paymentRules(),

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
     * was never asked to choose — no picker on either side of the wait, or one
     * Product on offer — carries no such rule and ignores whatever a stale
     * form posts.
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
     * What the Config alone can say — the Merchant's switch and whether the
     * Product List is shown, which is `CheckoutConfig::picksProducts()` — and
     * how many Products the submission was offered, which its quantity keys
     * say, because the form posts one per Product it drew a row for, ticked or
     * not. A Checkout with its list hidden draws no rows to tick and posts a
     * quantity per Product all the same, which is why the list has to be read
     * here too: without it, a Merchant hiding the list with the picker left on
     * would have every Order refused for a box no Customer was shown.
     *
     * The Config cannot be the whole of it, because it says what the Page asks
     * now and this form was drawn earlier. A tick list arriving at all is the
     * submission's own word that it drew one, and it is taken: switching the
     * picker off does not reach the copy already on someone's phone, and
     * reading only the Config there would take a Customer's ticks for nothing
     * and sell them everything the Page links — a cash-on-delivery parcel
     * holding items they never ticked, at a total above the summary they read.
     * The fingerprint cannot turn that one away, because no price moved.
     *
     * Both halves, and neither alone. A Customer who ticked nothing posts no
     * `selected` at all — an unticked checkbox is simply absent — so the
     * Config's half is what tells a form that drew a tick list from one that
     * never did, and the payload's half is what tells a form drawn before the
     * Merchant's edit from one drawn after.
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
        return ($this->checkoutConfig()->picksProducts() || $this->has('selected'))
            && count($this->quantities()) > 1;
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
     * How the Customer will pay, where this Page offers a choice of ways.
     *
     * A name and never anything more: what a method does is read off the
     * Page at Capture, so a submission naming a method the Page never listed
     * is refused here rather than placed under whatever it claims. A method
     * the Page lists but cannot offer now is not refused: that is a form that
     * went stale under a Settings change, and it captures Unpaid.
     *
     * Optional where the Page offers Cash on Delivery, because a Page that
     * draws no chooser posts no method and is placed under cash — and so is a
     * plain post that lost the radio, which is where the form would have
     * opened. Required only where no cash fallback exists: a Page listing an
     * online method alone on an installation that has switched cash off.
     *
     * @return array<string, list<mixed>>
     */
    private function paymentRules(): array
    {
        $config = $this->checkoutConfig();

        return [
            'payment_method' => [
                $config->offersPaymentMethod(PaymentMethod::CashOnDelivery) ? 'nullable' : 'required',
                'string',
                Rule::in(PaymentMethod::names($config->acceptedMethods())),
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
     * dropped at Capture. That is not how a Product soft-deleted under a live
     * campaign is handled — such a form quoted a total including it, so the
     * fingerprint turns the submission away in `after()` before any of this
     * decides anything. It is what keeps a hand-made post naming Products from
     * elsewhere from selling them. A Product with no quantity is refused rather
     * than sold at one, because a COD parcel holding an item nobody chose is
     * refused at the door.
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

        $maxQuantity = app(GeneralSettings::class)->checkoutMaxQuantity;

        foreach ($this->soldProducts() as $product) {
            $rules['quantities.'.$product->getKey()] = [
                'required',
                'integer',
                'min:1',
                'max:'.$maxQuantity,
            ];
        }

        return $rules;
    }

    /**
     * What a refusal calls each question this Page asks, where no message of
     * ours words the refusal itself.
     *
     * Every field, not only the Merchant's own — but theirs are why it exists:
     * a built-in's refusals are all worded here already, and a question keyed
     * `gift_wrap` has nothing but the Merchant's own wording to be called by.
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
             * Only a name that is no method at all is refused here — a
             * known method the Page does not offer captures Unpaid, because
             * a form drawn before the Merchant edited the tab looks the
             * same — and it reads as the hand-made post it is.
             */
            'payment_method.required' => 'Please choose how you would like to pay.',
            'payment_method.in' => 'Please choose one of the payment methods listed.',

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
                 * The figures this form quoted are not the figures the Page
                 * would charge now — a Product repriced or gone, or a delivery
                 * amount edited, since these bytes were written to disk.
                 *
                 * First, and ahead of anything the Customer could put right
                 * themselves: an order they can fix at a box is still an order
                 * captured at a price nobody showed them, and telling them to
                 * correct a field would be telling them to finish a form that
                 * is already wrong. Being turned away is the safe direction to
                 * fail in (ADR-0007).
                 */
                if (! PricingFingerprint::matches($page, $this->input('fingerprint'))) {
                    $validator->errors()->add('page', self::PAGE_HAS_CHANGED);

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
                 * What the fingerprint cannot speak for: a submission whose
                 * prices are current but whose parcel is not this Page's, which
                 * is a hand-made post rather than a form that went stale. Kept
                 * because the refusal has to land somewhere a visitor can read
                 * it, and neither has a box of its own to complain about.
                 */
                if ($this->unofferedProducts()->isNotEmpty() || $this->nothingChosenIsOnSale()) {
                    $validator->errors()->add('page', self::PAGE_HAS_CHANGED);
                }
            },
        ];
    }

    /**
     * Whether a submission that chose Products named nothing this Page sells.
     *
     * A hand-made post naming Products from somewhere else, in practice, or one
     * ticking something that is not a Product at all — refused here rather than
     * left to fail as a Capture with nothing to sell. A form whose ticks were
     * soft-deleted under the visitor arrives this shape too, but never reaches
     * this far: losing a Product moves the fingerprint, and `after()` turns that
     * submission away first.
     *
     * Either way an Order with no lines is a courier collecting a delivery
     * charge for an empty parcel.
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
     * Empty for every form rendered from the Page as it stands, and empty for a
     * form written before a Product was linked too — that one is turned away by
     * the fingerprint first, because linking a Product changes what the Page
     * would collect. What is left is a hand-made post that leaves one out.
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
     * @return array{name: string|null, phone: string, email: string|null, address: string|null, district: string|null, answers: list<CapturedAnswer>, delivery_option: string|null, payment_method: PaymentMethod|null, selected: list<int>|null, quantities: array<int, int>}
     */
    public function details(): array
    {
        return [
            ...$this->answersInColumns(),
            'answers' => $this->answersInSnapshot(),
            'delivery_option' => $this->deliveryOption(),
            'payment_method' => $this->paymentMethod(),
            'selected' => $this->selected(),
            'quantities' => $this->quantities(),
        ];
    }

    /**
     * How the Customer said they would pay, resolved against what the Page
     * offers.
     *
     * Read through the Config so that a submission carrying no method — a
     * Page that drew no chooser, or a plain post that lost the radio — lands
     * on Cash on Delivery where the Page offers it, the same place the form
     * would have opened. Null only where the rules above have already refused
     * the submission.
     */
    private function paymentMethod(): ?PaymentMethod
    {
        $name = $this->string('payment_method')->trim()->toString();

        return $this->checkoutConfig()->paymentMethodFor($name === '' ? null : $name);
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
    private function answersInColumns(): array
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
    private function answersInSnapshot(): array
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
