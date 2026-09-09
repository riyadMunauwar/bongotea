<?php

namespace App\Support\Checkout;

use App\Enums\PaymentMethod;
use App\Support\Money;
use App\Support\Settings\GeneralSettings;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;

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
     * The name a Config stored the retired form-only layout under.
     *
     * Read in exactly one place, `showProductsFromArray()`, and private so it
     * stays that way: the name is not a layout any more, and a second reader
     * would be a second place for the upgrade to go wrong.
     */
    private const RETIRED_FORM_ONLY_LAYOUT = 'form_only';

    /**
     * @param  list<CheckoutField>  $fields
     * @param  list<DeliveryOption>  $deliveryOptions
     */
    private function __construct(
        public readonly array $fields,
        public readonly array $deliveryOptions,

        /**
         * How this Checkout looks, as far as the Merchant has said anything
         * about it. Sparse: what they left alone is filled in from the token
         * set's own defaults at the point of emission, not stored here.
         */
        public readonly StyleTokens $styleTokens,

        /**
         * Whether the Checkout draws the Product List: the Page's Products
         * with their pictures, quantities and line totals.
         *
         * Off for a Page whose own markup has already sold the Product above
         * the fold, on any layout. A Checkout with the list hidden still sells
         * everything the Page sells, one of each, and captures the same
         * complete Order; what it cannot do is offer a Product Picker, because
         * there is nothing drawn to pick between — which is why `picksProducts()`
         * reads this before the switch below it.
         */
        public readonly bool $showProducts,

        /**
         * Whether the Customer chooses which of this Page's Products to buy.
         *
         * The Merchant's switch and nothing more. What a Checkout actually
         * asks needs the Product List to be shown as well, which is
         * `picksProducts()`, and then how many Products the Page still sells,
         * which is Page's to answer — see `Page::offersProductChoice()`.
         */
        public readonly bool $productPicker,

        /**
         * Which of this Page's Products the Picker opens ticked, by id.
         *
         * The Merchant's list and nothing more, kept whole even where it
         * cannot be honoured — like the switch above it, so a Merchant hiding
         * the Product List gets their ticks back when they show it again.
         * What a form actually opens ticked needs the list to be shown as
         * well, which is `opensTicked()`, and then the Products the Page still
         * sells, which is Page's to answer — see `Page::defaultSelection()`.
         *
         * @var list<int>
         */
        public readonly array $defaultSelection,

        /**
         * How the Checkout is arranged.
         *
         * A rendering decision only: it changes what the island draws and
         * nothing about what it asks for, what it charges or how it is styled.
         * Nor whether the Product List is drawn — that is `showProducts`, and
         * it works on every arrangement alike.
         */
        public readonly CheckoutLayout $layout,

        /**
         * Everything the Checkout says: the order button and the line
         * beneath it, the totals labels, and what each Payment Method is
         * called.
         *
         * Copy, not a figure: like the layout and the Default Selection it
         * stays out of the Pricing Fingerprint, so a Merchant editing a word
         * on a live Page never turns away an Order somebody is half-way
         * through typing.
         */
        public readonly CheckoutWords $words,

        /**
         * Which Payment Methods this Page offers, by name and in the order
         * the Checkout draws them.
         *
         * The Merchant's list and nothing more, kept whole even where a
         * method in it cannot be offered — switched off for the installation,
         * or not yet usable at all — so a method switched back on reappears
         * where the Merchant put it. What a Checkout actually offers is
         * `offeredMethods()`, which is the only reading anything draws or
         * validates from.
         *
         * @var list<PaymentMethod>
         */
        public readonly array $paymentMethods,

        /** Whether the chooser draws each method's logo beside its name. */
        public readonly bool $showPaymentLogos,

        /**
         * Whether a Page offering nothing but Cash on Delivery draws a chooser
         * at all. On, a cash-only Page draws the form it always did and the
         * submission carries no method; off, it draws a single row so the
         * Customer sees how they will pay.
         */
        public readonly bool $hideLoneCashChoice,
    ) {}

    /**
     * The Checkout a Page starts with, and the one every existing Page was
     * backfilled to: the four details the form has always asked for, in the
     * order it has always asked them, every one of them on and required, and a
     * single Delivery Option carrying the installation's charge.
     *
     * Email is in the list too and switched off, so a new Page holds a complete
     * Config while rendering the form a Merchant already knows.
     */
    public static function default(): self
    {
        return new self(
            array_map(BuiltInCheckoutField::default(...), BuiltInField::cases()),
            [self::seededDeliveryOption()],

            /**
             * Nothing said about the appearance, which is the Checkout as it
             * looked before a Merchant could restyle it: every token at the
             * value its rule already carried.
             */
            StyleTokens::none(),

            /**
             * Shown, which is the Checkout as it has always drawn: a Page
             * whose own markup sells the Product is the exception, and hiding
             * the list is a decision a Merchant makes about one Page.
             */
            showProducts: true,

            /**
             * Off, so a Page sells everything it links the way it always has.
             * A picker is a decision a Merchant makes about one campaign, and
             * switching it on for every Page at once would put an unticked box
             * between paid traffic and an order nobody asked us to change.
             */
            productPicker: false,

            /**
             * Nothing ticked, which is what a Picker opened with before a
             * Merchant could say otherwise. A tick is a price a Customer meets
             * before they have chosen anything, and on a cash-on-delivery Page
             * that is a call about their own Products no default of ours is in
             * a position to make for them (ADR-0010).
             */
            defaultSelection: [],

            /**
             * The arrangement the Checkout has always had, so a Merchant who
             * never opens the Checkout tab sees the form they already know.
             */
            layout: CheckoutLayout::Stacked,

            /** The design's words, Bangla first and English second. */
            words: CheckoutWords::default(),

            /**
             * Cash on Delivery alone, with the choice hidden: every Page that
             * exists today is paid at the door, and a chooser with one row
             * would put a decision nobody can make in front of paid traffic.
             * Logos shown, so a Page that does start offering a second method
             * looks right with no upload from the Merchant.
             */
            paymentMethods: [PaymentMethod::CashOnDelivery],
            showPaymentLogos: true,
            hideLoneCashChoice: true,
        );
    }

    /**
     * The one Delivery Option a Page is born with.
     *
     * One option is exactly today's behaviour — an amount added to the total
     * with no question asked — so a Merchant who never opens the Checkout tab
     * sees no change (ADR-0008). The figure is a General Setting because that
     * is where the single global charge lives; from here on it is a starting
     * point rather than the answer, and a Page that edits it is unaffected by
     * anyone changing it.
     */
    private static function seededDeliveryOption(): DeliveryOption
    {
        return new DeliveryOption('Delivery', Money::toMinorUnits(app(GeneralSettings::class)->deliveryCharge));
    }

    /**
     * Rebuild a Config from its stored shape.
     *
     * Total on purpose: an unusable field is dropped, a built-in the stored
     * shape never mentioned is appended with its defaults, and something
     * unreadable yields the default Config. A Checkout asking one question more
     * than a Merchant meant is fixed at their desk; a Checkout that throws is a
     * landing page answering paid traffic with a 500.
     *
     * The stored order is the order asked in, whichever kind of field holds a
     * position: what makes a Merchant's own question sit between phone and
     * address is that it was written between them, and nothing here sorts the
     * built-ins back to the front. Only a built-in the stored shape is missing
     * is appended, because it has to exist somewhere and the end is the one
     * place that moves nothing else.
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

            /** A key twice over would ask twice and store once. */
            if (! is_string($key) || isset($fields[$key])) {
                continue;
            }

            $builtIn = BuiltInField::tryFrom($key);

            $field = $builtIn !== null
                ? BuiltInCheckoutField::fromArray($builtIn, $field)
                : CustomCheckoutField::fromArray($key, $field);

            if ($field !== null) {
                $fields[$key] = $field;
            }
        }

        foreach (BuiltInField::cases() as $builtIn) {
            $fields[$builtIn->value] ??= BuiltInCheckoutField::default($builtIn);
        }

        return new self(
            array_values($fields),
            self::deliveryOptionsFromArray($stored),
            StyleTokens::fromArray((array) ($stored['style_tokens'] ?? [])),

            showProducts: self::showProductsFromArray($stored),

            /**
             * Absent means off, which is what every Config written before the
             * picker existed holds and what those Pages have always done: sell
             * everything they link.
             */
            productPicker: (bool) ($stored['product_picker'] ?? false),

            /** Absent means nothing ticked, which is what every Config written before a Merchant could pre-tick holds. */
            defaultSelection: self::defaultSelectionFromArray($stored),

            /** Absent, or a name nothing draws, is the arrangement it has always had. */
            layout: CheckoutLayout::fromStored($stored['layout'] ?? null),

            /** Absent, blank, or something the tab would refuse, is the design's words. */
            words: CheckoutWords::fromArray(self::wordsFromArray($stored)),

            /**
             * Absent is Cash on Delivery alone, with the choice hidden and
             * logos shown, which is what every Config written before a Page
             * could choose a method holds and what those Pages have always
             * drawn. No data migration: the defaults are read, not written.
             */
            paymentMethods: array_key_exists('payment_methods', $stored)
                ? self::paymentMethodsFromArray($stored['payment_methods'])
                : [PaymentMethod::CashOnDelivery],
            showPaymentLogos: (bool) ($stored['show_payment_logos'] ?? true),
            hideLoneCashChoice: (bool) ($stored['hide_lone_cash_choice'] ?? true),
        );
    }

    /**
     * The Payment Methods of a stored Config, by name and in stored order.
     *
     * Names only, one of each: a name nothing knows is dropped rather than
     * thrown, like every other unusable value a Config holds, and a name twice
     * over would draw a choice that decides nothing. Nothing here asks whether
     * a method can be offered — that is `offeredMethods()`, and the raw list is
     * kept whole so a method switched back on reappears where it was.
     *
     * Empty is kept empty rather than filled in: absent and empty say different
     * things, as they do for Delivery Options, and it is the offered reading
     * that falls back so a Page always has one way to order.
     *
     * @return list<PaymentMethod>
     */
    private static function paymentMethodsFromArray(mixed $stored): array
    {
        $methods = [];

        foreach ((array) $stored as $name) {
            $method = PaymentMethod::fromStored($name);

            if ($method !== null && ! in_array($method, $methods, true)) {
                $methods[] = $method;
            }
        }

        return $methods;
    }

    /**
     * The stored shape of a Config's words, with the order button read from
     * where it was stored before the words existed.
     *
     * The button's wording and note once sat at the top level under
     * `order_button`; they live under `words` now, beside the rest of what
     * the Checkout says. A Config written before the move is read from the
     * old key, and the first save from the tab writes the new shape. No data
     * migration: the reading is what carries it, as it does for the retired
     * form-only layout. Where a stored shape somehow holds both, the words
     * object wins, because it is the shape the tab writes.
     *
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    private static function wordsFromArray(array $stored): array
    {
        $words = (array) ($stored['words'] ?? []);

        if (! array_key_exists('order_button', $words) && array_key_exists('order_button', $stored)) {
            $words['order_button'] = $stored['order_button'];
        }

        return $words;
    }

    /**
     * Whether a stored Config draws the Product List.
     *
     * Absent means shown, which is what every Config written before the switch
     * existed holds and what those Pages have always drawn — with one
     * exception. A Config stored under the retired form-only layout hid the
     * list by its layout's name, and is read as exactly that: the arrangement
     * falls back to stacked as any unknown name does, and the list is hidden
     * whatever else the stored shape says, because a published Page that
     * suddenly drew a product panel would be the upgrade changing something
     * nobody asked it to. No data migration: the reading is what carries it,
     * and the first save from the tab writes the new shape.
     *
     * Anything that is not a boolean is dropped for the default, like every
     * other unusable value a Config holds.
     *
     * @param  array<string, mixed>  $stored
     */
    private static function showProductsFromArray(array $stored): bool
    {
        if (($stored['layout'] ?? null) === self::RETIRED_FORM_ONLY_LAYOUT) {
            return false;
        }

        $shown = $stored['show_products'] ?? true;

        return is_bool($shown) ? $shown : true;
    }

    /**
     * The Default Selection of a stored Config, as Product ids.
     *
     * Ids only, one tick each, in the order they were stored. Nothing here
     * asks whether the Page still sells any of them: a Config knows what a
     * Merchant ticked and not what is on the shelf, so a tick naming something
     * gone is dropped where the Page is known rather than thrown away here,
     * which would lose it on any save made while the Product sat in the bin.
     *
     * @param  array<string, mixed>  $stored
     * @return list<int>
     */
    private static function defaultSelectionFromArray(array $stored): array
    {
        $selection = [];

        foreach ((array) ($stored['default_selection'] ?? []) as $id) {
            /** A key arrives as a string from a form and as an int from the database; both name the same Product. */
            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                continue;
            }

            $id = (int) $id;

            /** A tick twice over ticks one box. */
            if ($id > 0 && ! in_array($id, $selection, true)) {
                $selection[] = $id;
            }
        }

        return $selection;
    }

    /**
     * The Delivery Options of a stored Config.
     *
     * Absent and empty say different things, so they are told apart. A Config
     * written before a Page could price its own delivery has no such key and
     * keeps charging what it always charged; a Merchant who deleted every row
     * on the Checkout tab means a Page that charges nothing for delivery, and
     * an empty list is how they say so.
     *
     * @param  array<string, mixed>  $stored
     * @return list<DeliveryOption>
     */
    private static function deliveryOptionsFromArray(array $stored): array
    {
        if (! array_key_exists('delivery_options', $stored)) {
            return [self::seededDeliveryOption()];
        }

        $options = [];

        foreach ((array) $stored['delivery_options'] as $option) {
            if (! is_array($option)) {
                continue;
            }

            $option = DeliveryOption::fromArray($option);

            /** A label twice over would offer a choice that decides nothing. */
            if ($option === null || isset($options[$option->label])) {
                continue;
            }

            $options[$option->label] = $option;
        }

        return array_values($options);
    }

    /**
     * The fields a visitor is actually asked, in order.
     *
     * The one list both renderings are built from, so a question the Merchant
     * switched off is gone from the form and gone from the rules together. A
     * field that is off still exists — it keeps its wording and its rules in
     * `fields` for the day it is switched back on — it is simply not asked.
     *
     * @return list<CheckoutField>
     */
    public function enabledFields(): array
    {
        return array_values(array_filter($this->fields, fn (CheckoutField $field): bool => $field->enabled));
    }

    /**
     * Whether this Checkout asks the Customer which Products to buy, as far as
     * the Config alone can say.
     *
     * The Merchant's two switches together. A Checkout with its Product List
     * hidden draws no Products, so it has nothing to put a tick box beside —
     * and a Config that let the picker stand regardless would compile a rule
     * demanding a choice the form never offered, which is every Order on a live
     * Page refused for a box no Customer can find. The picker is kept as set,
     * so showing the list again restores the Checkout the Merchant configured.
     *
     * How many Products the Page still sells is the other half, and it is not
     * the Config's: see `Page::offersProductChoice()` for the rendering side
     * and `StoreOrderRequest::wasAskedToChoose()` for the Capture's.
     */
    public function picksProducts(): bool
    {
        return $this->showProducts && $this->productPicker;
    }

    /**
     * Which Products the Picker opens ticked, as far as the Config alone can
     * say.
     *
     * The same gate the Picker itself goes through, and for the same reason: a
     * Product ticked by default behind a hidden Product List is a tick nobody
     * can see and nobody can untick, under a form that never asked. Dropped
     * rather than enforced, like every other value a Config holds and cannot
     * use.
     *
     * Which of these the Page still sells is the other half, and it is not the
     * Config's — see `Page::defaultSelection()`.
     *
     * @return list<int>
     */
    public function opensTicked(): array
    {
        return $this->picksProducts() ? $this->defaultSelection : [];
    }

    /**
     * Whether this Page delivers at a price at all.
     *
     * False only where the Merchant offers no arrangement whatsoever, which is
     * them saying there is nothing to charge for. An arrangement that happens
     * to cost nothing is not this: it is an option priced at zero, agreed to
     * like any other and recorded on the Order by name.
     */
    public function offersDelivery(): bool
    {
        return $this->deliveryOptions !== [];
    }

    /**
     * Whether this Page asks the Customer how they want it delivered.
     *
     * Only a Page offering more than one arrangement has anything to ask. None
     * charges nothing and one is added straight to the total, and in neither
     * case is there a question worth putting in front of a Customer whose only
     * possible answer is the one they would be given.
     */
    public function offersDeliveryChoice(): bool
    {
        return count($this->deliveryOptions) > 1;
    }

    /**
     * The arrangements a choice is made between, by name.
     *
     * @return list<string>
     */
    public function deliveryLabels(): array
    {
        return array_map(fn (DeliveryOption $option): string => $option->label, $this->deliveryOptions);
    }

    /**
     * The Delivery Option a Capture is charged for, given what the Customer
     * chose — or nothing, where this Page charges for no delivery at all.
     *
     * This is the whole of "the delivery charge is derived from the Page and
     * never accepted from the request": what arrives is a name, and the amount
     * against it is read here. A Page offering one arrangement charges it
     * whatever the submission says, because there was no choice to make and a
     * form claiming otherwise is a form nobody was shown.
     *
     * Null where two or more are offered and the name matches none of them.
     * The request is refused before it reaches a Capture, so that is the shape
     * of a Merchant renaming an option under a visitor mid-form — a refusal,
     * never a Customer charged for an arrangement they never picked.
     */
    public function deliveryFor(?string $label): ?DeliveryOption
    {
        if (! $this->offersDelivery()) {
            return null;
        }

        if (! $this->offersDeliveryChoice()) {
            return $this->deliveryOptions[0];
        }

        foreach ($this->deliveryOptions as $option) {
            if ($option->label === $label) {
                return $option;
            }
        }

        return null;
    }

    /**
     * The Payment Methods this Checkout actually offers, in the Page's order.
     *
     * The Merchant's list filtered to what the installation can take money
     * through, falling back to Cash on Delivery when nothing in it can be —
     * a Settings change, or a list a Merchant emptied, must never leave a live
     * Page with no way to order. Everything that draws or validates a method
     * reads this and never the raw list, so the form and the Capture cannot
     * disagree about what was on offer.
     *
     * @return list<PaymentMethod>
     */
    public function offeredMethods(): array
    {
        $offered = array_values(array_filter(
            $this->paymentMethods,
            fn (PaymentMethod $method): bool => $method->isUsable(),
        ));

        return $offered === [] ? [PaymentMethod::CashOnDelivery] : $offered;
    }

    /**
     * Whether this Checkout offers a way to pay by this name.
     */
    public function offersPaymentMethod(PaymentMethod $method): bool
    {
        return in_array($method, $this->offeredMethods(), true);
    }

    /**
     * What the island draws to ask how the Customer will pay — or nothing,
     * where the only method offered is Cash on Delivery and the Page hides
     * that choice.
     */
    public function paymentChooser(): PaymentChooser
    {
        return PaymentChooser::for($this->offeredMethods(), $this->showPaymentLogos, $this->hideLoneCashChoice);
    }

    /**
     * The Payment Methods a Capture may name: every method the system knows,
     * offered by this Page now or not.
     *
     * Wider than `offeredMethods()` on purpose. A form on a Customer's phone
     * was drawn from the Page as it stood, and a method the installation has
     * since switched off, or the Merchant has since taken off the Page's tab,
     * is still one that form offered in good faith — and at Capture the two
     * cannot be told from a name the Page never listed. So any known name is
     * taken: a method the Page offers now starts a Payment as ever, and any
     * other captures Unpaid with no Payment and lands the Customer on the
     * Retry Page, rather than turning them away for a Merchant's edit
     * (ADR-0013). Only a name that is no method at all is refused.
     *
     * @return list<PaymentMethod>
     */
    public function acceptedMethods(): array
    {
        return PaymentMethod::cases();
    }

    /**
     * The Payment Method a Capture is placed under, given what the Customer
     * chose.
     *
     * A name and never anything more: the request says how they will pay, and
     * whether that is one this Page lists is read here. No name at all is
     * Cash on Delivery where the Page offers it — a Page that draws no chooser
     * posts no method, and a plain post that lost the radio should land where
     * the form would have opened — and null where it does not, which the
     * request refuses before a Capture is reached. A name that is no method
     * at all is null too, for the same treatment; a known name the Page does
     * not offer now is taken, and captures Unpaid.
     */
    public function paymentMethodFor(?string $name): ?PaymentMethod
    {
        if ($name === null) {
            return $this->offersPaymentMethod(PaymentMethod::CashOnDelivery) ? PaymentMethod::CashOnDelivery : null;
        }

        $method = PaymentMethod::fromStored($name);

        return $method !== null && in_array($method, $this->acceptedMethods(), true) ? $method : null;
    }

    /**
     * The server side of the one definition.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = [];

        foreach ($this->enabledFields() as $field) {
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

        foreach ($this->enabledFields() as $field) {
            $messages = [...$messages, ...$field->messages()];
        }

        return $messages;
    }

    /**
     * What a refusal calls each question when no message of ours words it.
     *
     * The Merchant's own wording rather than the key they filed it under, so a
     * Customer is never told that "the gift_wrap field" is wrong. Lower-cased
     * because Laravel drops the name mid-sentence.
     *
     * @return array<string, string>
     */
    public function validationAttributes(): array
    {
        $attributes = [];

        foreach ($this->enabledFields() as $field) {
            $attributes[$field->key()] = Str::lower($field->label);
        }

        return $attributes;
    }

    /**
     * @return array{fields: list<array<string, mixed>>, delivery_options: list<array{label: string, amount: int}>, style_tokens: array<string, string>, show_products: bool, product_picker: bool, default_selection: list<int>, layout: string, words: array{order_button: array{wording: ?string, note: ?string}, subtotal: ?string, delivery: ?string, total: ?string, payment: array<string, array{long: ?string, short: ?string}>}, payment_methods: list<string>, show_payment_logos: bool, hide_lone_cash_choice: bool}
     */
    public function toArray(): array
    {
        return [
            'fields' => $this->fieldsToArray(),
            'delivery_options' => $this->deliveryOptionsToArray(),
            'style_tokens' => $this->styleTokens->toArray(),
            'show_products' => $this->showProducts,
            'product_picker' => $this->productPicker,
            'default_selection' => $this->defaultSelection,
            'layout' => $this->layout->value,
            'words' => $this->words->toArray(),
            'payment_methods' => PaymentMethod::names($this->paymentMethods),
            'show_payment_logos' => $this->showPaymentLogos,
            'hide_lone_cash_choice' => $this->hideLoneCashChoice,
        ];
    }

    /**
     * The field list in its stored shape, which is also the shape the Checkout
     * tab edits a row at a time. Named so the admin can ask for it without
     * knowing which key it is filed under here.
     *
     * @return list<array<string, mixed>>
     */
    public function fieldsToArray(): array
    {
        return array_map(fn (CheckoutField $field): array => $field->toArray(), $this->fields);
    }

    /**
     * The Delivery Options in their stored shape, which is also the shape the
     * Checkout tab edits a row at a time.
     *
     * @return list<array{label: string, amount: int}>
     */
    public function deliveryOptionsToArray(): array
    {
        return array_map(fn (DeliveryOption $option): array => $option->toArray(), $this->deliveryOptions);
    }
}
