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

    /**
     * Whether one product's box opens ticked.
     *
     * Written once and read twice — by the box itself and by the subtotal
     * beneath it. Two readings of the same list is how a figure and a tick
     * come to disagree, and the disagreement would stand for a visitor whose
     * scripts never run.
     */
    $opensTicked = fn (\App\Models\Product $product): bool => in_array($product->getKey(), $defaultSelection, true);
@endphp
{{--
    The layout's class rides beside the base one, in every layout, and the
    stylesheet below is the base every arrangement shares plus that one
    arrangement's own rules. That is what "one base sheet plus a modifier" buys:
    these styles ship inside every cached page, so a layout with a sheet of its
    own would charge every visitor for the arrangements their page is not using,
    while a block emitted only where it applies charges nobody.
--}}
<div class="gio-checkout {{ $layout->modifierClass() }}" data-gio-checkout data-page-slug="{{ $page->slug }}">
    <style>
        {{--
            The typeface, served from this site. Declared once, here, and only
            while the font token names it: a merchant who typed `inherit` asked
            for the page's own face and gets no font file at all. Unescaped
            because it is our own block and no merchant's value is in it.
        --}}
        {!! $styleTokens->fontFaces() !!}
        {{--
            The eleven Style Tokens, declared once on the root and read by every
            rule under it.

            Restyling is a redeclaration of these and never a rule of our own
            making. A generated rule would be more specific than the
            hand-written one beside it on the same page, so the style editor
            would start winning arguments it is supposed to lose — the merchant
            who writes CSS keeps exactly the control they had before this
            existed.

            Set once for the whole form: no question redeclares a token on its
            own wrapper, so this block is the same bytes whether the form asks
            two questions or twenty, and the page never carries a per-field
            style attribute. Everything that is not a token — how wide the form
            sits, one-pixel borders, the type size — is the layout's figure,
            written here.
        --}}
        {{--
            No panel. The island sits inside a page the merchant already
            designed, so a box of its own would double-frame whatever they drew
            around it; a merchant who wants one writes it into their own
            stylesheet, which is what the flat sheet exists to allow. With no
            box there is nothing to inset from, so no padding either — the form
            sits flush with the element the shortcode was dropped in. What is
            bordered instead is what a thumb has to hit: the boxes a customer
            types into and the product rows they pick, with a hairline rule
            between one section and the next and structural whitespace for
            everything else.

            The split block's arithmetic reads the root's edges, so a padding
            or a border put back here has to be added to the figures there.
        --}}
        {{--
            `font-family` reads the token, and `inherit` in the token still
            means the page's face: a custom property set to `inherit` takes the
            parent's `--gio-font`, which nothing declares, so the `var()` is
            invalid at computed-value time and `font-family` — an inherited
            property — falls back to inheriting. Sound for any inherited
            property; do not move the read onto one that is not.
        --}}
        .gio-checkout { {{ $styleTokens->declarations() }} box-sizing: border-box; max-width: 30rem; margin: 1.5rem auto; background: var(--gio-background); font-family: var(--gio-font); font-size: 1rem; color: var(--gio-text); accent-color: var(--gio-accent); }
        .gio-checkout-products { margin: 0 0 1.25rem; padding: 0 0 1.25rem; border-bottom: 1px solid var(--gio-border); }
        {{--
            A product row is boxed and filled like the boxes a customer types
            into — the same border token, the same surface, the same radius —
            because it is the same kind of thing: something a thumb has to hit.
            One redeclaration restyles all of them together.
        --}}
        .gio-checkout-product { padding: .75rem; border: 1px solid var(--gio-border); border-radius: var(--gio-radius); background: var(--gio-surface); }
        .gio-checkout-product + .gio-checkout-product { margin: .5rem 0 0; }
        .gio-checkout-product-head { display: flex; gap: .75rem; align-items: center; }
        .gio-checkout-pickable { display: grid; grid-template-columns: auto 1fr; column-gap: .625rem; }
        .gio-checkout-pickable > * { grid-column: 2; }
        .gio-checkout-pickable > .gio-checkout-pick { grid-column: 1; grid-row: 1; align-self: center; width: 1.15rem; height: 1.15rem; margin: 0; flex: none; }
        .gio-checkout-pickable .gio-checkout-product-head { cursor: pointer; }
        {{--
            A picked product reads as picked from across the room, the same way
            a chosen delivery row does and in the same token: the border takes
            the selected colour, a one-pixel ring outside it thickens the edge
            without moving the row, and the ground takes a light tint of it.
            The tint is mixed from the one token rather than being a second
            colour, so a merchant changes what "picked" looks like in one box.
            On a cash-on-delivery page an item nobody meant to choose is a
            refused parcel and courier fees both ways, so this is the state a
            customer most needs to be able to tell at a glance.

            `:has()` because the tick is a sibling of the head, not of the row,
            and only a box inside a product row can be checked. A browser that
            cannot read it, or cannot mix a colour, draws the row it always
            drew with the tick still saying which is picked.
        --}}
        .gio-checkout-product:has(:checked) { border-color: var(--gio-accent); box-shadow: 0 0 0 1px var(--gio-accent); background: color-mix(in srgb, var(--gio-accent) 8%, transparent); }
        {{--
            The whole point of the picker: five products on a phone are five
            lines of text until they are wanted, rather than five steppers for
            items nobody chose. A sibling selector rather than :has(), so the
            oldest webview that can render the form can also hide the stepper.
        --}}
        .gio-checkout-pick:not(:checked) ~ .gio-checkout-quantity { display: none; }
        .gio-checkout-image { width: 4.5rem; height: 4.5rem; object-fit: cover; border-radius: var(--gio-radius); flex: none; }
        {{--
            Every size below is an `em` against the root's own font size, so a
            layout that sets the root's type larger grows the whole form
            proportionally instead of growing one paragraph and leaving the rest
            where it was. Nothing between here and the root sets a font size, so
            nothing compounds.
        --}}
        {{--
            The row reads as a cart line: the picture, then the name taking
            what is left, then the price at the far end where a customer
            expects a figure. `min-width: 0` on the name is what lets a long
            one wrap rather than push the price out of the row.
        --}}
        .gio-checkout-name { flex: 1; min-width: 0; margin: 0; font-size: 1em; font-weight: 600; }
        .gio-checkout-price { margin: 0; font-size: 1.125em; font-weight: 700; white-space: nowrap; }
        .gio-checkout-description { margin: .5rem 0 0; font-size: .875em; line-height: 1.5; color: var(--gio-muted); }
        {{--
            The word and the stepper on one line, and the refusal — when there
            is one — on a line of its own beneath them, which is what the wrap
            and the full-width slot are for. Both of the slot's rules sit below
            the one-class rules they narrow and beat them by order, the same
            trade as everywhere else in this sheet.
        --}}
        .gio-checkout-quantity { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem .75rem; margin: .75rem 0 0; }
        .gio-checkout-form { margin: 0; }
        {{--
            The questions, as a grid rather than as a stack of margins. One
            column here and the gap where each field's own bottom margin used to
            be, so a form where nobody paired anything is spaced exactly as it
            always was.
        --}}
        .gio-checkout-fields { display: grid; grid-template-columns: 1fr; gap: .75rem; }
        {{--
            `min-width: 0` is what stops a paired question overflowing the island.

            A grid item's `min-width` is `auto`, which is its content's
            min-content width — and an input's is about twenty characters
            whatever width we give it. So two columns narrower than that would
            push the grid wider than the checkout and out of the element the
            merchant put the shortcode in, which is the one thing this island
            must never do. It is reachable: box the form at 20rem and open it on
            a desktop. Zero lets the column be as narrow as it is told, and the
            input's own `width: 100%` then fits it.
        --}}
        .gio-checkout-field { margin: 0; min-width: 0; }
        {{--
            Where a half-width question is half width, and the only place it is.

            This traffic is overwhelmingly phone-sized, so the second column
            exists above the breakpoint and nowhere else: below it the grid is
            one column and every question fills it, whatever the merchant chose.
            There is deliberately no control that undoes this — a merchant
            cannot be handed a switch whose only use is breaking the form for
            most of the people who will see it.

            The breakpoint is not a token. A custom property cannot be read in a
            media query prelude, and a per-page one would mean a generated rule
            — which is the thing this stylesheet exists not to do.

            `rem` here is 16px whatever the merchant's document says. Relative
            units in a media query resolve against the initial font size, not
            against the page's root, so a page setting `html { font-size: 10px }`
            cannot drag the breakpoint down onto a phone. Inside the rules below
            it, `rem` means what the document says, as everywhere else.

            Below the rule it narrows, both selectors one class, so the later
            declaration is the one that applies. Same order dependency as the
            quantity stepper, and raising either would spend the escape hatch a
            merchant's own stylesheet needs on ourselves.
        --}}
        @media (min-width: 30rem) { .gio-checkout-fields { grid-template-columns: 1fr 1fr; } .gio-checkout-field { grid-column: span 2; } .gio-checkout-field-half { grid-column: span 1; } }
        .gio-checkout-label { display: block; margin: 0 0 .25rem; font-size: .8125em; font-weight: 600; }
        .gio-checkout-quantity-label { margin: 0; }
        .gio-checkout-help { margin: 0 0 .375rem; font-size: .75em; line-height: 1.4; color: var(--gio-muted); }
        .gio-checkout-input { box-sizing: border-box; width: 100%; padding: .625em .75em; border: 1px solid var(--gio-border); border-radius: var(--gio-radius); font-family: inherit; font-size: 1em; background: var(--gio-surface); color: inherit; }
        .gio-checkout-input:focus { outline: 2px solid var(--gio-accent); outline-offset: -1px; }
        {{--
            The stepper is one bordered box holding three things: minus, the
            number, plus. The border and the radius are the wrapper's, so the
            box inside gives its own up — below the rule it narrows, and that
            is the whole of what makes it work: the box carries both classes,
            both selectors are one class, so the later declaration is the one
            that applies. Painted with the surface token like any box a
            customer types into.

            The number stays a real numeric input, and its native spinner is
            left alone. That spinner and the keyboard are the stepper a
            customer whose script never runs gets, and they honour the same
            bounds the buttons do — so a quantity is never blocked by the
            browser from being something the capture would have taken.

            The buttons carry no border of their own and no background, so a
            merchant's `--gio-border` restyles the whole control at once.
        --}}
        .gio-checkout-stepper { display: inline-flex; align-items: stretch; border: 1px solid var(--gio-border); border-radius: var(--gio-radius); background: var(--gio-surface); overflow: hidden; }
        .gio-checkout-step { flex: none; width: 2.25rem; padding: 0; border: 0; background: transparent; color: inherit; font-family: inherit; font-size: 1.125em; line-height: 1; cursor: pointer; }
        .gio-checkout-quantity-input { width: 3rem; padding: .625em .25em; border: 0; border-radius: 0; text-align: center; }
        .gio-checkout-delivery, .gio-checkout-choices { margin: 0; padding: 0; border: 0; }
        {{--
            A row a customer taps is a box like any other — bordered, filled
            with the surface token and rounded the same — because it is the
            same kind of thing: something a thumb has to hit.
        --}}
        .gio-checkout-choice { display: flex; gap: .625rem; align-items: center; padding: .625rem .75rem; border: 1px solid var(--gio-border); border-radius: var(--gio-radius); background: var(--gio-surface); font-size: .9375em; cursor: pointer; }
        .gio-checkout-choice + .gio-checkout-choice { margin: .375rem 0 0; }
        {{--
            The row that is picked reads as picked from across the room, not
            only from the dot inside its radio: the border takes the selected
            colour, a one-pixel ring outside it thickens the edge without
            moving the row, and the ground takes a light tint of it — the same
            three declarations, in the same token, as a picked product above.
            The token defaults to the accent, so a merchant who recoloured the
            checkout recoloured this too.

            `:has()` rather than a sibling selector, because the row is the
            label and the control sits inside it — there is no sibling to
            reach. A browser that cannot read it draws the row it always drew,
            with the radio still saying which one is chosen, so nothing is
            lost that was there before.
        --}}
        .gio-checkout-choice:has(:checked) { border-color: var(--gio-accent); box-shadow: 0 0 0 1px var(--gio-accent); background: color-mix(in srgb, var(--gio-accent) 8%, transparent); }
        {{--
            The words take what is left of the row, so the figure sits at the
            far end where a customer expects one — the same shape as a product
            row: control and name left, price right. `min-width: 0` lets a long
            label wrap rather than push the price out of the row, and the price
            itself never wraps, because a figure broken across two lines is a
            figure misread.
        --}}
        .gio-checkout-choice-label { flex: 1; min-width: 0; }
        .gio-checkout-choice-amount { font-weight: 700; white-space: nowrap; }
        {{--
            A refusal about one answer, beneath the box it is about, in a red
            that is not a token: what a refusal is written in is not something
            a merchant restyles, because the one place it must be readable is
            the moment the order did not go through.
        --}}
        .gio-checkout-error { display: none; margin: .25rem 0 0; font-size: .8125em; color: #b91c1c; }
        .gio-checkout-error[data-shown] { display: block; }
        .gio-checkout-quantity-error { flex: 1 0 100%; margin: 0; }
        {{--
            A refusal the customer cannot fix at a box, across the top of the
            island. Drawn as nothing until the script writes to it, so a page
            that fails nothing draws nothing and a visitor whose script never
            runs is told by the page the plain post lands on instead.

            Shown, it paints its own ground in the refusal red and writes on
            it in white. That is what makes it legible on a page styled dark
            before the merchant has touched a colour: the sentence saying the
            whole order was turned away cannot be the one thing on the page
            nobody can read. Both declarations sit on the shown state so the
            colour is written once, where it is painted.

            The reload button is drawn in the banner's own two colours swapped
            over. Its hidden state is stated outright rather than left to the
            `hidden` attribute's own styling, which any `button { display }`
            rule in the page's stylesheet would override — and a reload
            offered for a dropped connection throws away what the customer
            typed for nothing.
        --}}
        .gio-checkout-banner { display: none; margin: 0 0 1.25rem; }
        .gio-checkout-banner[data-shown] { display: flex; flex-wrap: wrap; gap: .625rem 1rem; align-items: center; justify-content: space-between; padding: .875rem 1rem; border-radius: var(--gio-radius); background: #b91c1c; color: #fff; }
        .gio-checkout-banner-text { flex: 1 1 14rem; margin: 0; font-size: .9375em; line-height: 1.45; color: inherit; }
        .gio-checkout-reload { flex: none; padding: .5em .875em; border: 0; border-radius: var(--gio-button-radius); background: #fff; color: #b91c1c; font-family: inherit; font-size: .875em; font-weight: 700; cursor: pointer; }
        .gio-checkout-reload[hidden] { display: none; }
        .gio-checkout-summary { margin: 1rem 0; padding: .75rem 0 0; border-top: 1px solid var(--gio-border); }
        {{--
            The summary's own size, moved down onto its rows. It sat on the
            container while the sizes were rem and nothing compounded; in `em`
            it would multiply against the total's, which carries both classes
            and is meant to be the larger figure, not a fraction of a fraction.
        --}}
        .gio-checkout-row { display: flex; justify-content: space-between; gap: 1rem; margin: 0 0 .375rem; font-size: .875em; }
        {{--
            The total is ruled off from the lines above it with the same
            hairline the sections are, and set heavier and larger than they
            are: a receipt a customer reads down to what the courier will
            collect. This rule is inside the summary and not between sections,
            so a layout that drops the panel's bottom rule leaves it alone.

            It carries both classes and both selectors are one class, so the
            later declaration is the one that applies — order, not
            specificity, as everywhere else in this sheet.
        --}}
        .gio-checkout-total { margin: .375rem 0 0; padding: .5rem 0 0; border-top: 1px solid var(--gio-border); font-size: 1.0625em; font-weight: 700; }
        .gio-checkout-submit { display: block; width: 100%; padding: .875rem 1rem; border: 0; border-radius: var(--gio-button-radius); background: var(--gio-button-background); color: var(--gio-button-text); font-family: inherit; font-size: 1.0625em; font-weight: 700; cursor: pointer; }
        .gio-checkout-submit[disabled] { opacity: .6; cursor: progress; }
        .gio-checkout-note { margin: .625rem 0 0; font-size: .75em; text-align: center; color: var(--gio-muted); }
        {{--
            Everything above is the base, and it is the same bytes whichever
            arrangement the merchant chose. This is the one block that is not:
            the rules the chosen layout adds, and nothing of the arrangements
            this page is not drawing (ADR-0009). It sits last so that what it
            narrows it narrows by order rather than by specificity, the same
            trade the half-width field and the quantity stepper make.

            Unescaped because it is a hand-written block picked from a fixed
            list, not a figure a merchant typed. The escaping above guards the
            one string here that is — a layout's rules are code, and a layout
            that wanted a merchant's value in them would be the generated rule
            this sheet exists not to write.

            Ahead of it on the same line, the one rule the payment chooser
            adds, and only on a page that draws a logo. An additive block on
            the layout's model: a page whose only method is cash on delivery
            with the choice hidden ships the sheet it always shipped, byte for
            byte — which is why it shares the layout's line and carries its own
            line break, so an absent block leaves no blank line behind. Before
            the layout's block, so the layout still sits last and may narrow it.
        --}}
        {!! $paymentChooser->styleRules() !!}{!! $layout->styleRules() !!}
    </style>

    {{--
        Where a refusal the customer cannot fix at a box is put: the page moved
        under them, the connection dropped, the server refused or fell over.
        At the top of the island rather than above the button, because a
        customer whose tap did nothing looks up, not down — and outside the
        form, because every layout arranges the form's children and a child of
        the island sits above whatever the arrangement draws.

        The reload button is for the one refusal it can put right: a page
        repriced or changed since these bytes were written. It stays hidden for
        the others, where reloading would only throw away what they typed for
        nothing. `aria-live` rather than a role, so a screen reader is told
        what was written into it and not what an empty box is for.
    --}}
    <div class="gio-checkout-banner" data-gio-banner aria-live="assertive">
        <p class="gio-checkout-banner-text" data-gio-error-for="page"></p>
        <button class="gio-checkout-reload" type="button" data-gio-reload hidden>Reload page</button>
    </div>

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
            What this form quoted the customer, as a figure the server can check
            back. These bytes may sit on disk for days, and a price or a delivery
            amount edited in the meantime means the total on screen is not the
            total the courier would collect — which in cash on delivery is a
            refused parcel and a fee in both directions.

            The capture refuses a submission whose fingerprint is not the page's
            current one, and tells the visitor to reload. Nothing about the
            questions or the styling is in it, so editing those never rejects an
            order somebody has half-typed (ADR-0007).
        --}}
        <input type="hidden" name="fingerprint" value="{{ $fingerprint }}">

        {{--
            Everything this Page sells, each with a quantity of its own.

            Quantities are keyed by Product id rather than by the position the
            Product sits at, so reordering them in the admin cannot point a form
            already open on someone's phone at a different item. A Product
            soft-deleted since these bytes were written is simply absent from
            the Page by the time the Capture is checked, and the rest still sell.

            Drawn only where the Product List is shown, on whichever layout the
            page is arranged in. Where it is hidden, the quantities are still
            posted — one of everything the page sells — so a checkout with the
            list hidden captures exactly the Order one showing it would.
        --}}
        @if ($showProducts)
            <div class="gio-checkout-products">
                @foreach ($products as $product)
                    <div class="gio-checkout-product{{ $picksProducts ? ' gio-checkout-pickable' : '' }}"
                         data-gio-line data-unit-price="{{ $product->price }}">
                        @if ($picksProducts)
                            {{--
                                Ticked where the merchant said so, and unticked
                                otherwise, which is what a page says nothing about.
                                A tick is a starting position and never an
                                agreement: the server charges for what arrives
                                ticked, so a customer who unticks one before
                                ordering is charged for what is left.

                                One ticked is a price the customer meets on load
                                and then changes. Two or more is a bundle the
                                merchant is asserting, and on a cash-on-delivery
                                page it carries the risk the empty default existed
                                to avoid — an extra nobody chose is a refused
                                parcel and a courier fee in both directions. The
                                Checkout tab warns them there; here it is honoured
                                (ADR-0010).

                                Labelled by the product's own name, so a screen
                                reader hears what is being ticked rather than
                                "checkbox" three times.
                            --}}
                            <input class="gio-checkout-pick"
                                   id="gio-pick-{{ $product->getKey() }}"
                                   name="selected[]"
                                   value="{{ $product->getKey() }}"
                                   aria-labelledby="gio-product-{{ $product->getKey() }}"
                                   type="checkbox" data-gio-pick
                                   @checked($opensTicked($product))>
                        @endif

                        {{-- The `for` carries its own leading space, so a page that asks nothing renders the tag it always did. --}}
                        <{{ $productHead }} class="gio-checkout-product-head"@if ($picksProducts) for="gio-pick-{{ $product->getKey() }}"@endif>
                            @if ($image = $product->imageUrl())
                                <img class="gio-checkout-image" src="{{ $image }}" alt="{{ $product->name }}" loading="lazy">
                            @endif

                            <p class="gio-checkout-name" id="gio-product-{{ $product->getKey() }}">{{ $product->name }}</p>
                            <p class="gio-checkout-price">{{ \App\Support\Money::format($product->price) }}</p>
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
                            <label class="gio-checkout-label gio-checkout-quantity-label"
                                   id="gio-quantity-label-{{ $product->getKey() }}"
                                   for="gio-quantity-{{ $product->getKey() }}">Quantity</label>
                            {{--
                                Minus and plus flanking a real numeric input, and
                                the input is the control. Its bounds are what keep
                                the browser and the capture agreed on the largest
                                quantity, and typing into it is how a customer gets
                                from one to twelve without eleven taps — a readout
                                that could not be typed into was rejected for both
                                reasons. The buttons only step it, by the step it
                                declares, and do nothing at all until the script
                                runs; the box works without them.

                                `type="button"`, because a button in a form
                                submits it unless told otherwise, and a tap on
                                plus must never place an order. Hidden from the
                                accessibility tree and skipped by the keyboard:
                                the spinbutton they step already has arrow keys
                                and a name, and three stops per product would
                                make a page selling five things fifteen stops
                                long.
                            --}}
                            <div class="gio-checkout-stepper">
                                <button class="gio-checkout-step" type="button" data-gio-step="-1" aria-hidden="true" tabindex="-1">&minus;</button>
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
                                <button class="gio-checkout-step" type="button" data-gio-step="1" aria-hidden="true" tabindex="-1">+</button>
                            </div>
                            <p class="gio-checkout-error gio-checkout-quantity-error" data-gio-error-for="quantities.{{ $product->getKey() }}"></p>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            {{--
                The whole product panel, minus everything a customer looks at.

                A page hiding its Product List sold the product in its own
                markup above the fold, so drawing it again in smaller type
                would be the page arguing with itself. What is left is what an
                Order cannot be captured without: one quantity per product,
                keyed by product id like the stepper it stands in for, so the
                Capture checks and writes exactly what it would from a form
                that drew the list.

                One of each, and no way to say otherwise. There is no stepper to
                type into and no box to tick — which is also why a checkout
                with the list hidden never asks the customer to choose, and why
                the switch is read where that question is answered
                (`picksProducts()`).

                They carry the line markers too, so the summary below totals the
                same way in every layout rather than the script needing to know
                which one it is looking at.
            --}}
            @foreach ($products as $product)
                <input type="hidden" name="quantities[{{ $product->getKey() }}]" value="1"
                       data-gio-line data-unit-price="{{ $product->price }}">
            @endforeach
        @endif

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

            The grid they sit in is what lets two short questions share a row,
            and it holds the delivery choice as well: that is a question the
            page asks like any other, and it should be spaced like one.
        --}}
        <div class="gio-checkout-fields">
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

                    How wide it sits is a flat class the sheet above already
                    carries, and it says nothing about phones: the class only
                    means anything above the breakpoint. Blank for a full-width
                    question, so a page where nobody paired anything renders the
                    markup it always did.
                --}}
                <div class="gio-checkout-field{{ $field->width->classSuffix() }}">
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
@if ($paymentChooser->isDrawn())
                {{--
                    At column 0, and so is its @endif: Blade swallows the
                    newline after a directive but not the indent before it, so
                    an indented @if leaves a line of spaces in the bytes of
                    every page that draws no chooser. Written flush left, a
                    page with a default Config emits exactly what it did
                    before a method could be chosen.

                    How the customer will pay, in the merchant's order. Drawn
                    only where there is a decision worth putting in front of
                    them: a page offering nothing but cash on delivery with the
                    choice hidden draws the form it always did and posts no
                    method, and the server places the order under cash on
                    delivery. The comment sits inside the branch for the same
                    reason, so a page drawing no chooser emits no line for it.

                    Radios like the delivery choice above, and drawn as the
                    same row: the control, then the logo where the page shows
                    them, then the name. The first is already picked so a page
                    with one preferred method needs no extra tap, and so a
                    plain form post with no script carries a method
                    regardless. The server refuses a name the page never
                    listed.

                    The logo is decorative beside a name that says the same
                    thing, so its alt is empty and a screen reader hears the
                    method once.
                --}}
                <div class="gio-checkout-field">
                    <fieldset class="gio-checkout-choices gio-checkout-payment">
                        <legend class="gio-checkout-label">Payment</legend>

                        @foreach ($paymentChooser->methods as $index => $method)
                            <label class="gio-checkout-choice">
                                <input type="radio"
                                       name="payment_method"
                                       value="{{ $method->value }}"
                                       @checked($index === 0) required>
                                @if ($paymentChooser->showsLogos)
                                    <img class="gio-checkout-logo" src="{{ $method->logoUrl() }}" alt="" loading="lazy">
                                @endif
                                <span class="gio-checkout-choice-label">{{ $words->paymentLabel($method) }}</span>
                            </label>
                        @endforeach
                    </fieldset>

                    <p class="gio-checkout-error" data-gio-error-for="payment_method"></p>
                </div>
