{{--
    The Checkout island.

    Rendered once per cache miss and served byte-identical to every visitor, so
    nothing here may read a session, a cookie, a CSRF token or a query string.
    Per-visitor behaviour is client-side or it does not exist.

    Styles are single-class, low-specificity and prefixed, with no element
    selectors and no !important, so a merchant's own stylesheet can restyle the
    form without fighting us. No shadow DOM: it would isolate properly but then
    the merchant could not restyle it at all, and it complicates autofill on a
    form whose whole job is being filled fast on a phone.

    The form is a real form with a real action, so a visitor whose scripts never
    run can still order. The script below only upgrades that: inline errors
    instead of a page reload, and a retry path for clients that send no
    Sec-Fetch-Site header.
--}}
@use(App\Support\Checkout\CheckoutFieldType)
@php
    $currencySymbol = config('giopages.currency.symbol');
    $currencyDecimals = (int) config('giopages.currency.decimals');

    /**
     * The arrangement this form opens on, which is the first the merchant
     * listed — the one charged outright where there is no choice to make, and
     * the radio already ticked where there is. Nothing at all where the page
     * offers no delivery, and then no delivery row is drawn and nothing added.
     *
     * Opening on one rather than on nothing is what keeps the total truthful
     * before the visitor touches anything.
     */
    $preselected = $deliveryOptions[0] ?? null;

    /**
     * What a product's name and picture are wrapped in.
     *
     * A label where there is a box to tick, so a thumb on a phone ticks it by
     * tapping the name or the picture rather than by finding the checkbox
     * itself. A plain div everywhere else: a label with nothing to label is a
     * promise the row does not keep, and it would change the markup of every
     * page that asks no question.
     */
    $productHead = $picksProducts ? 'label' : 'div';
