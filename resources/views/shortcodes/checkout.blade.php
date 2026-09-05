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
@endphp
<div class="gio-checkout" data-gio-checkout data-page-slug="{{ $page->slug }}">
    <style>
        .gio-checkout { box-sizing: border-box; max-width: 30rem; margin: 1.5rem auto; padding: 1rem; border: 1px solid #e5e7eb; border-radius: .5rem; font-family: inherit; }
        .gio-checkout-product { display: flex; gap: .75rem; align-items: center; }
        .gio-checkout-image { width: 4.5rem; height: 4.5rem; object-fit: cover; border-radius: .375rem; flex: none; }
        .gio-checkout-name { margin: 0 0 .25rem; font-size: 1rem; font-weight: 600; }
        .gio-checkout-price { margin: 0; font-size: 1.125rem; font-weight: 700; }
        .gio-checkout-description { margin: .5rem 0 0; font-size: .875rem; line-height: 1.5; }
        .gio-checkout-form { margin: 1rem 0 0; }
        .gio-checkout-field { margin: 0 0 .75rem; }
        .gio-checkout-label { display: block; margin: 0 0 .25rem; font-size: .8125rem; font-weight: 600; }
        .gio-checkout-input { box-sizing: border-box; width: 100%; padding: .625rem .75rem; border: 1px solid #d1d5db; border-radius: .375rem; font-family: inherit; font-size: 1rem; background: #fff; color: inherit; }
        .gio-checkout-input:focus { outline: 2px solid #2563eb; outline-offset: -1px; }
        .gio-checkout-error { display: none; margin: .25rem 0 0; font-size: .8125rem; color: #b91c1c; }
        .gio-checkout-error[data-shown] { display: block; }
        .gio-checkout-summary { margin: 1rem 0; padding: .75rem 0 0; border-top: 1px solid #e5e7eb; font-size: .875rem; }
        .gio-checkout-row { display: flex; justify-content: space-between; gap: 1rem; margin: 0 0 .375rem; }
        .gio-checkout-total { font-size: 1.0625rem; font-weight: 700; margin: 0; }
        .gio-checkout-submit { display: block; width: 100%; padding: .875rem 1rem; border: 0; border-radius: .375rem; background: #16a34a; color: #fff; font-family: inherit; font-size: 1.0625rem; font-weight: 700; cursor: pointer; }
        .gio-checkout-submit[disabled] { opacity: .6; cursor: progress; }
        .gio-checkout-note { margin: .625rem 0 0; font-size: .75rem; text-align: center; opacity: .75; }
    </style>

    <div class="gio-checkout-product">
        @if ($image = $product->imageUrl())
            <img class="gio-checkout-image" src="{{ $image }}" alt="{{ $product->name }}" loading="lazy">
        @endif

        <div>
            <p class="gio-checkout-name">{{ $product->name }}</p>
            <p class="gio-checkout-price">{{ \App\Support\Money::format($product->price) }}</p>
        </div>
    </div>

    @if ($product->description)
        <p class="gio-checkout-description">{{ $product->description }}</p>
    @endif

    {{--
        No @csrf token: this markup is shared by every visitor, so a token here
        would be one person's token handed to everyone. The POST authenticates
        by origin instead, with the script's token retry as the fallback.
    --}}
    <form class="gio-checkout-form"
          method="post"
          action="{{ route('checkout.store') }}"
          data-gio-checkout-form
          data-unit-price="{{ $product->price }}"
          data-currency-symbol="{{ $currencySymbol }}"
          data-currency-decimals="{{ $currencyDecimals }}"
          data-delivery-charges="{{ json_encode($deliveryCharges) }}"
          data-token-url="{{ route('checkout.token') }}">
        <input type="hidden" name="page" value="{{ $page->slug }}">

        {{--
            The questions, their wording and their order come from this Page's
            own Checkout Config — the same list the server compiles its rules
            from, so a visitor is never blocked from typing something the
            Capture would have accepted.
        --}}
        @foreach ($fields as $field)
            <div class="gio-checkout-field">
                <label class="gio-checkout-label" for="gio-{{ $field->key() }}">{{ $field->label }}</label>

                @switch ($field->type())
                    @case (CheckoutFieldType::Textarea)
                        <textarea class="gio-checkout-input" id="gio-{{ $field->key() }}" name="{{ $field->key() }}"
                                  {{ $field->controlAttributes() }} @required($field->required)></textarea>
                        @break

                    @case (CheckoutFieldType::Select)
                        <select class="gio-checkout-input" id="gio-{{ $field->key() }}" name="{{ $field->key() }}"
                                {{ $field->controlAttributes() }} @required($field->required)>
                            <option value="" selected disabled>{{ $field->placeholder() }}</option>
                            @foreach ($field->options() as $option)
                                <option value="{{ $option }}">{{ $option }}</option>
                            @endforeach
                        </select>
                        @break

                    @default
                        <input class="gio-checkout-input" id="gio-{{ $field->key() }}" name="{{ $field->key() }}"
                               {{ $field->controlAttributes() }} @required($field->required)>
                @endswitch

                <p class="gio-checkout-error" data-gio-error-for="{{ $field->key() }}"></p>
            </div>
        @endforeach

        <div class="gio-checkout-field">
            <label class="gio-checkout-label" for="gio-quantity">Quantity</label>
            <input class="gio-checkout-input" id="gio-quantity" name="quantity" type="number"
                   value="1" min="1" max="{{ $maxQuantity }}" step="1" inputmode="numeric" required>
            <p class="gio-checkout-error" data-gio-error-for="quantity"></p>
        </div>

        <div class="gio-checkout-summary">
            <p class="gio-checkout-row">
                <span>Subtotal</span>
                <span data-gio-subtotal>{{ \App\Support\Money::format($product->price) }}</span>
            </p>
            <p class="gio-checkout-row">
                <span>Delivery</span>
                <span data-gio-delivery>&mdash;</span>
            </p>
            <p class="gio-checkout-row gio-checkout-total">
                <span>Total</span>
                <span data-gio-total>{{ \App\Support\Money::format($product->price) }}</span>
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

    function deliveryFor(form) {
        var district = form.elements.district ? form.elements.district.value : '';
        if (!district) { return null; }
        try {
            var charges = JSON.parse(form.dataset.deliveryCharges);
            return typeof charges[district] === 'number' ? charges[district] : null;
        } catch (e) {
            return null;
        }
    }

    /* The figure the customer hands over in cash, kept truthful as they type. */
    function retotal(form) {
        var unitPrice = parseInt(form.dataset.unitPrice, 10) || 0;
        var quantity = Math.max(1, parseInt(form.elements.quantity.value, 10) || 1);
        var subtotal = unitPrice * quantity;
        var delivery = deliveryFor(form);

        var set = function (name, text) {
            var el = form.querySelector('[data-gio-' + name + ']');
            if (el) { el.textContent = text; }
        };

        set('subtotal', money(form, subtotal));
        set('delivery', delivery === null ? '—' : money(form, delivery));
        set('total', money(form, subtotal + (delivery || 0)));
    }

    function clearErrors(form) {
        form.querySelectorAll('[data-gio-error-for]').forEach(function (el) {
            el.textContent = '';
            el.removeAttribute('data-shown');
        });
    }

    /*
     * Where an error is shown. A refusal keyed to something this form never
     * asked for — a field the merchant switched off after these bytes were
     * written to disk — falls back to the page-level slot, because the visitor
     * whose order was refused has to be told something. Discarding it leaves
     * them tapping a button that re-enables and says nothing.
     */
    function slotFor(form, field) {
        return form.querySelector('[data-gio-error-for="' + field + '"]')
            || form.querySelector('[data-gio-error-for="page"]');
    }

    function showErrors(form, errors) {
        var first = null;

        Object.keys(errors || {}).forEach(function (field) {
            var el = slotFor(form, field);
            /* One slot, one message: the first refusal to reach it stands. */
            if (!el || el.hasAttribute('data-shown')) { return; }
            el.textContent = [].concat(errors[field])[0];
            el.setAttribute('data-shown', '');
            if (!first) { first = el; }
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
        if (form) { retotal(form); }
    });

    document.addEventListener('submit', function (event) {
        var form = formFor(event);
        if (!form) { return; }

        event.preventDefault();

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

        clearErrors(form);

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