@endif
        </div>

        {{--
            One of everything, which is what the quantity boxes above start on,
            plus the delivery arrangement that is already selected. The script
            keeps these truthful as the visitor changes them; a visitor whose
            scripts never run reads the figures the page was rendered with, and
            the Order Complete Page after Capture is where they see what they
            actually owe.

            Where the customer picks, only what the form opens ticked: totalling
            the whole shelf would quote a figure no order could ever come to,
            and totalling nothing where a merchant has pre-ticked would put a
            zero beside boxes that are visibly ticked. Read through the same
            test the boxes were drawn from, so the figure and the ticks cannot
            disagree. The script retotals on load and on every touch after it,
            but a visitor whose scripts never run reads these bytes and orders
            from them, which is what this figure has to be right for.
        --}}
        @php($subtotal = (int) ($picksProducts ? $products->filter($opensTicked) : $products)->sum('price'))
        @php($total = $subtotal + ($preselected?->amount ?? 0))

        {{--
            Three rows in the merchant's words or the design's. The delivery
            row is labelled as a charge rather than after the arrangement:
            the arrangement's own name is on the radio above, where it is
            chosen, and the row beneath it is what that choice costs. Nothing
            at all where the page charges for no delivery.
        --}}
        <div class="gio-checkout-summary">
            <p class="gio-checkout-row">
                <span>{{ $words->subtotalLabel() }}</span>
                <span data-gio-subtotal>{{ \App\Support\Money::format($subtotal) }}</span>
            </p>

            @if ($preselected !== null)
                <p class="gio-checkout-row">
                    <span>{{ $words->deliveryLabel() }}</span>
                    <span data-gio-delivery>{{ \App\Support\Money::format($preselected->amount) }}</span>
                </p>
            @endif

            <p class="gio-checkout-row gio-checkout-total">
                <span>{{ $words->totalLabel() }}</span>
                <span data-gio-total>{{ \App\Support\Money::format($total) }}</span>
            </p>
        </div>

        {{--
            The merchant's words, or the design's. Where the wording names the
            total it is filled in here from the same figure the summary shows,
            so a visitor whose scripts never run reads a price and never a
            placeholder; the template then rides on the button for the script,
            which refills it every time it retotals. No template where nothing
            is named, so the script leaves a plain button alone.
        --}}
        <button class="gio-checkout-submit" type="submit" data-gio-submit{!! $words->orderButton->carriesTotal() ? ' data-gio-submit-wording="'.e($words->orderButton->wording()).'"' : '' !!}>{{ $words->orderButton->wordingFor(\App\Support\Money::format($total)) }}</button>

        <p class="gio-checkout-note">{{ $words->orderButton->note() }}</p>
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

    /*
     * What the merchant writes into the button's wording to put the total on
     * it, spelled the one way the Checkout tab documents. The server has
     * already filled it in once at render; this is what the script refills.
     */
    var TOTAL_PLACEHOLDER = '{total}';

    /*
     * One sentence per way an order can fail to arrive, each saying what
     * actually happened. A customer whose phone dropped off the network and
     * one who tapped too many times are in different situations, and telling
     * both to "try again" in the same words helps neither.
     */
    var CONNECTION_LOST = 'We could not reach the server. Please check your connection and try again.';
    var TOO_MANY_ATTEMPTS = 'Too many attempts just now. Please wait a moment and try again.';
    var SERVER_FAILED = 'Something went wrong placing your order. Please try again.';

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
            return { amount: parseInt(picked.dataset.amount, 10) || 0 };
        }

        return { amount: parseInt(form.dataset.deliveryAmount, 10) || 0 };
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

        var total = money(form, subtotal + delivery.amount);

        set('subtotal', money(form, subtotal));
        set('delivery', money(form, delivery.amount));
        set('total', total);

        /*
         * The button carries the same figure where the merchant put it there.
         * Left alone while an order is going out: the button is saying so, and
         * a retotal in flight must not talk over it.
         */
        var button = form.querySelector('[data-gio-submit][data-gio-submit-wording]');

        if (button && !button.disabled) {
            button.textContent = button.dataset.gioSubmitWording.split(TOTAL_PLACEHOLDER).join(total);
        }
    }

    /*
     * The island the form sits in, which is what the error slots are looked
     * up in: the banner is a child of the island and not of the form, so that
     * no layout has to make room for it.
     */
    function islandFor(form) {
        return form.closest('[data-gio-checkout]');
    }

    /* The three parts of the whole-form banner, which always travel together. */
    function bannerFor(form) {
        var island = islandFor(form);

        return {
            box: island.querySelector('[data-gio-banner]'),
            text: island.querySelector('[data-gio-error-for="page"]'),
            reload: island.querySelector('[data-gio-reload]')
        };
    }

    function clearErrors(form) {
        islandFor(form).querySelectorAll('[data-gio-error-for]').forEach(function (el) {
            el.textContent = '';
            el.removeAttribute('data-shown');
        });

        var banner = bannerFor(form);

        if (banner.box) { banner.box.removeAttribute('data-shown'); }
        if (banner.reload) { banner.reload.hidden = true; }
    }

    function slotFor(form, field) {
        return islandFor(form).querySelector('[data-gio-error-for="' + field + '"]');
    }

    /*
     * Write one message into one slot, and say whether it was taken. One slot,
     * one message: whatever reaches it first stands.
     */
    function fill(el, message) {
        if (!el || el.hasAttribute('data-shown')) { return false; }
        el.textContent = message;
        el.setAttribute('data-shown', '');

        return true;
    }

    /*
     * Open the banner, with or without the way back, *before* anything is
     * written into it: a live region only announces what changes while it is
     * on the page, and the banner is drawn as nothing until this.
     */
    function openBanner(form, offerReload) {
        var banner = bannerFor(form);

        if (banner.box) { banner.box.setAttribute('data-shown', ''); }
        if (banner.reload) { banner.reload.hidden = !offerReload; }

        return banner.text;
    }

    /*
     * A refusal about one answer goes beside that answer; anything the server
     * refused that has no box goes in the banner across the top. The server's
     * page-level key and any key this form never drew both mean the page
     * moved under the customer, so both come with the reload button — it is
     * the one failure a reload puts right. A failure of the connection or of
     * the server is passed to `showFailure` instead, which offers no reload:
     * what they typed is still good.
     */
    function showErrors(form, errors) {
        errors = errors || {};

        var first = null;
        var fields = Object.keys(errors);
        var message = function (field) { return [].concat(errors[field])[0]; };

        /*
         * The server's own page-level refusal first, so it can never lose the
         * banner to a refusal keyed to something this form never asked for —
         * a field the merchant switched off after these bytes were written to
         * disk. That one falls back to the banner too, because a visitor whose
         * order was refused has to be told something; discarding it leaves
         * them tapping a button that re-enables and says nothing.
         */
        var boxless = fields.filter(function (field) { return field !== 'page' && !slotFor(form, field); });

        if (errors.page || boxless.length) {
            var text = openBanner(form, true);

            ['page'].concat(boxless).forEach(function (field) {
                if (errors[field] && fill(text, message(field)) && !first) { first = text; }
            });
        }

        fields.forEach(function (field) {
            if (field !== 'page' && fill(slotFor(form, field), message(field)) && !first) { first = slotFor(form, field); }
        });

        if (first) { first.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
    }

    /* A failure that is nobody's answer to fix, worded for what went wrong and offering no reload. */
    function showFailure(form, message) {
        var text = openBanner(form, false);

        if (fill(text, message)) { text.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
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

    /*
     * The plus and minus beside a quantity step the box between them, and
     * nothing more: `stepUp` and `stepDown` read the min, the max and the step
     * the markup declares, so the buttons honour exactly the bounds the box
     * does and a limit is written once. An `input` event follows, because a
     * value set from script fires none and the total above would go stale.
     */
    document.addEventListener('click', function (event) {
        var form = formFor(event);
        if (!form) { return; }

        var step = event.target.closest ? event.target.closest('[data-gio-step]') : null;
        if (!step) { return; }

        var box = step.parentNode.querySelector('[data-gio-quantity]');
        if (!box) { return; }

        if (parseInt(step.dataset.gioStep, 10) < 0) { box.stepDown(); } else { box.stepUp(); }

        box.dispatchEvent(new Event('input', { bubbles: true }));
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
                showFailure(form, TOO_MANY_ATTEMPTS);
                restore();
                return;
            }

            showFailure(form, SERVER_FAILED);
            restore();
        };

        post(form, null)
            .then(function (response) { return handle(response, true); })
            .catch(function () {
                showFailure(form, CONNECTION_LOST);
                restore();
            });
    });

    /*
     * The way back from a page that moved under the customer: a fresh copy of
     * it, with the current prices in it. Delegated like everything else, and
     * from the island rather than the form, because the banner is the
     * island's child.
     */
    document.addEventListener('click', function (event) {
        var reload = event.target.closest ? event.target.closest('[data-gio-reload]') : null;
        if (!reload || !reload.closest('[data-gio-checkout]')) { return; }

        window.location.reload();
    });

    document.querySelectorAll('[data-gio-checkout-form]').forEach(retotal);
})();
</script>