@endphp
<div class="gio-checkout" data-gio-checkout data-page-slug="{{ $page->slug }}">
    <style>
        {{--
            The named token set, declared once on the root and read by every
            rule under it.

            Restyling is a redeclaration of these and never a rule of our own
            making. A generated rule would be more specific than the
            hand-written one beside it on the same page, so the style editor
            would start winning arguments it is supposed to lose — the merchant
            who writes CSS keeps exactly the control they had before this
            existed.

            The same property is what a single field redeclares to differ from
            the rest, on its own wrapper, so this block is the same length
            whether the form asks two questions or twenty.
        --}}
        .gio-checkout { {{ $styleTokens->declarations() }} box-sizing: border-box; max-width: 30rem; margin: 1.5rem auto; padding: 1rem; border: var(--gio-border-width) solid var(--gio-border); border-radius: var(--gio-radius); font-family: inherit; font-size: var(--gio-font-size); color: var(--gio-text); accent-color: var(--gio-accent); }
        .gio-checkout-products { margin: 0 0 1.25rem; }
        .gio-checkout-product + .gio-checkout-product { margin: 1rem 0 0; padding: 1rem 0 0; border-top: var(--gio-border-width) solid var(--gio-border); }
        .gio-checkout-product-head { display: flex; gap: .75rem; align-items: center; }
        .gio-checkout-pickable { display: grid; grid-template-columns: auto 1fr; column-gap: .625rem; }
        .gio-checkout-pickable > * { grid-column: 2; }
        .gio-checkout-pickable > .gio-checkout-pick { grid-column: 1; grid-row: 1; align-self: center; width: 1.15rem; height: 1.15rem; margin: 0; flex: none; }
        .gio-checkout-pickable .gio-checkout-product-head { cursor: pointer; }
        {{--
            The whole point of the picker: five products on a phone are five
            lines of text until they are wanted, rather than five steppers for
            items nobody chose. A sibling selector rather than :has(), so the
            oldest webview that can render the form can also hide the stepper.
        --}}
        .gio-checkout-pick:not(:checked) ~ .gio-checkout-quantity { display: none; }
        .gio-checkout-image { width: 4.5rem; height: 4.5rem; object-fit: cover; border-radius: var(--gio-radius); flex: none; }
        {{--
            Every size below is an `em` against the root's own font size, which
            is a token. That is what makes one control grow the whole form
            proportionally instead of growing one paragraph and leaving the rest
            where it was. Nothing between here and the root sets a font size, so
            nothing compounds.
        --}}
        .gio-checkout-name { margin: 0 0 .25rem; font-size: 1em; font-weight: 600; }
        .gio-checkout-price { margin: 0; font-size: 1.125em; font-weight: 700; }
        .gio-checkout-description { margin: .5rem 0 0; font-size: .875em; line-height: 1.5; }
        .gio-checkout-quantity { margin: .75rem 0 0; }
        .gio-checkout-form { margin: 0; }
        {{--
            The three inherited properties are declared here as well as on the
            root, and that repetition is what makes a per-field override work at
            all. A wrapper redeclaring `--gio-text` cannot reach back and change
            a `color` already computed on its ancestor; only a declaration on
            the wrapper itself reads the value the wrapper carries. The values
            are the root's, so a field that overrides nothing renders as it did.
        --}}
        .gio-checkout-field { margin: 0 0 .75rem; color: var(--gio-text); font-size: var(--gio-font-size); accent-color: var(--gio-accent); }
        .gio-checkout-label { display: block; margin: 0 0 .25rem; font-size: .8125em; font-weight: 600; }
        .gio-checkout-help { margin: 0 0 .375rem; font-size: .75em; line-height: 1.4; opacity: .75; }
        {{--
            One scale, two declarations. The padding is stated in `em`, so it is
            measured against the font size the same scale just set — a merchant
            asking for a bigger box cannot end up with a bigger box full of type
            too small to read back what they typed.
        --}}
        .gio-checkout-input { box-sizing: border-box; width: 100%; padding: .625em .75em; border: var(--gio-border-width) solid var(--gio-border); border-radius: var(--gio-radius); font-family: inherit; font-size: calc(1em * var(--gio-input-size)); background: var(--gio-background); color: inherit; }
        .gio-checkout-input:focus { outline: 2px solid var(--gio-accent); outline-offset: -1px; }
        {{--
            Below the rule it narrows, and that is the whole of what makes it
            work: the stepper carries both classes, both selectors are one
            class, so the later declaration is the one that applies. It sat
            above and has never had any effect — a stepper for a quantity of one
            has been running the full width of the form since it was written.

            Sized off the same scale as the box it is, so a form asked for at
            Large does not get a wider stepper's worth of type in a box that
            stayed where it was.
        --}}
        .gio-checkout-quantity-input { width: calc(7rem * var(--gio-input-size)); }
        .gio-checkout-delivery, .gio-checkout-choices { margin: 0; padding: 0; border: 0; }
        {{--
            A row a customer taps is a box like any other, so the same scale
            sizes it. Stated as two multiplications rather than in `em` off its
            own font size, which is what the input above does: the row's text is
            not the form's text size, so `em` padding here would not come out
            where it has always been.

            Not painted with the background token. The boxes a customer types
            into have always been white and the rows have always been the page
            showing through; painting them now would turn light text on a dark
            page into light text on white on every checkout already published.
        --}}
        .gio-checkout-choice { display: flex; gap: .625rem; align-items: center; padding: calc(.625rem * var(--gio-input-size)) calc(.75rem * var(--gio-input-size)); border: var(--gio-border-width) solid var(--gio-border); border-radius: var(--gio-radius); font-size: calc(.9375em * var(--gio-input-size)); cursor: pointer; }
        .gio-checkout-choice + .gio-checkout-choice { margin: .375rem 0 0; }
        .gio-checkout-choice-label { flex: 1; }
        .gio-checkout-choice-amount { font-weight: 600; }
        .gio-checkout-error { display: none; margin: .25rem 0 0; font-size: .8125em; color: #b91c1c; }
        .gio-checkout-error[data-shown] { display: block; }
        .gio-checkout-summary { margin: 1rem 0; padding: .75rem 0 0; border-top: var(--gio-border-width) solid var(--gio-border); }
        {{--
            The summary's own size, moved down onto its rows. It sat on the
            container while the sizes were rem and nothing compounded; in `em`
            it would multiply against the total's, which carries both classes
            and is meant to be the larger figure, not a fraction of a fraction.
        --}}
        .gio-checkout-row { display: flex; justify-content: space-between; gap: 1rem; margin: 0 0 .375rem; font-size: .875em; }
        .gio-checkout-total { font-size: 1.0625em; font-weight: 700; margin: 0; }
        .gio-checkout-submit { display: block; width: 100%; padding: .875rem 1rem; border: 0; border-radius: var(--gio-button-radius); background: var(--gio-button-background); color: var(--gio-button-text); font-family: inherit; font-size: 1.0625em; font-weight: 700; cursor: pointer; }
        .gio-checkout-submit[disabled] { opacity: .6; cursor: progress; }
        .gio-checkout-note { margin: .625rem 0 0; font-size: .75em; text-align: center; opacity: .75; }
    </style>

    {{--
        No @csrf token: this markup is shared by every visitor, so a token here
        would be one person's token handed to everyone. The POST authenticates
        by origin instead, with the script's token retry as the fallback.
    --}}
    <form class="gio-checkout-form"
          method="post"
          action="{{ route('checkout.store') }}"
          data-gio-checkout-form
          data-currency-symbol="{{ $currencySymbol }}"
          data-currency-decimals="{{ $currencyDecimals }}"
          {{--
              What delivery costs when there is nothing to choose between. The
              server charges it either way, so the figure on screen has to be
              the figure collected at the door — a total that reads low by the
              delivery charge is a refused parcel.
          --}}
          data-delivery-amount="{{ $preselected?->amount ?? 0 }}"
          data-token-url="{{ route('checkout.token') }}">
        <input type="hidden" name="page" value="{{ $page->slug }}">

        {{--
            Everything this Page sells, each with a quantity of its own.

            Quantities are keyed by Product id rather than by the position the
            Product sits at, so reordering them in the admin cannot point a form
            already open on someone's phone at a different item. A Product
            soft-deleted since these bytes were written is simply absent from
            the Page by the time the Capture is checked, and the rest still sell.
        --}}
        <div class="gio-checkout-products">
            @foreach ($products as $product)
                <div class="gio-checkout-product{{ $picksProducts ? ' gio-checkout-pickable' : '' }}"
                     data-gio-line data-unit-price="{{ $product->price }}">
                    @if ($picksProducts)
                        {{--
                            Unticked, every one of them. On a cash-on-delivery
                            page a pre-ticked upsell is not a lost sale, it is a
                            refused parcel and a courier fee in both directions
                            on the whole order — including the item the customer
                            actually wanted.

                            Labelled by the product's own name, so a screen
                            reader hears what is being ticked rather than
                            "checkbox" three times.
                        --}}
                        <input class="gio-checkout-pick"
                               id="gio-pick-{{ $product->getKey() }}"
                               name="selected[]"
                               value="{{ $product->getKey() }}"
                               aria-labelledby="gio-product-{{ $product->getKey() }}"
                               type="checkbox" data-gio-pick>
                    @endif

                    {{-- The `for` carries its own leading space, so a page that asks nothing renders the tag it always did. --}}
                    <{{ $productHead }} class="gio-checkout-product-head"@if ($picksProducts) for="gio-pick-{{ $product->getKey() }}"@endif>
                        @if ($image = $product->imageUrl())
                            <img class="gio-checkout-image" src="{{ $image }}" alt="{{ $product->name }}" loading="lazy">
                        @endif

                        <div>
                            <p class="gio-checkout-name" id="gio-product-{{ $product->getKey() }}">{{ $product->name }}</p>
                            <p class="gio-checkout-price">{{ \App\Support\Money::format($product->price) }}</p>
                        </div>
                    </{{ $productHead }}>

                    @if ($product->description)
                        <p class="gio-checkout-description">{{ $product->description }}</p>
                    @endif

                    <div class="gio-checkout-quantity">
                        {{--
                            Labelled by its own word *and* the product's name, so a
                            screen reader on a page selling three things hears
                            "Quantity, Winter Jacket" rather than "Quantity" three
                            times. The visible label stays the visible label, which
                            is what a voice-control user says out loud.
                        --}}
                        <label class="gio-checkout-label"
                               id="gio-quantity-label-{{ $product->getKey() }}"
                               for="gio-quantity-{{ $product->getKey() }}">Quantity</label>
                        {{--
                            Not `required` where the box can be hidden. A
                            stepper emptied and then unticked would fail the
                            browser's own check from behind `display: none`,
                            which refuses to submit and shows the customer
                            nothing at all. The server checks what was ticked
                            either way, and the island puts that refusal in the
                            slot below.
                        --}}
                        <input class="gio-checkout-input gio-checkout-quantity-input"
                               id="gio-quantity-{{ $product->getKey() }}"
                               name="quantities[{{ $product->getKey() }}]"
                               aria-labelledby="gio-quantity-label-{{ $product->getKey() }} gio-product-{{ $product->getKey() }}"
                               type="number" value="1" min="1" max="{{ $maxQuantity }}" step="1"
                               inputmode="numeric" @required(! $picksProducts) data-gio-quantity>
                        <p class="gio-checkout-error" data-gio-error-for="quantities.{{ $product->getKey() }}"></p>
                    </div>
                </div>
            @endforeach
        </div>

        {{--
            Where an order for nothing is refused, beside the boxes that were
            never ticked. Only a page that asks the question has anywhere to
            show the answer being wrong; on any other, the island's fallback
            puts it in the page-level slot instead.
        --}}
        @if ($picksProducts)
            <p class="gio-checkout-error" data-gio-error-for="selected"></p>
        @endif

        {{--
            The questions, their wording and their order come from this Page's
            own Checkout Config — the same list the server compiles its rules
            from, so a visitor is never blocked from typing something the
            Capture would have accepted.

            One loop for both kinds of field. A question the merchant added of
            their own is drawn by its type exactly as a built-in of that type
            is, and sits wherever the list puts it, including between two
            built-ins (ADR-0006).
        --}}
        @foreach ($fields as $field)
            @php
                $id = 'gio-'.$field->key();

                /** The id is the view's to invent, so it is the view that ties the two together. */
                $control = $field->controlAttributes()->merge(array_filter([
                    'aria-describedby' => $field->help === null ? null : $id.'-help',
                ]));
            @endphp

            {{--
                What this one question looks like where the merchant wanted it
                to differ from the rest, written onto its own wrapper.

                A redeclaration of tokens the root already declares, so the
                cascade confines it to this field and nothing has to be
                generated to name it. Absent entirely where the merchant
                overrode nothing, which is every field on almost every page.
            --}}
            <div class="gio-checkout-field"@if (! $field->styleTokens->isEmpty()) style="{{ $field->styleTokens->declarations() }}"@endif>
                {{--
                    A radio group and a checkbox carry their own wording — a
                    legend over the choices, and the text beside the box — so
                    neither takes the label block above it. Labelling both would
                    ask the same question twice, and a stray <label> pointing at
                    nothing is worse than no label at all.
                --}}
                @if ($field->type() === CheckoutFieldType::Radio)
                    <fieldset class="gio-checkout-choices">
                        <legend class="gio-checkout-label">{{ $field->label }}</legend>

                        @if ($field->help !== null)
                            <p class="gio-checkout-help" id="{{ $id }}-help">{{ $field->help }}</p>
                        @endif

                        {{--
                            Nothing chosen to begin with, even where an answer is
                            compulsory: a pre-ticked answer is one the customer
                            never gave. The browser's own check asks for one, and
                            the server refuses a value the list does not hold.
                        --}}
                        @foreach ($field->options() as $option)
                            <label class="gio-checkout-choice">
                                <input name="{{ $field->key() }}" value="{{ $option->value }}"
                                       {{ $control }} @required($field->required)>
                                <span class="gio-checkout-choice-label">{{ $option->label }}</span>
                            </label>
                        @endforeach
                    </fieldset>
                @elseif ($field->type() === CheckoutFieldType::Checkbox)
                    @if ($field->help !== null)
                        <p class="gio-checkout-help" id="{{ $id }}-help">{{ $field->help }}</p>
                    @endif

                    <label class="gio-checkout-choice">
                        <input name="{{ $field->key() }}" {{ $control }} @required($field->required)>
                        <span class="gio-checkout-choice-label">{{ $field->label }}</span>
                    </label>
                @else
                    <label class="gio-checkout-label" for="{{ $id }}">{{ $field->label }}</label>

                    @if ($field->help !== null)
                        <p class="gio-checkout-help" id="{{ $id }}-help">{{ $field->help }}</p>
                    @endif

                    @switch ($field->type())
                        @case (CheckoutFieldType::Textarea)
                            <textarea class="gio-checkout-input" id="{{ $id }}" name="{{ $field->key() }}"
                                      {{ $control }} @required($field->required)></textarea>
                            @break

                        @case (CheckoutFieldType::Select)
                            <select class="gio-checkout-input" id="{{ $id }}" name="{{ $field->key() }}"
                                    {{ $control }} @required($field->required)>
                                {{--
                                    Unpickable only where an answer is compulsory, so a browser can
                                    submit what the server would accept. It is rendered even with no
                                    placeholder to word it: a required select has to be able to start
                                    on nothing chosen, rather than on whichever district sorts first.
                                --}}
                                <option value="" selected @disabled($field->required)>{{ $field->placeholder }}</option>
                                @foreach ($field->options() as $option)
                                    <option value="{{ $option->value }}">{{ $option->label }}</option>
                                @endforeach
                            </select>
                            @break

                        @default
                            <input class="gio-checkout-input" id="{{ $id }}" name="{{ $field->key() }}"
                                   {{ $control }} @required($field->required)>
                    @endswitch
                @endif

                <p class="gio-checkout-error" data-gio-error-for="{{ $field->key() }}"></p>
            </div>
        @endforeach

        {{--
            How the parcel gets to the door, in the merchant's own words and at
            their own prices. Rendered only where there is a decision to make: a
            page offering one arrangement charges it, and a page offering none
            charges nothing, and neither is a question worth putting in front of
            a customer whose only possible answer is the one they would be given.

            Radios rather than a select, because the amounts have to be readable
            without opening anything — the choice is a price as much as it is a
            delivery method. The first is already chosen so the total below is
            truthful from the first paint; the server refuses a submission that
            names none of them regardless.
        --}}
        @if (count($deliveryOptions) > 1)
            <div class="gio-checkout-field">
                <fieldset class="gio-checkout-delivery">
                    <legend class="gio-checkout-label">Delivery</legend>

                    @foreach ($deliveryOptions as $index => $option)
                        <label class="gio-checkout-choice">
                            <input type="radio"
                                   name="delivery_option"
                                   value="{{ $option->label }}"
                                   data-gio-delivery-option
                                   data-amount="{{ $option->amount }}"
                                   @checked($index === 0) required>
                            <span class="gio-checkout-choice-label">{{ $option->label }}</span>
                            <span class="gio-checkout-choice-amount">{{ \App\Support\Money::format($option->amount) }}</span>
                        </label>
                    @endforeach
                </fieldset>

                <p class="gio-checkout-error" data-gio-error-for="delivery_option"></p>
            </div>
        @endif

        {{--
            One of everything, which is what the quantity boxes above start on,
            plus the delivery arrangement that is already selected. The script
            keeps these truthful as the visitor changes them; a visitor whose
            scripts never run reads the figures the page was rendered with, and
            the confirmation page after Capture is where they see what they
            actually owe.

            Nothing at all where the customer picks: nothing is ticked, so
            nothing is being bought yet, and a subtotal quoting the whole shelf
            would be a figure no order could ever come to.
        --}}
        @php($subtotal = $picksProducts ? 0 : (int) $products->sum('price'))

        <div class="gio-checkout-summary">
            <p class="gio-checkout-row">
                <span>Subtotal</span>
                <span data-gio-subtotal>{{ \App\Support\Money::format($subtotal) }}</span>
            </p>

            {{--
                Named after the arrangement rather than after the word
                "delivery": the customer agreed to "Inside Dhaka", and that is
                what the row they are asked to pay should say. Nothing at all
                where the page charges for no delivery.
            --}}
            @if ($preselected !== null)
                <p class="gio-checkout-row">
                    <span data-gio-delivery-label>{{ $preselected->label }}</span>
                    <span data-gio-delivery>{{ \App\Support\Money::format($preselected->amount) }}</span>
                </p>
            @endif

            <p class="gio-checkout-row gio-checkout-total">
                <span>Total</span>
                <span data-gio-total>{{ \App\Support\Money::format($subtotal + ($preselected?->amount ?? 0)) }}</span>
            </p>
        </div>

        {{-- The page-level failure slot: a refusal the visitor cannot fix by editing a field. --}}
        <p class="gio-checkout-error" data-gio-error-for="page"></p>

        <button class="gio-checkout-submit" type="submit" data-gio-submit>Order now &mdash; Cash on delivery</button>

        <p class="gio-checkout-note">Pay in cash when the parcel reaches you.</p>
    </form>
</div>

<script>
(function () {
    /*
     * Guarded and delegated from the document, so the script is harmless if a
     * draft preview happens to expand two islands: one listener serves any
     * number of forms, and a second copy of this block does nothing.
     */
    if (window.__gioCheckoutBound) { return; }
    window.__gioCheckoutBound = true;

    var SUBMITTING = 'Placing your order…';

    function money(form, minorUnits) {
        var decimals = parseInt(form.dataset.currencyDecimals, 10) || 0;
        var value = minorUnits / Math.pow(10, decimals);
        return form.dataset.currencySymbol + value.toFixed(decimals);
    }

    /*
     * The delivery arrangement this form is currently on: whichever radio is
     * chosen, or — where the page offers no choice — the single amount it
     * charges, which is nothing at all when it charges for no delivery.
     *
     * Never a dash. The server adds this figure either way, so a total on
     * screen that reads low by it is a parcel refused at the door.
     */
    function deliveryFor(form) {
        var picked = form.querySelector('[data-gio-delivery-option]:checked');

        if (picked) {
            return { label: picked.value, amount: parseInt(picked.dataset.amount, 10) || 0 };
        }

        return { label: null, amount: parseInt(form.dataset.deliveryAmount, 10) || 0 };
    }

    /* Whether this form asks the customer which products to buy. */
    function picks(form) {
        return form.querySelector('[data-gio-pick]') !== null;
    }

    /* Whether they have chosen any. */
    function picked(form) {
        return form.querySelector('[data-gio-pick]:checked') !== null;
    }

    /*
     * The subtotal of one line, from the price rendered into it. Read off the
     * line rather than off a single price on the form: a page may sell several
     * products, each at its own price and its own quantity.
     *
     * Nothing for a line the customer has not ticked, so the summary only ever
     * totals up what they are actually ordering.
     */
    function lineSubtotal(line) {
        var pick = line.querySelector('[data-gio-pick]');

        if (pick && !pick.checked) { return 0; }

        var unitPrice = parseInt(line.dataset.unitPrice, 10) || 0;
        var input = line.querySelector('[data-gio-quantity]');
        var quantity = Math.max(1, parseInt(input ? input.value : '', 10) || 1);

        return unitPrice * quantity;
    }

    /* The figure the customer hands over in cash, kept truthful as they type. */
    function retotal(form) {
        var subtotal = 0;

        form.querySelectorAll('[data-gio-line]').forEach(function (line) {
            subtotal += lineSubtotal(line);
        });

        var delivery = deliveryFor(form);

        var set = function (name, text) {
            var el = form.querySelector('[data-gio-' + name + ']');
            if (el) { el.textContent = text; }
        };

        set('subtotal', money(form, subtotal));
        set('delivery', money(form, delivery.amount));
        set('total', money(form, subtotal + delivery.amount));

        /* The row is named after the arrangement the customer just chose. */
        if (delivery.label !== null) { set('delivery-label', delivery.label); }
    }

    function clearErrors(form) {
        form.querySelectorAll('[data-gio-error-for]').forEach(function (el) {
            el.textContent = '';
            el.removeAttribute('data-shown');
        });
    }

    function slotFor(form, field) {
        return form.querySelector('[data-gio-error-for="' + field + '"]');
    }

    function showErrors(form, errors) {
        var first = null;
        var fields = Object.keys(errors || {});

        var show = function (el, message) {
            /* One slot, one message: whatever reaches it first stands. */
            if (!el || el.hasAttribute('data-shown')) { return; }
            el.textContent = message;
            el.setAttribute('data-shown', '');
            if (!first) { first = el; }
        };

        fields.forEach(function (field) {
            show(slotFor(form, field), [].concat(errors[field])[0]);
        });

        /*
         * A refusal keyed to something this form never asked for — a field the
         * merchant switched off after these bytes were written to disk — falls
         * back to the page-level slot, because a visitor whose order was
         * refused has to be told something. Discarding it leaves them tapping a
         * button that re-enables and says nothing.
         *
         * Second, so it can never take that slot from a page-level refusal that
         * came in the same response and belongs there.
         */
        fields.forEach(function (field) {
            if (slotFor(form, field)) { return; }
            show(slotFor(form, 'page'), [].concat(errors[field])[0]);
        });

        if (first) { first.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
    }

    function showFailure(form, message) {
        showErrors(form, { page: [message] });
    }

    function post(form, token) {
        var headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        if (token) { headers['X-CSRF-TOKEN'] = token; }

        return fetch(form.action, {
            method: 'POST',
            headers: headers,
            body: new FormData(form),
            credentials: 'same-origin'
        });
    }

    function formFor(event) {
        var target = event.target;
        return target && target.closest ? target.closest('[data-gio-checkout-form]') : null;
    }

    document.addEventListener('input', function (event) {
        var form = formFor(event);
        if (form) { retotal(form); }
    });

    document.addEventListener('change', function (event) {
        var form = formFor(event);
        if (!form) { return; }

        /*
         * Unticking forgets the quantity, so a stepper is back at one the next
         * time it is revealed — which is what a customer ticking a box again
         * expects, and what the box said when they first saw it.
         */
        var target = event.target;

        if (target.matches && target.matches('[data-gio-pick]') && !target.checked) {
            var line = target.closest('[data-gio-line]');
            var quantity = line ? line.querySelector('[data-gio-quantity]') : null;

            if (quantity) { quantity.value = '1'; }
        }

        retotal(form);
    });

    document.addEventListener('submit', function (event) {
        var form = formFor(event);
        if (!form) { return; }

        event.preventDefault();

        clearErrors(form);

        /*
         * An order for nothing is not an order. Refused here as well as at the
         * server, so a customer who ticked nothing is told beside the boxes
         * rather than after a round trip that leaves them looking at a button.
         */
        if (picks(form) && !picked(form)) {
            showErrors(form, { selected: ['Please choose at least one item to order.'] });
            return;
        }

        var button = form.querySelector('[data-gio-submit]');
        var buttonLabel = button ? button.innerHTML : null;

        if (button) {
            /* Guards the double-tap that would otherwise capture two Orders. */
            if (button.disabled) { return; }
            button.disabled = true;
            button.textContent = SUBMITTING;
        }

        var restore = function () {
            if (button) {
                button.disabled = false;
                button.innerHTML = buttonLabel;
            }
        };

        var handle = function (response, allowRetry) {
            if (response.status === 201) {
                return response.json().then(function (body) {
                    /* Replaced rather than pushed: back must not re-offer a submitted form. */
                    window.location.replace(body.redirect);
                });
            }

            if (response.status === 422) {
                return response.json().then(function (body) {
                    showErrors(form, body.errors);
                    restore();
                });
            }

            /*
             * 419 means the origin check did not pass and no token was sent —
             * the client omitted Sec-Fetch-Site. Fetch a token and go again,
             * once. This is the one round trip that keeps such a client from
             * losing its order outright.
             */
            if (response.status === 419 && allowRetry) {
                return fetch(form.dataset.tokenUrl, {
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin'
                })
                    .then(function (tokenResponse) { return tokenResponse.json(); })
                    .then(function (body) { return post(form, body.token); })
                    .then(function (retried) { return handle(retried, false); });
            }

            if (response.status === 429) {
                showFailure(form, 'Too many attempts just now. Please wait a moment and try again.');
                restore();
                return;
            }

            showFailure(form, 'Something went wrong placing your order. Please try again.');
            restore();
        };

        post(form, null)
            .then(function (response) { return handle(response, true); })
            .catch(function () {
                showFailure(form, 'We could not reach the server. Please check your connection and try again.');
                restore();
            });
    });

    document.querySelectorAll('[data-gio-checkout-form]').forEach(retotal);
})();
</script>
