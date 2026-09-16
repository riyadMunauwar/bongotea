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

    One template for all ten layouts, and the document is written in the same
    order for every one of them: the products, the questions with the delivery
    choice, the payment choice, the summary, the button (ADR-0015). What a
    layout changes it changes in the sheet, and the only branches here are the
    layout's own predicates — which of its words it draws, whether a delivery
    detail line fits, which payment label it names a method by.
--}}
@use(App\Support\Checkout\CheckoutFieldType)
@use(App\Support\Checkout\WordKind)
@use(App\Support\Checkout\WordPlace)
@use(App\Support\Checkout\WordSlot)
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

    /**
     * The button in the layout's own words where the merchant has said
     * nothing: several designs put the live total on it.
     */
    $button = $words->orderButton->forLayout($layout);

    /**
     * The numbered sections, counted as they are drawn. The products may be
     * hidden and the payment choice may not be drawn, so the numbers follow
     * what is on the page rather than the design's fixed three: a customer
     * never reads a "2" with no "1" above it.
     */
    $sectionNumber = 0;

    /**
     * The figures a layout's own words may name, each wrapped so the script
     * can keep it truthful: how many products open ticked (every product,
     * where there is no picker), and what the delivery arrangement the form
     * opens on costs. Substituted into the escaped words, so a merchant's
     * sentence is escaped and the figure's markup is not.
     */
    $count = $picksProducts ? $products->filter($opensTicked)->count() : $products->count();

    $figures = [
        WordSlot::COUNT_PLACEHOLDER => '<span data-gio-count>'.$count.'</span>',
        WordSlot::DELIVERY_PLACEHOLDER => '<span data-gio-delivery>'.e(\App\Support\Money::format($preselected?->amount ?? 0)).'</span>',
    ];

    $sentenceFor = fn (WordSlot $slot): string => strtr(e($words->slot($slot)), $figures);

    /**
     * The word the layout draws at one place on the form, in the markup its
     * kind calls for, or nothing where the layout draws none there. The
     * view knows where it stands and asks the layout what goes there, so a
     * layout with words of its own adds no branch here; and the badge on a
     * numbered headline counts as it is drawn.
     */
    $wordAt = function (WordPlace $place) use ($layout, $sentenceFor, &$sectionNumber): string {
        $slot = $layout->slotAt($place);

        if ($slot === null) {
            return '';
        }

        return match ($slot->kind()) {
            WordKind::NumberedHeadline => '<h3 class="gio-checkout-heading"><span class="gio-checkout-number">'.(++$sectionNumber).'</span>'.$sentenceFor($slot).'</h3>',
            WordKind::Headline => '<h3 class="gio-checkout-heading">'.$sentenceFor($slot).'</h3>',
            WordKind::Eyebrow => '<p class="gio-checkout-eyebrow">'.$sentenceFor($slot).'</p>',
            WordKind::Count => '<p class="gio-checkout-count">'.$sentenceFor($slot).'</p>',
            WordKind::ColumnHeading => '<span class="gio-checkout-column-heading">'.$sentenceFor($slot).'</span>',
            WordKind::EmptyState => '<p class="gio-checkout-empty">'.$sentenceFor($slot).'</p>',
            WordKind::Line => '<p class="gio-checkout-footnote">'.$sentenceFor($slot).'</p>',
            /**
             * Never reached through `slotAt()`: a control's word has no place
             * on the form, because the control it sits on is not in the
             * document at all. The arm is what keeps the match total.
             */
            WordKind::Control => '',
        };
    };

    /**
     * The words a behaving layout's script draws on the chrome it builds —
     * the steps in a stepper's rail, its Next, its Back — handed over on the
     * form rather than written into the document.
     *
     * Without script there is no rail and no Next for a word to sit on, so
     * putting either in the markup would be a dead button on a form that
     * still has to take an order (ADR-0015). This rides on the form the way
     * the button's own `{total}` template does, and is nothing at all on the
     * eight arrangements that only arrange.
     */
    $cues = '';

    foreach ($layout->wordSlots() as $slot) {
        if ($slot->kind() === WordKind::Control) {
            $cues .= ' data-gio-cue-'.str_replace('_', '-', $slot->value).'="'.e($words->slot($slot)).'"';
        }
    }

    /**
     * The ground the island draws itself on: the design's outer padding and the
     * background token's fill, or none at all where the merchant has dissolved
     * the Container so the checkout sits flush inside their own wrapper. The
     * card inside is untouched either way.
     *
     * Written here as the design's figures rather than declared as a token,
     * because turning the ground off is structural — the section is painted and
     * padded or it is not — and a token is a colour a flat rule reads (ADR-0027).
     * A default Config keeps the ground on, so a page nobody has touched emits
     * exactly the bytes it always did.
     */
    $ground = $container->enabled
        ? 'padding: '.$container->ground->css().'; background: var(--gio-background);'
        : 'padding: 0; background: transparent;';

    /**
     * The phone tightens each box's padding, but only where the box is still at
     * the design's figure: there is no phone padding control, so a Merchant who
     * set their own gets it at every width rather than losing it below the
     * breakpoint — which is also what keeps a card padded to 0 for the flush
     * recipe from being re-padded on a phone. With the Container off there is no
     * ground to narrow at all. Each carries its own trailing space, so a page at
     * the design's figures emits the media query byte-for-byte as it always did.
     */
    $groundOnPhone = $container->groundTightensOnPhone() ? '.gio-checkout { padding: 1.25rem 1rem; } ' : '';
    $cardOnPhone = $container->cardTightensOnPhone() ? '.gio-checkout-card { padding: 1.25rem 1rem; } ' : '';
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
            style attribute. Everything that is not a token — how wide the card
            sits, one-pixel borders, the type scale — is the design's figure,
            written here from the handoff README.
        --}}
        {{--
            The root is the section the design paints behind the card: the
            background token's ground, and the design's padding around it. The
            card inside is what is bordered and filled — a white card at the
            design's width with the design's padding — and everything a
            customer reads is inside it. Sizes are in rem so the scale is the
            design's pixels at the browser's default and grows with a page
            that sets its own root size.

            `font-family` reads the token, and `inherit` in the token still
            means the page's face: a custom property set to `inherit` takes the
            parent's `--gio-font`, which nothing declares, so the `var()` is
            invalid at computed-value time and `font-family` — an inherited
            property — falls back to inheriting. Sound for any inherited
            property; do not move the read onto one that is not.
        --}}
        .gio-checkout { {{ $styleTokens->declarations() }} box-sizing: border-box; {!! $ground !!} font-family: var(--gio-font); font-size: .875rem; line-height: 1.4; color: var(--gio-text); accent-color: var(--gio-accent); }
        .gio-checkout-card { box-sizing: border-box; max-width: 35rem; margin: 0 auto; padding: {{ $container->card->css() }}; border: 1px solid var(--gio-border); background: var(--gio-surface); }
        .gio-checkout-form { margin: 0; }
        {{--
            The numbered sections the design draws, set apart by whitespace
            alone: a badge in the text colour with the number in the surface
            colour, and the layout's own headline beside it. The heading is
            drawn only where the layout owns the words for it, but the rules
            are in the base whatever the layout, so the sheet is the same bytes
            whichever words a page draws.
        --}}
        .gio-checkout-section { margin: 0; }
        .gio-checkout-section + .gio-checkout-section { margin: 1.75rem 0 0; }
        {{--
            Everything the customer answers — the questions with the delivery
            choice beneath them, and the payment choice — as one block, so a
            two-column arrangement can put it on one side of the form in one
            move. Spaced from the products above it the way the sections are
            spaced from each other; nothing else here, so on a one-column
            page it is the same island it always was.
        --}}
        .gio-checkout-questions { margin: 0; }
        .gio-checkout-section + .gio-checkout-questions { margin: 1.75rem 0 0; }
        .gio-checkout-heading { display: flex; align-items: center; gap: .625rem; margin: 0 0 .875rem; font-size: 1rem; font-weight: 700; line-height: 1.3; }
        .gio-checkout-number { display: grid; place-items: center; flex: none; width: 1.5rem; height: 1.5rem; border-radius: 50%; background: var(--gio-text); color: var(--gio-surface); font-size: .75rem; font-weight: 700; }
        {{--
            A headline drawn over the delivery choice sits after the questions
            in the same section, and takes the design's section gap above it.
            In the base rather than in the block of the one layout drawing
            such a headline, so a layout's block never has to reach three
            classes deep to space it.
        --}}
        .gio-checkout-fields + .gio-checkout-heading { margin-top: 1.5rem; }
        {{--
            An eyebrow is the design's small capitals over a block, in the
            muted ink: a layout's own word where the design draws one rather
            than a headline. In the base whether or not the layout draws one,
            so the sheet is the same bytes whichever words a page draws.
        --}}
        .gio-checkout-eyebrow { margin: 0 0 .75rem; font-size: .75rem; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; line-height: 1.4; color: var(--gio-muted); }
        {{--
            An eyebrow drawn over the delivery choice sits after the questions
            in the same section and takes the section gap above it, as a
            headline there does. A count is the small muted line at the far
            end of the row a title sits on, naming how many products are
            picked; the layout drawing one sets the two on one row.
        --}}
        .gio-checkout-fields + .gio-checkout-eyebrow { margin-top: 1.5rem; }
        .gio-checkout-count { margin: 0 0 .75rem; font-size: .75rem; line-height: 1.4; text-align: right; color: var(--gio-muted); }
        {{--
            The heading over one column of a product list drawn as a table,
            in the design's small capitals. Which cell it takes is the
            layout's, because only a layout drawing the list as a table has
            columns to head; the words and the ink are the base's, so the
            sheet is the same bytes whichever words a page draws.
        --}}
        .gio-checkout-column-heading { margin: 0; font-size: .6875rem; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; line-height: 1.4; color: var(--gio-muted); }
        {{--
            A product row is one line a thumb can read: the tick where there is
            one, the picture, the name with its price beneath, the stepper, and
            the line total at the far end. Boxed with the border token and
            filled from the surface token like every box a customer touches,
            and square-cornered as the design draws every row. The row wraps,
            which is the whole of what happens when a quantity is refused: the
            message takes a line of its own beneath.
        --}}
        .gio-checkout-products { display: grid; gap: .5rem; margin: 0; }
        .gio-checkout-product { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; padding: .75rem; border: 1px solid var(--gio-border); background: var(--gio-surface); transition: border-color .15s, background .15s; }
        .gio-checkout-pick { flex: none; width: 1rem; height: 1rem; margin: 0; }
        .gio-checkout-pickable .gio-checkout-product-head { cursor: pointer; }
        {{--
            A picked product reads as picked from across the room, the same way
            a chosen delivery row does and in the same token: the border takes
            the accent and the ground takes a light tint of it, mixed from the
            one token rather than being a second colour, so a merchant changes
            what "picked" looks like in one box. On a cash-on-delivery page an
            item nobody meant to choose is a refused parcel and courier fees
            both ways, so this is the state a customer most needs to be able
            to tell at a glance.

            `:has()` because the tick is a sibling of the head, not of the row,
            and only a box inside a product row can be checked. A browser that
            cannot read it, or cannot mix a colour, draws the row it always
            drew with the tick still saying which is picked.
        --}}
        .gio-checkout-product:has(:checked) { border-color: var(--gio-accent); background: color-mix(in srgb, var(--gio-accent) 5%, var(--gio-surface)); }
        {{--
            The whole point of the picker: five products on a phone are five
            lines of text until they are wanted, rather than five steppers for
            items nobody chose, and the line total of an item not chosen fades
            as the design draws it. Sibling selectors rather than :has(), so
            the oldest webview that can render the form can also hide the
            stepper.
        --}}
        .gio-checkout-pick:not(:checked) ~ .gio-checkout-quantity { display: none; }
        .gio-checkout-pick:not(:checked) ~ .gio-checkout-line-total { opacity: .45; }
        {{--
            The line a layout draws beneath an empty list: nothing until a
            list that can be ticked has nothing ticked, and then the layout's
            own words in the muted ink. Gated on a tick box being there at
            all, because a list with no picker has nothing to untick and
            `:not(:has(:checked))` would otherwise be true of it forever. In
            the base whether or not the layout draws one, so the sheet is
            the same bytes whichever words a page draws.
        --}}
        .gio-checkout-empty { display: none; margin: 0; font-size: .8125rem; line-height: 1.4; text-align: center; color: var(--gio-muted); }
        .gio-checkout-products:has(.gio-checkout-pick):not(:has(:checked)) + .gio-checkout-empty { display: block; }
        .gio-checkout-product-head { display: flex; flex: 1; min-width: 0; gap: .75rem; align-items: center; }
        .gio-checkout-image { flex: none; width: 2.75rem; height: 2.75rem; object-fit: cover; border: 1px solid var(--gio-border); border-radius: var(--gio-radius); }
        .gio-checkout-product-words { flex: 1; min-width: 0; }
        .gio-checkout-name { margin: 0; font-size: .875rem; font-weight: 600; line-height: 1.3; }
        .gio-checkout-description { margin: .125rem 0 0; font-size: .75rem; line-height: 1.4; color: var(--gio-muted); display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; line-clamp: 2; overflow: hidden; }
        .gio-checkout-price { margin: .125rem 0 0; font-size: .75rem; line-height: 1.4; color: var(--gio-muted); white-space: nowrap; }
        .gio-checkout-quantity { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem .75rem; margin: 0; }
        .gio-checkout-line-total { flex: none; width: 3.75rem; margin: 0; font-size: .875rem; font-weight: 600; text-align: right; white-space: nowrap; }
        {{--
            The questions, as a grid rather than as a stack of margins, holding
            the delivery choice beneath them as the design draws it.
        --}}
        .gio-checkout-fields { display: grid; grid-template-columns: 1fr; gap: .875rem; }
        {{--
            `min-width: 0` is what stops a paired question overflowing the island.

            A grid item's `min-width` is `auto`, which is its content's
            min-content width — and an input's is about twenty characters
            whatever width we give it. So two columns narrower than that would
            push the grid wider than the checkout and out of the element the
            merchant put the shortcode in, which is the one thing this island
            must never do. Zero lets the column be as narrow as it is told, and
            the input's own `width: 100%` then fits it.
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
            cannot drag the breakpoint down onto a phone.

            Below the rule it narrows, both selectors one class, so the later
            declaration is the one that applies. Same order dependency as the
            quantity stepper, and raising either would spend the escape hatch a
            merchant's own stylesheet needs on ourselves.
        --}}
        @media (min-width: 30rem) { .gio-checkout-fields { grid-template-columns: 1fr 1fr; } .gio-checkout-field { grid-column: span 2; } .gio-checkout-field-half { grid-column: span 1; } }
        {{--
            A label is the design's secondary ink: between the text and the
            muted token, mixed from the two so a merchant recolouring either
            moves it with them rather than leaving a third colour behind.
        --}}
        .gio-checkout-label { display: block; margin: 0 0 .375rem; font-size: .75rem; font-weight: 600; line-height: 1.3; color: color-mix(in srgb, var(--gio-text) 35%, var(--gio-muted)); }
        {{--
            A label a screen reader hears and a sighted customer does not: the
            word "Quantity" beside a stepper that already reads as one, and the
            word "Delivery" over two priced boxes that already read as the
            choice. Clipped rather than hidden, so it stays in the
            accessibility tree and stays what a voice-control user says.
        --}}
        .gio-checkout-hidden-label { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; }
        .gio-checkout-help { margin: -.125rem 0 .375rem; font-size: .75rem; line-height: 1.4; color: var(--gio-muted); }
        {{--
            The boxes a customer types into, at the design's padding and type
            size, bordered and filled from the tokens and rounded by the radius
            token. Focus is the design's: the accent on the border and a soft
            ring mixed from it, in place of the browser's outline.
        --}}
        .gio-checkout-input { box-sizing: border-box; width: 100%; padding: .6875rem .75rem; border: 1px solid var(--gio-border); border-radius: var(--gio-radius); background: var(--gio-surface); color: inherit; font-family: inherit; font-size: .875rem; line-height: 1.4; resize: vertical; transition: border-color .15s, box-shadow .15s; }
        .gio-checkout-input::placeholder { color: color-mix(in srgb, var(--gio-muted) 70%, var(--gio-surface)); opacity: 1; }
        .gio-checkout-input:focus { outline: 0; border-color: var(--gio-accent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--gio-accent) 15%, transparent); }
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
        .gio-checkout-quantity-stepper { display: inline-flex; align-items: stretch; border: 1px solid var(--gio-border); border-radius: var(--gio-radius); background: var(--gio-surface); overflow: hidden; }
        .gio-checkout-quantity-step { flex: none; width: 1.875rem; height: 1.875rem; padding: 0; border: 0; background: transparent; color: inherit; font-family: inherit; font-size: 1rem; line-height: 1; cursor: pointer; }
        .gio-checkout-quantity-input { width: 1.625rem; padding: 0; border: 0; border-radius: 0; font-size: .8125rem; font-weight: 600; text-align: center; }
        {{--
            The delivery choice is two boxes side by side beneath the
            questions, each taking half the row and wrapping to a row of its
            own when the card is narrow; a radio question and the payment
            choice stack theirs. The rows themselves are one rule: a flex row
            a thumb can hit anywhere, bordered and filled from the tokens,
            square as the design draws every row. The `flex` a delivery row
            carries is inert in the grids the other groups draw.
        --}}
        .gio-checkout-delivery { display: flex; flex-wrap: wrap; gap: .625rem; margin: .875rem 0 0; padding: 0; border: 0; min-width: 0; }
        .gio-checkout-choices { display: grid; gap: .5rem; margin: 0; padding: 0; border: 0; min-width: 0; }
        .gio-checkout-choice { display: flex; flex: 1 1 12.5rem; gap: .625rem; align-items: center; padding: .75rem .875rem; border: 1px solid var(--gio-border); background: var(--gio-surface); font-size: .875rem; cursor: pointer; transition: border-color .15s; }
        {{--
            The row that is picked takes the accent on its border, as a picked
            product does. `:has()` rather than a sibling selector, because the
            row is the label and the control sits inside it — there is no
            sibling to reach. A browser that cannot read it draws the row it
            always drew, with the radio still saying which one is chosen.
        --}}
        .gio-checkout-choice:has(:checked) { border-color: var(--gio-accent); }
        .gio-checkout-tick { flex: none; margin: 0; }
        {{--
            The words take what is left of the row, so the figure sits at the
            far end where a customer expects one — the same shape as a product
            row: control and name left, price right. `min-width: 0` lets a long
            label wrap rather than push the price out of the row, and the price
            itself never wraps, because a figure broken across two lines is a
            figure misread.
        --}}
        .gio-checkout-choice-label { flex: 1; min-width: 0; }
        {{--
            A delivery option's detail line: on its own line beneath the name,
            smaller, and receding in the muted token the way every help line
            does. In the base sheet whether or not any option carries one, so
            a page with a detail costs the same bytes as a page without.
        --}}
        .gio-checkout-choice-detail { display: block; margin: .125rem 0 0; font-size: .75rem; line-height: 1.4; color: var(--gio-muted); }
        .gio-checkout-choice-amount { font-weight: 700; white-space: nowrap; }
        {{--
            A refusal about one answer, beneath the box it is about, in a red
            that is not a token: what a refusal is written in is not something
            a merchant restyles, because the one place it must be readable is
            the moment the order did not go through.
        --}}
        .gio-checkout-error { display: none; margin: .375rem 0 0; font-size: .75rem; color: #b91c1c; }
        .gio-checkout-error[data-shown] { display: block; }
        .gio-checkout-quantity-error { flex: 1 0 100%; margin: 0; }
        {{--
            A refusal the customer cannot fix at a box, across the top of the
            card. Drawn as nothing until the script writes to it, so a page
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
        .gio-checkout-banner { display: none; margin: 0 0 1.75rem; }
        .gio-checkout-banner[data-shown] { display: flex; flex-wrap: wrap; gap: .625rem 1rem; align-items: center; justify-content: space-between; padding: .875rem 1rem; background: #b91c1c; color: #fff; }
        .gio-checkout-banner-text { flex: 1 1 14rem; margin: 0; font-size: .9375rem; line-height: 1.45; color: inherit; }
        .gio-checkout-reload { flex: none; padding: .5em .875em; border: 0; border-radius: var(--gio-button-radius); background: #fff; color: #b91c1c; font-family: inherit; font-size: .875rem; font-weight: 700; cursor: pointer; }
        .gio-checkout-reload[hidden] { display: none; }
        {{--
            The footer the design rules off from the sections above it: the
            summary's rows in the secondary ink, the total ruled off again and
            set large in the text colour, and the button beneath. The total's
            rule sits after the row's in the sheet, at one class each, so it
            narrows by order — the same trade the whole sheet makes.
        --}}
        .gio-checkout-summary { display: grid; gap: .5rem; margin: 1.75rem 0 0; padding: 1.125rem 0 0; border-top: 1px solid var(--gio-border); }
        .gio-checkout-row { display: flex; justify-content: space-between; gap: 1rem; margin: 0; font-size: .875rem; color: color-mix(in srgb, var(--gio-text) 35%, var(--gio-muted)); }
        .gio-checkout-total { margin: .25rem 0 0; padding: .625rem 0 0; border-top: 1px solid var(--gio-border); font-size: 1.125rem; font-weight: 700; color: var(--gio-text); }
        {{--
            The button, full width at the design's height, in the button
            tokens. Hover darkens the button's own colour rather than naming a
            second one, so a merchant's button colour still has a hover; the
            press is the design's one-pixel drop.
        --}}
        .gio-checkout-submit { display: flex; justify-content: center; align-items: center; gap: .5rem; box-sizing: border-box; width: 100%; min-height: 3rem; margin: .625rem 0 0; padding: .875rem 1.25rem; border: 0; border-radius: var(--gio-button-radius); background: var(--gio-button-background); color: var(--gio-button-text); font-family: inherit; font-size: .9375rem; font-weight: 700; line-height: 1.3; cursor: pointer; transition: background .15s, transform .1s; }
        .gio-checkout-submit:hover { background: color-mix(in srgb, var(--gio-button-background) 85%, #000); }
        .gio-checkout-submit:active { transform: translateY(1px); }
        .gio-checkout-submit[disabled] { opacity: .6; cursor: progress; transform: none; }
        .gio-checkout-note { margin: .625rem 0 0; font-size: .75rem; text-align: center; color: var(--gio-muted); }
        {{--
            The line a layout adds beneath the button after the button's own
            note — a reassurance, a footnote about the delivery charge —
            receding in the muted ink the way the note does.
        --}}
        .gio-checkout-footnote { margin: .5rem 0 0; font-size: .75rem; text-align: center; color: var(--gio-muted); }
        {{--
            The phone, as the design draws it at 375: the section and the card
            close in, a product's line total drops to a row of its own beneath
            the name, and the button loses a little of its padding. Nothing
            here reorders anything; it is the same island, tighter.
        --}}
        @media (max-width: 37.5rem) { {!! $groundOnPhone !!}{!! $cardOnPhone !!}.gio-checkout-line-total { flex: 1 0 100%; width: auto; padding: 0 0 0 1.75rem; font-size: .8125rem; text-align: left; } .gio-checkout-submit { padding: .875rem 1rem; font-size: .875rem; } }
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

    <div class="gio-checkout-card">
        {{--
            Where a refusal the customer cannot fix at a box is put: the page
            moved under them, the connection dropped, the server refused or
            fell over. At the top of the card rather than above the button,
            because a customer whose tap did nothing looks up, not down — and
            outside the form, because every layout arranges the form's
            children and a sibling of the form sits above whatever the
            arrangement draws.

            The reload button is for the one refusal it can put right: a page
            repriced or changed since these bytes were written. It stays hidden
            for the others, where reloading would only throw away what they
            typed for nothing. `aria-live` rather than a role, so a screen
            reader is told what was written into it and not what an empty box
            is for.
        --}}
        <div class="gio-checkout-banner" data-gio-banner aria-live="assertive">
            <p class="gio-checkout-banner-text" data-gio-error-for="page"></p>
            <button class="gio-checkout-reload" type="button" data-gio-reload hidden>Reload page</button>
        </div>

        {{--
            No @csrf token: this markup is shared by every visitor, so a token
            here would be one person's token handed to everyone. The POST
            authenticates by origin instead, with the script's token retry as
            the fallback.
        --}}
        <form class="gio-checkout-form"
              method="post"
              action="{{ route('checkout.store') }}"
              data-gio-checkout-form
              data-currency-symbol="{{ $currencySymbol }}"
              data-currency-decimals="{{ $currencyDecimals }}"
              {{--
                  What delivery costs when there is nothing to choose between.
                  The server charges it either way, so the figure on screen has
                  to be the figure collected at the door — a total that reads
                  low by the delivery charge is a refused parcel.
              --}}
              data-delivery-amount="{{ $preselected?->amount ?? 0 }}"
              data-token-url="{{ route('checkout.token') }}"{!! $cues !!}>
            <input type="hidden" name="page" value="{{ $page->slug }}">

            {{--
                What this form quoted the customer, as a figure the server can
                check back. These bytes may sit on disk for days, and a price or
                a delivery amount edited in the meantime means the total on
                screen is not the total the courier would collect — which in
                cash on delivery is a refused parcel and a fee in both
                directions.

                The capture refuses a submission whose fingerprint is not the
                page's current one, and tells the visitor to reload. Nothing
                about the questions or the styling is in it, so editing those
                never rejects an order somebody has half-typed (ADR-0007).
            --}}
            <input type="hidden" name="fingerprint" value="{{ $fingerprint }}">

            {{--
                Everything this Page sells, each with a quantity of its own.

                Quantities are keyed by Product id rather than by the position
                the Product sits at, so reordering them in the admin cannot
                point a form already open on someone's phone at a different
                item. A Product soft-deleted since these bytes were written is
                simply absent from the Page by the time the Capture is checked,
                and the rest still sell.

                Drawn only where the Product List is shown, on whichever layout
                the page is arranged in. Where it is hidden, the quantities are
                still posted — one of everything the page sells — so a checkout
                with the list hidden captures exactly the Order one showing it
                would.
            --}}
            @if ($showProducts)
                {{--
                    Which of the three the section is, said in the markup so
                    a behaving layout's script can step through them or open
                    one at a time without reading the classes of what happens
                    to be inside. Written on every layout, so the document is
                    the same shape whichever arrangement is chosen, and read
                    by nothing at all on the eight that do not behave.
                --}}
                <div class="gio-checkout-section" data-gio-panel="products">
                    {!! $wordAt(WordPlace::Products) !!}
                    {!! $wordAt(WordPlace::ItemCount) !!}

                    {{--
                        The three headings a layout drawing the list as a
                        table puts over its columns, written on one template
                        line so a layout heading none emits the one line it
                        always did rather than three. Siblings of the list
                        rather than its children: the sheet places them in
                        the section's own grid, which is the same grid every
                        row beneath them is drawn in.
                    --}}
                    {!! $wordAt(WordPlace::ItemHeading) !!}{!! $wordAt(WordPlace::QuantityHeading) !!}{!! $wordAt(WordPlace::AmountHeading) !!}

                    <div class="gio-checkout-products">
                        @foreach ($products as $product)
                            <div class="gio-checkout-product{{ $picksProducts ? ' gio-checkout-pickable' : '' }}"
                                 data-gio-line data-product-id="{{ $product->getKey() }}" data-unit-price="{{ $product->price }}">
                                @if ($picksProducts)
                                    {{--
                                        Ticked where the merchant said so, and
                                        unticked otherwise, which is what a page
                                        says nothing about. A tick is a starting
                                        position and never an agreement: the
                                        server charges for what arrives ticked,
                                        so a customer who unticks one before
                                        ordering is charged for what is left.

                                        One ticked is a price the customer meets
                                        on load and then changes. Two or more is
                                        a bundle the merchant is asserting, and
                                        on a cash-on-delivery page it carries the
                                        risk the empty default existed to avoid —
                                        an extra nobody chose is a refused parcel
                                        and a courier fee in both directions. The
                                        Checkout tab warns them there; here it is
                                        honoured (ADR-0010).

                                        Labelled by the product's own name, so a
                                        screen reader hears what is being ticked
                                        rather than "checkbox" three times.
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

                                    <div class="gio-checkout-product-words">
                                        <p class="gio-checkout-name" id="gio-product-{{ $product->getKey() }}">{{ $product->name }}</p>
                                        @if ($product->description)
                                            <p class="gio-checkout-description">{{ $product->description }}</p>
                                        @endif
                                        <p class="gio-checkout-price">{{ \App\Support\Money::format($product->price) }}</p>
                                    </div>
                                </{{ $productHead }}>

                                <div class="gio-checkout-quantity">
                                    {{--
                                        Labelled by its own word *and* the
                                        product's name, so a screen reader on a
                                        page selling three things hears
                                        "Quantity, Winter Jacket" rather than
                                        "Quantity" three times. The word is
                                        clipped from sight, as the design draws
                                        the stepper bare, and stays what a
                                        voice-control user says out loud.
                                    --}}
                                    <label class="gio-checkout-label gio-checkout-hidden-label gio-checkout-quantity-label"
                                           id="gio-quantity-label-{{ $product->getKey() }}"
                                           for="gio-quantity-{{ $product->getKey() }}">Quantity</label>
                                    {{--
                                        Minus and plus flanking a real numeric
                                        input, and the input is the control. Its
                                        bounds are what keep the browser and the
                                        capture agreed on the largest quantity,
                                        and typing into it is how a customer gets
                                        from one to twelve without eleven taps —
                                        a readout that could not be typed into
                                        was rejected for both reasons. The
                                        buttons only step it, by the step it
                                        declares, and do nothing at all until the
                                        script runs; the box works without them.

                                        `type="button"`, because a button in a
                                        form submits it unless told otherwise,
                                        and a tap on plus must never place an
                                        order. Hidden from the accessibility tree
                                        and skipped by the keyboard: the
                                        spinbutton they step already has arrow
                                        keys and a name, and three stops per
                                        product would make a page selling five
                                        things fifteen stops long.
                                    --}}
                                    <div class="gio-checkout-quantity-stepper">
                                        <button class="gio-checkout-quantity-step" type="button" data-gio-step="-1" aria-hidden="true" tabindex="-1">&minus;</button>
                                        {{--
                                            Not `required` where the box can be
                                            hidden. A stepper emptied and then
                                            unticked would fail the browser's own
                                            check from behind `display: none`,
                                            which refuses to submit and shows the
                                            customer nothing at all. The server
                                            checks what was ticked either way,
                                            and the island puts that refusal in
                                            the slot below.
                                        --}}
                                        <input class="gio-checkout-input gio-checkout-quantity-input"
                                               id="gio-quantity-{{ $product->getKey() }}"
                                               name="quantities[{{ $product->getKey() }}]"
                                               aria-labelledby="gio-quantity-label-{{ $product->getKey() }} gio-product-{{ $product->getKey() }}"
                                               type="number" value="1" min="1" max="{{ $maxQuantity }}" step="1"
                                               inputmode="numeric" @required(! $picksProducts) data-gio-quantity>
                                        <button class="gio-checkout-quantity-step" type="button" data-gio-step="1" aria-hidden="true" tabindex="-1">+</button>
                                    </div>
                                    <p class="gio-checkout-error gio-checkout-quantity-error" data-gio-error-for="quantities.{{ $product->getKey() }}"></p>
                                </div>

                                {{--
                                    What this line comes to, as the design
                                    draws it at the end of the row: one of it
                                    at the page's price until the customer
                                    touches the stepper, and kept truthful by
                                    the script after. Drawn at the price even
                                    for a product not yet ticked, faded, so a
                                    customer reads what ticking it would add.
                                --}}
                                <p class="gio-checkout-line-total" data-gio-line-total>{{ \App\Support\Money::format($product->price) }}</p>
                            </div>
                        @endforeach
                    </div>
                    {{--
                        The line a layout draws beneath an empty list, in the
                        markup whenever the layout owns one and shown by the
                        sheet only while a list that can be ticked has nothing
                        ticked — the list's next sibling, which is how the
                        sheet reaches it. A visitor whose scripts never run
                        still reads why the total is zero.
                    --}}
                    {!! $wordAt(WordPlace::EmptyList) !!}

                    {{--
                        Where an order for nothing is refused, beside the
                        boxes that were never ticked, inside the section that
                        holds them: a two-column arrangement then places the
                        products and their refusal in one move. Only a page
                        that asks the question has anywhere to show the answer
                        being wrong; on any other, the island's fallback puts
                        it in the page-level slot instead.
                    --}}
                    @if ($picksProducts)
                        <p class="gio-checkout-error gio-checkout-pick-error" data-gio-error-for="selected"></p>
                    @endif
                </div>
            @else
                {{--
                    The whole product panel, minus everything a customer looks
                    at.

                    A page hiding its Product List sold the product in its own
                    markup above the fold, so drawing it again in smaller type
                    would be the page arguing with itself. What is left is what
                    an Order cannot be captured without: one quantity per
                    product, keyed by product id like the stepper it stands in
                    for, so the Capture checks and writes exactly what it would
                    from a form that drew the list.

                    One of each, and no way to say otherwise. There is no
                    stepper to type into and no box to tick — which is also why
                    a checkout with the list hidden never asks the customer to
                    choose, and why the switch is read where that question is
                    answered (`picksProducts()`).

                    They carry the line markers too, so the summary below
                    totals the same way in every layout rather than the script
                    needing to know which one it is looking at.
                --}}
                @foreach ($products as $product)
                    <input type="hidden" name="quantities[{{ $product->getKey() }}]" value="1"
                           data-gio-line data-product-id="{{ $product->getKey() }}" data-unit-price="{{ $product->price }}">
                @endforeach
            @endif

            {{--
                Everything the customer answers, as one block: the questions
                with the delivery choice beneath them, then the payment
                choice. One element so that a two-column arrangement can put
                the whole of it on one side of the form in a single move,
                while the products and the summary take the other; on a
                one-column page it is an unstyled box around the sections it
                always held. The document's order is unchanged by it
                (ADR-0015).
            --}}
            <div class="gio-checkout-questions">
            {{--
                The questions, their wording and their order come from this
                Page's own Checkout Config — the same list the server compiles
                its rules from, so a visitor is never blocked from typing
                something the Capture would have accepted.

                One loop for both kinds of field. A question the merchant added
                of their own is drawn by its type exactly as a built-in of that
                type is, and sits wherever the list puts it, including between
                two built-ins (ADR-0006).

                The grid they sit in is what lets two short questions share a
                row; the delivery choice sits beneath it in the same section,
                as the design draws the details.
            --}}
            <div class="gio-checkout-section" data-gio-panel="details">
                {!! $wordAt(WordPlace::Details) !!}

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
                            One wrapper per question, carrying nothing but how
                            wide it sits: a flat class the sheet above already
                            has, meaning something only above the breakpoint,
                            and blank for a full-width question so a page where
                            nobody paired anything renders the markup it always
                            did. Blank too on a layout that pairs nothing — the
                            layout's to say — while the width itself stays in
                            the Config for the day the page is arranged another
                            way. No question restyles itself, so no wrapper
                            carries a style of its own.
                        --}}
                        {{--
                            Marked as a question so the script can tell the
                            moment a customer starts answering one from a tap
                            on a product's box or a quantity stepper, which is
                            arranging an order and not filling in the form.
                            On the wrapper rather than on each control: one
                            attribute serves every kind of field, including a
                            radio group whose controls are several.
                        --}}
                        <div class="gio-checkout-field{{ $layout->pairsQuestions() ? $field->width->classSuffix() : '' }}" data-gio-field>
                            {{--
                                A radio group and a checkbox carry their own
                                wording — a legend over the choices, and the
                                text beside the box — so neither takes the label
                                block above it. Labelling both would ask the
                                same question twice, and a stray <label>
                                pointing at nothing is worse than no label at
                                all.
                            --}}
                            @if ($field->type() === CheckoutFieldType::Radio)
                                <fieldset class="gio-checkout-choices">
                                    <legend class="gio-checkout-label">{{ $field->label }}</legend>

                                    @if ($field->help !== null)
                                        <p class="gio-checkout-help" id="{{ $id }}-help">{{ $field->help }}</p>
                                    @endif

                                    {{--
                                        Nothing chosen to begin with, even where
                                        an answer is compulsory: a pre-ticked
                                        answer is one the customer never gave.
                                        The browser's own check asks for one,
                                        and the server refuses a value the list
                                        does not hold.
                                    --}}
                                    @foreach ($field->options() as $option)
                                        <label class="gio-checkout-choice">
                                            <input class="gio-checkout-tick" name="{{ $field->key() }}" value="{{ $option->value }}"
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
                                    <input class="gio-checkout-tick" name="{{ $field->key() }}" {{ $control }} @required($field->required)>
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
                </div>

                {{--
                    How the parcel gets to the door, in the merchant's own words
                    and at their own prices. Rendered only where there is a
                    decision to make: a page offering one arrangement charges
                    it, and a page offering none charges nothing, and neither
                    is a question worth putting in front of a customer whose
                    only possible answer is the one they would be given.

                    Radios rather than a select, because the amounts have to be
                    readable without opening anything — the choice is a price
                    as much as it is a delivery method. The first is already
                    chosen so the total below is truthful from the first paint;
                    the server refuses a submission that names none of them
                    regardless.
                --}}
                @if (count($deliveryOptions) > 1)
                    {!! $wordAt(WordPlace::Delivery) !!}

                    {{--
                        The row and its control carry a second class naming
                        what they are for, beside the shared one that draws
                        them: a layout that draws the delivery choice as a
                        segmented control and the payment choice as plain
                        radios has to tell the two apart, and may reach only
                        two classes deep to do it. The shared class is written
                        last, so a rule reading `gio-checkout-choice-label">`
                        reads the same bytes it always did.
                    --}}
                    <fieldset class="gio-checkout-delivery">
                        <legend class="gio-checkout-label gio-checkout-hidden-label">Delivery</legend>

                        @foreach ($deliveryOptions as $index => $option)
                            <label class="gio-checkout-choice gio-checkout-delivery-choice">
                                <input class="gio-checkout-delivery-tick gio-checkout-tick"
                                       type="radio"
                                       name="delivery_option"
                                       value="{{ $option->label }}"
                                       data-gio-delivery-option
                                       data-amount="{{ $option->amount }}"
                                       @checked($index === 0) required>
                                {{--
                                    The detail line sits inside the words' span,
                                    on one line with the name, so a row with none
                                    emits exactly the bytes it always did and a
                                    row with one keeps the row's reading order:
                                    name, detail, figure. Drawn only where the
                                    layout has room for a second line — the
                                    layout's to say — and kept in the Config
                                    either way. Copy, not a figure: the script
                                    reads `data-amount` and never this.
                                --}}
                                <span class="gio-checkout-delivery-label gio-checkout-choice-label">{{ $option->label }}@if ($option->detail !== null && $layout->drawsDeliveryDetail())<span class="gio-checkout-choice-detail">{{ $option->detail }}</span>@endif</span>
                                <span class="gio-checkout-choice-amount">{{ \App\Support\Money::format($option->amount) }}</span>
                            </label>
                        @endforeach
                    </fieldset>

                    <p class="gio-checkout-error" data-gio-error-for="delivery_option"></p>
                @endif
            </div>
@if ($paymentChooser->isDrawn())
            {{--
                At column 0, and so is its @endif: Blade swallows the newline
                after a directive but not the indent before it, so an indented
                @if leaves a line of spaces in the bytes of every page that
                draws no chooser. Written flush left, a page with a default
                Config emits exactly what it did before a method could be
                chosen.

                How the customer will pay, in the merchant's order, as the
                design's third section. Drawn only where there is a decision
                worth putting in front of them: a page offering nothing but
                cash on delivery with the choice hidden draws the form it
                always did and posts no method, and the server places the
                order under cash on delivery. The comment sits inside the
                branch for the same reason, so a page drawing no chooser
                emits no line for it.

                Radios like the delivery choice above, and drawn as the same
                row: the control, the name, then the logo at the far end where
                the page shows them, as the design's slot sits. The first is
                already picked so a page with one preferred method needs no
                extra tap, and so a plain form post with no script carries a
                method regardless. The server refuses a name the page never
                listed. Which of a method's two labels names it is the
                layout's to say.

                The logo is decorative beside a name that says the same thing,
                so its alt is empty and a screen reader hears the method once.
            --}}
            <div class="gio-checkout-section" data-gio-panel="payment">
                {!! $wordAt(WordPlace::Payment) !!}

                <fieldset class="gio-checkout-choices gio-checkout-payment">
                    <legend class="gio-checkout-label gio-checkout-hidden-label">Payment</legend>

                    @foreach ($paymentChooser->methods as $index => $method)
                        <label class="gio-checkout-choice gio-checkout-payment-choice">
                            <input class="gio-checkout-payment-tick gio-checkout-tick"
                                   type="radio"
                                   name="payment_method"
                                   value="{{ $method->value }}"
                                   @checked($index === 0) required>
                            <span class="gio-checkout-payment-label gio-checkout-choice-label">{{ $layout->drawsShortPaymentLabels() ? $words->shortPaymentLabel($method) : $words->paymentLabel($method) }}</span>
                            @if ($paymentChooser->showsLogos)
                                <img class="gio-checkout-logo" src="{{ $method->logoUrl() }}" alt="" loading="lazy">
                            @endif
                        </label>
                    @endforeach
                </fieldset>

                <p class="gio-checkout-error" data-gio-error-for="payment_method"></p>
            </div>
@endif
            </div>

            {{--
                One of everything, which is what the quantity boxes above start
                on, plus the delivery arrangement that is already selected. The
                script keeps these truthful as the visitor changes them; a
                visitor whose scripts never run reads the figures the page was
                rendered with, and the Order Complete Page after Capture is
                where they see what they actually owe.

                Where the customer picks, only what the form opens ticked:
                totalling the whole shelf would quote a figure no order could
                ever come to, and totalling nothing where a merchant has
                pre-ticked would put a zero beside boxes that are visibly
                ticked. Read through the same test the boxes were drawn from,
                so the figure and the ticks cannot disagree.
            --}}
            @php($subtotal = (int) ($picksProducts ? $products->filter($opensTicked) : $products)->sum('price'))
            @php($total = $subtotal + ($preselected?->amount ?? 0))

            {{--
                Three rows in the merchant's words or the design's. The
                delivery row is labelled as a charge rather than after the
                arrangement: the arrangement's own name is on the radio above,
                where it is chosen, and the row beneath it is what that choice
                costs. Nothing at all where the page charges for no delivery.
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
                The merchant's words, or the layout's. Where the wording names
                the total it is filled in here from the same figure the summary
                shows, so a visitor whose scripts never run reads a price and
                never a placeholder; the template then rides on the button for
                the script, which refills it every time it retotals. No
                template where nothing is named, so the script leaves a plain
                button alone.
            --}}
            <button class="gio-checkout-submit" type="submit" data-gio-submit{!! $button->carriesTotal() ? ' data-gio-submit-wording="'.e($button->wording()).'"' : '' !!}>{{ $button->wordingFor(\App\Support\Money::format($total)) }}</button>

            <p class="gio-checkout-note">{{ $button->note() }}</p>

            {{--
                The line the layout adds beneath the button, where it draws
                one, after the button's own note: a reassurance, or a footnote
                naming the delivery charge the total already includes.
            --}}
            {!! $wordAt(WordPlace::Button) !!}
        </form>
    </div>
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

    /*
     * The two arrangements that behave, named by the class the layout puts on
     * the island's root. Everything keyed off these does nothing at all on the
     * other eight, which is what lets one script serve every page: the chrome
     * each of the two needs — a stepper's rail with its Next and Back, an
     * accordion's header buttons — is built here rather than written into the
     * document, so a visitor whose scripts never run reads every panel in the
     * order the page was written and orders from the form as it stands
     * (ADR-0015).
     */
    var STEPPER = 'gio-checkout-stepper';
    var ACCORDION = 'gio-checkout-accordion';

    /*
     * What this script calls out to the page when a customer first touches one
     * of the questions, and whether it has done so yet.
     *
     * The one thing the island says about itself to anything outside it. What
     * listens, if anything does, is none of its business: a page with a pixel
     * on it hears this and reports a moment, and a page without one hears
     * nothing happen.
     */
    var CHECKOUT_STARTED = @json($startedEvent);

    var startedFilling = false;

    /*
     * What a shut accordion header says about the choice made inside it, and
     * the arrows either header wears. English alone, like the sentences
     * above: these are the script's own words rather than the merchant's,
     * who words the header itself on the Words tab.
     */
    var ONE_ITEM = ' item';
    var MANY_ITEMS = ' items';
    var SUMMARY_JOIN = ' · ';
    var OPEN_CHEVRON = '▲';
    var SHUT_CHEVRON = '▼';

    /*
     * What a merchant's own page and an ad platform both want in the end: the
     * figure, in whole currency rather than in the minor units everything here
     * counts in. Rounded to the currency's own places, because a division by a
     * power of ten is the one arithmetic a browser can hand back a trailing
     * digit from, and a price is not a place to show one.
     */
    function major(form, minorUnits) {
        var decimals = parseInt(form.dataset.currencyDecimals, 10) || 0;
        return parseFloat((minorUnits / Math.pow(10, decimals)).toFixed(decimals));
    }

    function money(form, minorUnits) {
        var decimals = parseInt(form.dataset.currencyDecimals, 10) || 0;
        return form.dataset.currencySymbol + major(form, minorUnits).toFixed(decimals);
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
     * How many of this line the customer is ordering, and what one of them
     * costs. Both read off the line rather than off a single figure on the
     * form: a page may sell several products, each at its own price and its
     * own quantity.
     */
    function quantityOf(line) {
        var input = line.querySelector('[data-gio-quantity]');

        return Math.max(1, parseInt(input ? input.value : '', 10) || 1);
    }

    function unitPriceOf(line) {
        return parseInt(line.dataset.unitPrice, 10) || 0;
    }

    /*
     * Whether this line is part of the order: everything is, on a form that
     * asks the customer to tick nothing, and only what they ticked on one
     * that does.
     */
    function ticked(line) {
        var pick = line.querySelector('[data-gio-pick]');

        return !pick || pick.checked;
    }

    /* What one line comes to at its current quantity, which is what the line's own total shows, ticked or not. */
    function lineAmount(line) {
        return unitPriceOf(line) * quantityOf(line);
    }

    /*
     * What one line adds to the order: nothing for a line the customer has
     * not ticked, so the summary only ever totals up what they are actually
     * ordering.
     */
    function lineSubtotal(line) {
        return ticked(line) ? lineAmount(line) : 0;
    }

    /*
     * What the customer currently has selected: a line per product they are
     * ordering, how many of each, and what those products come to.
     *
     * Off the same line markers and the same two readings — `ticked` and
     * `lineAmount` — the summary is totalled from, so what leaves this page
     * about what a customer is buying and what the page shows them can never
     * come from two different walks of the form.
     *
     * The products alone. What it costs to get the parcel to the door is not
     * something the customer has selected, and the figure on screen carries
     * it because the figure on screen is what the courier collects.
     *
     * Named in this page's own words and in no platform's — whatever listens
     * translates. In whole currency rather than minor units, because this is
     * handed over the page's edge and nothing outside knows what a minor unit
     * is here.
     */
    function selectionOf(form) {
        var lines = [];
        var count = 0;
        var value = 0;

        form.querySelectorAll('[data-gio-line]').forEach(function (line) {
            if (!ticked(line)) { return; }

            lines.push({
                id: line.dataset.productId,
                quantity: quantityOf(line),
                unitPrice: major(form, unitPriceOf(line))
            });

            count += quantityOf(line);
            value += lineAmount(line);
        });

        return { value: major(form, value), count: count, lines: lines };
    }

    /*
     * Say once, and only once, that the customer has started filling the form.
     *
     * Raised on the document rather than acted on here, because this script
     * knows how a checkout is drawn and nothing about who wants to hear that
     * it was started. A page carrying no listener raises it into nothing.
     *
     * Once per page load: a customer who answers a question, wanders off to
     * read the page and comes back to answer another has not started a second
     * checkout. Tapping a product's box or a quantity stepper is arranging an
     * order rather than filling in the form, and is deliberately not it —
     * which is why the marker is on the questions and nowhere else, and why
     * this is reached from a touch rather than from a change.
     *
     * Nothing here may raise. It runs from a listener on a merchant's own
     * page, where a red line in the console would be the only thing anybody
     * ever saw of it.
     */
    function noteStarted(form, target) {
        if (startedFilling || !target || !target.closest || !target.closest('[data-gio-field]')) { return; }

        startedFilling = true;

        try {
            document.dispatchEvent(new CustomEvent(CHECKOUT_STARTED, { detail: selectionOf(form) }));
        } catch (e) {
            /* A browser with no CustomEvent reports nothing and orders fine. */
        }
    }

    /* The figure the customer hands over in cash, kept truthful as they type. */
    function retotal(form) {
        var subtotal = 0;
        var count = 0;

        form.querySelectorAll('[data-gio-line]').forEach(function (line) {
            var lineTotal = line.querySelector('[data-gio-line-total]');

            if (lineTotal) { lineTotal.textContent = money(form, lineAmount(line)); }
            if (ticked(line)) { count += 1; }

            subtotal += lineSubtotal(line);
        });

        var delivery = deliveryFor(form);

        /*
         * Every slot carrying a figure, not the first: a layout's own words
         * may name the delivery charge beneath the button as well as in the
         * summary, and both have to move together.
         */
        var set = function (name, text) {
            form.querySelectorAll('[data-gio-' + name + ']').forEach(function (el) {
                el.textContent = text;
            });
        };

        var total = money(form, subtotal + delivery.amount);

        set('count', String(count));
        set('subtotal', money(form, subtotal));
        set('delivery', money(form, delivery.amount));
        set('total', total);

        /* And what an accordion's shut headers say, off the same figures. */
        summarise(form, count, subtotal);

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
     * up in: the banner is the form's sibling and not its child, so that no
     * layout has to make room for it.
     */
    function islandFor(form) {
        return form.closest('[data-gio-checkout]');
    }

    /* Whether this form's island is arranged in the layout named. */
    function arrangedAs(form, layout) {
        var island = islandFor(form);

        return island !== null && island.classList.contains(layout);
    }

    /* Whether it is arranged in either of the two that behave. */
    function behaves(form) {
        return arrangedAs(form, STEPPER) || arrangedAs(form, ACCORDION);
    }

    /*
     * The sections a behaving layout steps through or opens, in the order the
     * document writes them. Which of the three each is, is said in the markup:
     * the products may be hidden and the payment choice may not be drawn, so a
     * step's own word cannot be found by counting.
     */
    function panelsOf(form) {
        return [].slice.call(form.querySelectorAll('[data-gio-panel]'));
    }

    /* One of the layout's own words for a control this script builds. */
    function cue(form, name) {
        return form.getAttribute('data-gio-cue-' + name) || '';
    }

    function element(tag, className) {
        var el = document.createElement(tag);
        el.className = className;

        return el;
    }

    /* `type="button"`, because a button in a form submits it unless told otherwise. */
    function chrome(className, text) {
        var el = element('button', className);
        el.type = 'button';
        el.textContent = text;

        return el;
    }

    function show(el, shown) {
        if (el) { el.hidden = !shown; }
    }

    function mark(el, done) {
        if (!el) { return; }

        if (done) { el.setAttribute('data-gio-done', ''); } else { el.removeAttribute('data-gio-done'); }
    }

    /*
     * The first answer in a panel that the browser would refuse, or null where
     * there is none.
     *
     * This is what a behaving layout has to ask before it hides anything: a
     * required box inside a panel the script has hidden cannot be focused, so
     * the browser refuses the whole submission and shows nobody anything at
     * all. Asked before every step forward, and again before the order goes
     * out, so the panel holding the refusal is opened before the browser is
     * asked to say what is wrong.
     */
    function refusalIn(panel) {
        var refused = null;

        panel.querySelectorAll('input, select, textarea').forEach(function (field) {
            if (!refused && field.willValidate && !field.checkValidity()) { refused = field; }
        });

        return refused;
    }

    /* Put a panel in front of the customer, in whichever way its layout does. */
    function reveal(form, panel) {
        if (arrangedAs(form, ACCORDION)) {
            openPanel(form, panel.dataset.gioPanel);

            return;
        }

        showStep(form, panelsOf(form).indexOf(panel));
    }

    /*
     * Whether every panel from here up to there holds answers the browser
     * would take. Where one does not, that panel is opened and the browser
     * says what is wrong, and the customer goes no further.
     */
    function answeredThrough(form, from, to) {
        var panels = panelsOf(form);

        for (var at = from; at < to && at < panels.length; at += 1) {
            var refused = refusalIn(panels[at]);

            if (refused) {
                reveal(form, panels[at]);
                refused.reportValidity();

                return false;
            }
        }

        return true;
    }

    /*
     * The stepper's chrome: a rail of steps above the panel, and a Next and a
     * Back at its foot.
     *
     * The rail sits above the form rather than inside it, as the design draws
     * it, so it is the island's chrome and not the form's; the nav goes in
     * ahead of the button, which is the one child of the form that only the
     * last step shows. The summary stays on screen throughout — the figure a
     * customer is agreeing to hand over in cash is never behind a tap.
     */
    function buildStepper(form) {
        var panels = panelsOf(form);

        if (panels.length === 0) { return; }

        var rail = element('div', 'gio-checkout-steps');

        panels.forEach(function (panel, at) {
            if (at) { rail.appendChild(element('span', 'gio-checkout-step-line')); }

            var step = chrome('gio-checkout-step', '');
            step.dataset.gioGoto = String(at);

            var number = element('span', 'gio-checkout-step-number');
            number.textContent = String(at + 1);

            var label = element('span', 'gio-checkout-step-label');
            label.textContent = cue(form, 'stepper-' + panel.dataset.gioPanel + '-step');

            step.appendChild(number);
            step.appendChild(label);
            rail.appendChild(step);
        });

        form.parentNode.insertBefore(rail, form);

        var nav = element('div', 'gio-checkout-nav');
        var back = chrome('gio-checkout-back', cue(form, 'stepper-back'));
        var next = chrome('gio-checkout-next', cue(form, 'stepper-next'));

        back.dataset.gioMove = '-1';
        next.dataset.gioMove = '1';
        nav.appendChild(back);
        nav.appendChild(next);

        form.insertBefore(nav, form.querySelector('[data-gio-submit]'));

        showStep(form, 0);
    }

    /* One panel at a time, the rail saying how far along the customer is. */
    function showStep(form, at) {
        var island = islandFor(form);
        var panels = panelsOf(form);
        var last = panels.length - 1;

        at = Math.max(0, Math.min(at, last));

        /*
         * Which step is showing, kept on the form so nothing here holds state
         * of its own. Never the quantity stepper's own name: on the form, that
         * would answer the stepper's delegated lookup for every click inside
         * it, and a tap on Next would silently add one to the first product's
         * quantity.
         */
        form.dataset.gioShowing = String(at);

        panels.forEach(function (panel, index) { panel.hidden = index !== at; });

        island.querySelectorAll('[data-gio-goto]').forEach(function (step) {
            var index = parseInt(step.dataset.gioGoto, 10);

            if (index === at) { step.setAttribute('aria-current', 'step'); } else { step.removeAttribute('aria-current'); }

            mark(step.querySelector('.gio-checkout-step-number'), index <= at);
            mark(step.previousElementSibling, index <= at);
        });

        show(form.querySelector('[data-gio-submit]'), at === last);
        show(form.querySelector('.gio-checkout-note'), at === last);
        show(island.querySelector('[data-gio-move="1"]'), at !== last);
        show(island.querySelector('[data-gio-move="-1"]'), at > 0);
    }

    /*
     * The accordion's chrome: each panel's own heading, with a button inside
     * it carrying the heading's words, what was chosen in the panel, and the
     * arrow saying which way it opens.
     *
     * The heading stays a heading and gains the button, which is what an
     * accordion header is; the panel's other children are what opens and
     * shuts, so every header is on screen at every moment.
     */
    function buildAccordion(form) {
        var panels = panelsOf(form);

        if (panels.length === 0) { return; }

        panels.forEach(function (panel) {
            var heading = panel.querySelector('.gio-checkout-heading');

            if (!heading) { return; }

            var head = chrome('gio-checkout-panel-head', '');
            head.dataset.gioPanelHead = panel.dataset.gioPanel;

            var title = element('span', 'gio-checkout-panel-title');
            title.textContent = heading.textContent;

            var chevron = element('span', 'gio-checkout-panel-chevron');
            chevron.setAttribute('aria-hidden', 'true');

            head.appendChild(title);
            head.appendChild(element('span', 'gio-checkout-panel-summary'));
            head.appendChild(chevron);

            heading.textContent = '';
            heading.appendChild(head);
        });

        openPanel(form, panels[0].dataset.gioPanel);
    }

    /* One panel open at a time, and none where the open one is tapped again. */
    function openPanel(form, name) {
        panelsOf(form).forEach(function (panel) {
            var open = panel.dataset.gioPanel === name;
            var head = panel.querySelector('[data-gio-panel-head]');

            [].slice.call(panel.children).forEach(function (child) {
                if (!child.classList.contains('gio-checkout-heading')) { child.hidden = !open; }
            });

            if (open) { panel.removeAttribute('data-gio-shut'); } else { panel.setAttribute('data-gio-shut', ''); }

            if (head) {
                head.setAttribute('aria-expanded', open ? 'true' : 'false');
                head.querySelector('.gio-checkout-panel-chevron').textContent = open ? OPEN_CHEVRON : SHUT_CHEVRON;
            }
        });
    }

    /*
     * What a shut accordion header says about the choice made inside it: how
     * many items and what they come to, the delivery arrangement and its
     * charge, and the method the customer will pay by.
     *
     * Read off the same figures and the same checked controls the totals are,
     * as `retotal` finishes, so a header and the summary beneath it cannot
     * disagree. Nothing at all where a panel holds no choice to report — a
     * page offering one delivery arrangement has none to name.
     */
    function summarise(form, count, subtotal) {
        if (!arrangedAs(form, ACCORDION)) { return; }

        var delivery = form.querySelector('[data-gio-delivery-option]:checked');

        /*
         * The row rather than the label inside it: a method's name is the only
         * text a payment row carries — the logo beside it is decorative and
         * says nothing — and reading the row keeps the class names of one
         * arrangement out of a script every page ships.
         */
        var chosen = form.querySelector('[name="payment_method"]:checked');

        var said = {
            products: count + (count === 1 ? ONE_ITEM : MANY_ITEMS) + SUMMARY_JOIN + money(form, subtotal),
            details: delivery ? delivery.value + SUMMARY_JOIN + money(form, deliveryFor(form).amount) : '',
            payment: chosen ? chosen.parentNode.textContent.trim() : ''
        };

        form.querySelectorAll('[data-gio-panel-head]').forEach(function (head) {
            head.querySelector('.gio-checkout-panel-summary').textContent = said[head.dataset.gioPanelHead] || '';
        });
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

        if (!first) { return; }

        /*
         * A slot inside a panel a behaving layout has shut is a refusal
         * nobody can read, so the panel it belongs to is opened before the
         * customer is taken to it. The banner is the island's own child and
         * belongs to no panel, so it opens nothing.
         */
        var panel = first.closest ? first.closest('[data-gio-panel]') : null;

        if (panel && behaves(form)) { reveal(form, panel); }

        first.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }

    /* A failure that is nobody's answer to fix, worded for what went wrong and offering no reload. */
    function showFailure(form, message) {
        var text = openBanner(form, false);

        if (fill(text, message)) { text.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
    }

    /*
     * One cookie by name, or null. Read at submit and never at render: these
     * bytes may have been written to disk days ago and are served to every
     * visitor alike, so nothing about this one visitor can be baked into
     * them.
     */
    function cookie(name) {
        var match = document.cookie.match(new RegExp('(^|;\\s*)' + name + '=([^;]*)'));
        if (!match) { return null; }

        /*
         * A stray `%` left by another script or a proxy makes decoding throw,
         * and a throw here would leave the order unposted behind a disabled
         * button. The raw value is worth more than nothing to a platform, and
         * an order is worth more than either.
         */
        try {
            return decodeURIComponent(match[2]);
        } catch (e) {
            return match[2];
        }
    }

    /*
     * The click identifiers the ad platforms match an order back to the click
     * that produced it, read from the browser and posted with the form.
     *
     * `_fbp` is the pixel's own browser identifier and is taken as it stands.
     * `_fbc` is Facebook's click identifier: taken from the cookie where the
     * pixel has already written one, and otherwise built from the `fbclid`
     * this page was opened with, in the shape Meta documents — a page whose
     * visitor blocks the pixel, or who submits before it has loaded, still
     * reports the click that brought them.
     *
     * A visitor whose scripts never run sends none of this, and their order
     * is captured exactly as it always was: the server keeps only what some
     * platform recognises and never refuses an order over it.
     */
    function clickIds() {
        var stored = cookie('_fbc');

        if (!stored) {
            /*
             * Read with a pattern rather than `URLSearchParams`, which the
             * older webviews a good share of this market still orders from do
             * not carry. Nothing on the ordering path may depend on a
             * convenience that is missing for some of them.
             */
            var clicked = window.location.search.match(/[?&]fbclid=([^&]*)/);
            if (clicked) { stored = 'fb.1.' + Date.now() + '.' + clicked[1]; }
        }

        return { fbc: stored, fbp: cookie('_fbp') };
    }

    /*
     * Written into hidden inputs rather than added to the body of the request,
     * so a layout that one day posts this form by other means carries them
     * too, and so a merchant debugging in their own browser can see what is
     * being sent. Added here rather than drawn in the document, because a box
     * rendered empty would post an empty value for every visitor whose
     * browser filled in nothing.
     */
    function stampClickIds(form) {
        var ids = clickIds();

        Object.keys(ids).forEach(function (name) {
            if (!ids[name]) { return; }

            var field = 'click_ids[' + name + ']';
            var input = form.querySelector('input[name="' + field + '"]');

            if (!input) {
                input = document.createElement('input');
                input.type = 'hidden';
                input.name = field;
                form.appendChild(input);
            }

            input.value = ids[name];
        });
    }

    /*
     * And whatever else a browser we have never seen does with any of the
     * above. Tracking is never a precondition for buying: this runs after the
     * submit has been taken over and the button disabled, so anything raised
     * here that was not caught would leave the customer looking at a dead
     * "Placing your order…" and no order placed. The reporting is what is
     * lost, never the order.
     */
    function stampClickIdsQuietly(form) {
        try {
            stampClickIds(form);
        } catch (e) {
            /* Nothing to say to the customer, and nothing to do about it. */
        }
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
     * Touching a question is what counts, not answering one: a customer who
     * taps into the name box has started filling the form whether or not they
     * type anything. `focusin` rather than `focus`, because only the former
     * reaches a listener on the document — and this one signal rather than
     * `input` beside it, because a browser autofilling a form nobody has
     * touched has started nothing.
     */
    document.addEventListener('focusin', function (event) {
        var form = formFor(event);
        if (form) { noteStarted(form, event.target); }
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

        /*
         * The button itself, never whatever else might one day carry the
         * name: a container with `data-gio-step` on it would answer this for
         * every click inside it, and a tap anywhere in the form would step a
         * quantity nobody touched.
         */
        var step = event.target.closest ? event.target.closest('button[data-gio-step]') : null;
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

        /* And every answer a behaving layout has shut away, which the browser is no longer checking. */
        if (behaves(form) && !answeredThrough(form, 0, panelsOf(form).length)) { return; }

        var button = form.querySelector('[data-gio-submit]');
        var buttonLabel = button ? button.innerHTML : null;

        if (button) {
            /* Guards the double-tap that would otherwise capture two Orders. */
            if (button.disabled) { return; }
            button.disabled = true;
            button.textContent = SUBMITTING;
        }

        stampClickIdsQuietly(form);

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
     * island's and not the form's.
     */
    document.addEventListener('click', function (event) {
        var reload = event.target.closest ? event.target.closest('[data-gio-reload]') : null;
        if (!reload || !reload.closest('[data-gio-checkout]')) { return; }

        window.location.reload();
    });

    /*
     * A behaving layout's own controls, delegated from the document like the
     * rest, and inert on the other eight because no page arranged in them has
     * any of these to click. Found through the island rather than through the
     * form, because the stepper's rail sits above the form as the banner does.
     */
    document.addEventListener('click', function (event) {
        var island = event.target.closest ? event.target.closest('[data-gio-checkout]') : null;
        var control = island ? event.target.closest('[data-gio-goto], [data-gio-move], [data-gio-panel-head]') : null;
        if (!control) { return; }

        var form = island.querySelector('[data-gio-checkout-form]');
        if (!form) { return; }

        if (control.dataset.gioPanelHead !== undefined) {
            /* Tapping the panel that is open shuts it, which is the whole of "one at a time or none". */
            openPanel(form, control.getAttribute('aria-expanded') === 'true' ? null : control.dataset.gioPanelHead);

            return;
        }

        var at = parseInt(form.dataset.gioShowing, 10) || 0;
        var to = control.dataset.gioGoto !== undefined
            ? parseInt(control.dataset.gioGoto, 10)
            : at + parseInt(control.dataset.gioMove, 10);

        /* Forward only past answers the browser would take, so nothing it refuses is ever hidden. */
        if (to > at && !answeredThrough(form, at, to)) { return; }

        showStep(form, to);
    });

    document.querySelectorAll('[data-gio-checkout-form]').forEach(function (form) {
        if (behaves(form)) {
            /*
             * The browser's own gate is taken over for the two arrangements
             * that hide an answer already given. A required box inside a
             * hidden panel is one the browser refuses to submit and cannot
             * focus, so it would block the order and show nobody anything —
             * the submit handler asks the same questions of the same boxes
             * instead, and opens the panel holding the first refusal before
             * reporting it. Set here rather than in the markup, so a visitor
             * whose scripts never run keeps every check the browser makes.
             */
            form.noValidate = true;
        }

        if (arrangedAs(form, STEPPER)) { buildStepper(form); }
        if (arrangedAs(form, ACCORDION)) { buildAccordion(form); }

        retotal(form);
    });
})();
</script>
