{{--
    The Order Complete Page: where a Customer lands once their Order is settled
    as far as the Checkout is concerned. It draws that one state; the
    controller sends an Unpaid Order to the Retry Page before this is reached.

    Unlike a landing page, this document is ours — the merchant does not author
    it — so it carries its own styles inline, and nothing else: no stylesheet,
    font, script or picture from anywhere. A page carrying a Customer's details
    makes no request they did not ask for. It is never cached: it is addressed
    per order and hit once.

    What it says is the Page's Order Complete Config, handed here as `$words`
    with every box the Merchant left empty at the built-in words and the
    Order's own facts in place of the placeholders. How it is arranged is
    `$layout`: one base sheet, the chosen layout's class on the root and that
    layout's additive block, and no other layout's rules. What it is coloured
    with is the Page's Checkout Style Tokens, completed the way the Checkout
    completes them, so the page after the order matches the form before it.

    It shows the Customer only to the browser that placed the Order. This URL
    ends up in browser history and Referer headers, so the address unlocks
    nothing; what does is the cookie Capture handed that browser, which the
    controller reads into `$placedByThisBrowser` (ADR-0014). Anyone else sees
    the Order and not the Customer: shown less, never refused.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $words->headline() }} · Order {{ $order->order_number }}</title>
    <style>
        {{--
            The named token set, declared once on the root and read by every
            rule under it, the same way the Checkout island reads it. The text
            token defaults to `inherit`, so the html element carries the colour
            the page has always been written in for the root to inherit from.
        --}}
        :root { color-scheme: light; color: #111318; }
        .gio-order-complete { {{ $styleTokens->declarations() }} box-sizing: border-box; min-height: 100vh; margin: 0; padding: 3.5rem 1.5rem; background: #f7f8fa; color: var(--gio-text); font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; font-size: var(--gio-font-size); line-height: 1.5; }
        .gio-order-complete-card { box-sizing: border-box; max-width: 32.5rem; margin: 0 auto; padding: 2.25rem 2rem; background: var(--gio-background); border: var(--gio-border-width) solid var(--gio-border); border-radius: var(--gio-radius); display: flex; flex-direction: column; align-items: center; gap: 1.125rem; text-align: center; }
        .gio-order-complete-head { box-sizing: border-box; width: 100%; min-width: 0; margin: 0; display: flex; flex-direction: column; align-items: center; gap: 1.125rem; }
        .gio-order-complete-badge { flex: none; width: 4rem; height: 4rem; border-radius: 50%; background: var(--gio-accent); color: #fff; display: grid; place-items: center; }
        .gio-order-complete-badge svg { width: 2rem; height: 2rem; }
        .gio-order-complete-words { min-width: 0; }
        .gio-order-complete-status { margin: 0 0 .5rem; font-size: .75em; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; }
        .gio-order-complete-headline { margin: 0 0 .375rem; font-size: 1.75em; font-weight: 700; line-height: 1.2; overflow-wrap: anywhere; }
        .gio-order-complete-message { margin: 0; font-size: .9375em; opacity: .72; }
        .gio-order-complete-number { flex: none; margin: 0; padding: .5rem .875rem; border: var(--gio-border-width) solid var(--gio-border); border-radius: var(--gio-radius); font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .8125em; font-weight: 600; }
        .gio-order-complete-summary { box-sizing: border-box; width: 100%; min-width: 0; margin: 0; padding: 1.125rem 0 0; border-top: var(--gio-border-width) solid var(--gio-border); display: flex; flex-direction: column; gap: .625rem; text-align: left; font-size: .875em; }
        .gio-order-complete-row { display: flex; justify-content: space-between; gap: .75rem; margin: 0; }
        .gio-order-complete-row-label { min-width: 0; overflow-wrap: anywhere; opacity: .72; }
        .gio-order-complete-row-amount { font-weight: 600; white-space: nowrap; }
        .gio-order-complete-total { margin: 0; padding: .625rem 0 0; border-top: var(--gio-border-width) solid var(--gio-border); font-size: 1.2em; font-weight: 700; }
        .gio-order-complete-total .gio-order-complete-row-label { opacity: 1; }
        .gio-order-complete-paid { display: inline-block; margin-left: .5rem; padding: .125rem .5rem; border-radius: 999px; background: #dcfce7; color: #166534; font-size: .75em; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; vertical-align: middle; }
        .gio-order-complete-deliver-to { box-sizing: border-box; width: 100%; min-width: 0; margin: 0; text-align: left; font-size: .875em; overflow-wrap: anywhere; }
        .gio-order-complete-eyebrow { margin: 0 0 .25rem; font-size: .6875rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; opacity: .7; }
        .gio-order-complete-deliver-to-line { margin: 0; }
        .gio-order-complete-deliver-to-name { font-weight: 600; }
        .gio-order-complete-deliver-to-note { margin: .375rem 0 0; opacity: .72; }
        .gio-order-complete-note { min-width: 0; margin: 0; font-size: .875em; opacity: .72; overflow-wrap: anywhere; }
        .gio-order-complete-actions { box-sizing: border-box; width: 100%; min-width: 0; display: flex; flex-direction: column; gap: .625rem; }
        .gio-order-complete-button { box-sizing: border-box; display: flex; justify-content: center; align-items: center; padding: .875rem 1.25rem; border-radius: var(--gio-button-radius); background: var(--gio-button-background); color: var(--gio-button-text); text-decoration: none; font-weight: 700; font-size: .9375em; }
        .gio-order-complete-button-secondary { background: transparent; color: inherit; border: var(--gio-border-width) solid var(--gio-border); }
        @media (max-width: 30rem) {
        .gio-order-complete { padding: 1.5rem .75rem; }
        .gio-order-complete-card { padding: 1.5rem 1.125rem; }
        }
        {!! $layout->styleRules() !!}
    </style>
</head>
<body class="gio-order-complete {{ $layout->modifierClass() }}">
    {{-- The one tick, drawn inline wherever a mark says "done": the badge, the first step, the first tile. --}}
    @php($tick = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>')
    <main class="gio-order-complete-card">
        {{--
            The head: the tick, the words and the order number, in one box so
            that a layout can lay them out as a strip or a column without
            moving them in the bytes. The status line is the one slot here a
            layout may or may not draw; only a Page arranged in a layout that
            draws it is asked what it says, and the layout's block styles it.
        --}}
        <header class="gio-order-complete-head">
            <div class="gio-order-complete-badge" aria-hidden="true">{!! $tick !!}</div>

            <div class="gio-order-complete-words">
                @if ($layout->drawsStatusLine())
                    <p class="gio-order-complete-status">{{ $words->statusLine() }}</p>
                @endif
                <h1 class="gio-order-complete-headline">{{ $words->headline() }}</h1>
                <p class="gio-order-complete-message">{!! nl2br(e($words->message())) !!}</p>
            </div>

            {{--
                The capture time is the one fact a layout may add to the
                number: a tracker that starts at "Order placed" starts from a
                real time. Written in the app's own timezone, machine-readable
                for a browser that wants to show it in the Customer's.
            --}}
            <p class="gio-order-complete-number">Order {{ $order->order_number }}@if ($layout->drawsCaptureTime()) <time class="gio-order-complete-capture-time" datetime="{{ $order->created_at->toIso8601String() }}">{{ $order->created_at->format('j M Y, g:i A') }}</time>@endif</p>
        </header>

        {{--
            The slots a layout may have of its own, under the head: a row of
            steps saying what happens next, or a set of tiles. Each is a fixed
            number of positions read from the Page's Config, with the
            built-in words in any the Merchant left alone, and only the
            layout that draws one is asked what it says. The first position
            is the one already done — the Order is placed — and is marked
            with the tick; the rest are numbered.
        --}}
        @if ($layout->drawsSteps())
            <ol class="gio-order-complete-steps" aria-label="What happens next">
                @foreach ($words->steps() as $step)
                    <li class="gio-order-complete-step">
                        <span class="gio-order-complete-step-mark" aria-hidden="true">@if ($loop->first){!! $tick !!}@else{{ $loop->iteration }}@endif</span>
                        <div class="gio-order-complete-step-words">
                            <p class="gio-order-complete-step-label">{{ $step['label'] }}</p>
                            <p class="gio-order-complete-step-timing">{{ $step['timing'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif

        @if ($layout->drawsTiles())
            <ul class="gio-order-complete-tiles">
                @foreach ($words->tiles() as $tile)
                    <li class="gio-order-complete-tile">
                        <span class="gio-order-complete-tile-mark" aria-hidden="true">@if ($loop->first){!! $tick !!}@else{{ $loop->iteration }}@endif</span>
                        <h2 class="gio-order-complete-tile-title">{{ $tile['title'] }}</h2>
                        <p class="gio-order-complete-tile-line">{!! nl2br(e($tile['line'])) !!}</p>
                    </li>
                @endforeach
            </ul>
        @endif

        {{--
            The fixed slots every layout draws, in labels nobody can change,
            so the page can never say something different from what the
            Order recorded.
        --}}
        <div class="gio-order-complete-summary">
            @foreach ($order->lines as $line)
                <p class="gio-order-complete-row">
                    <span class="gio-order-complete-row-label">{{ $line->product_name }} × {{ $line->quantity }}</span>
                    <span class="gio-order-complete-row-amount">{{ \App\Support\Money::format($line->line_total) }}</span>
                </p>
            @endforeach
            <p class="gio-order-complete-row">
                <span class="gio-order-complete-row-label">Subtotal</span>
                <span class="gio-order-complete-row-amount">{{ \App\Support\Money::format($order->subtotal) }}</span>
            </p>
            {{--
                Named after the arrangement that was agreed, in the words the
                customer chose it by. Absent altogether where the page charged
                for no delivery: a "৳0.00" row invites a question about a figure
                that was never part of the order.

                Read off the Order rather than off the Page it came from. An
                Order taken before delivery was an arrangement carries a charge
                and no name to put against it, and the row it is owed says
                "Delivery" — the word that stood there on the day.
            --}}
            @if ($order->delivery_label !== null || $order->delivery_charge > 0)
                <p class="gio-order-complete-row">
                    <span class="gio-order-complete-row-label">{{ $order->delivery_label ?? 'Delivery' }}</span>
                    <span class="gio-order-complete-row-amount">{{ \App\Support\Money::format($order->delivery_charge) }}</span>
                </p>
            @endif
            {{--
                How the customer said they would pay, in the words the
                checkout offered it under. Read off the Order, not the Page:
                the method chosen on the day is what the call and the courier
                go by, whatever the Page offers now.
            --}}
            <p class="gio-order-complete-row">
                <span class="gio-order-complete-row-label">Payment</span>
                <span class="gio-order-complete-row-amount">{{ $order->payment_method->getLabel() }}@if ($order->isPaid()) <span class="gio-order-complete-paid">Paid</span>@endif</span>
            </p>
            <p class="gio-order-complete-row gio-order-complete-total">
                <span class="gio-order-complete-row-label">{{ $order->payment_method->isOnline() ? 'Total' : 'Pay on delivery' }}</span>
                <span class="gio-order-complete-row-amount">{{ \App\Support\Money::format($order->total) }}</span>
            </p>
        </div>

        {{--
            Where the Order is going, for the browser that placed it and nobody
            else. Omitted rather than blanked for any other reader, so the
            layout closes up and gives no hint that a block is missing. The
            address and district come from the Order as captured, which may
            be blank on a Page that stopped asking; a blank line is skipped.
        --}}
        @if ($placedByThisBrowser)
            <section class="gio-order-complete-deliver-to" aria-labelledby="deliver-to">
                <h2 id="deliver-to" class="gio-order-complete-eyebrow">Deliver to</h2>
                <p class="gio-order-complete-deliver-to-line gio-order-complete-deliver-to-name">{{ $order->customer_name }}</p>
                <p class="gio-order-complete-deliver-to-line">{{ $order->customer_phone }}</p>
                @if (filled($order->customer_address))
                    <p class="gio-order-complete-deliver-to-line">{{ $order->customer_address }}</p>
                @endif
                @if (filled($order->customer_district))
                    <p class="gio-order-complete-deliver-to-line">{{ $order->customer_district }}</p>
                @endif
                @if (($note = $order->deliveryNote()) !== null)
                    <p class="gio-order-complete-deliver-to-note">Note: {{ $note }}</p>
                @endif
            </section>
        @endif

        <p class="gio-order-complete-note">{!! nl2br(e($words->note())) !!}</p>

        <nav class="gio-order-complete-actions" aria-label="What next">
            <a class="gio-order-complete-button" href="{{ $words->primaryButtonLink() }}">{{ $words->primaryButtonLabel() }}</a>
            @if ($words->drawsSecondaryButton())
                <a class="gio-order-complete-button gio-order-complete-button-secondary" href="{{ $words->secondaryButtonLink() }}">{{ $words->secondaryButtonLabel() }}</a>
            @endif
        </nav>
    </main>
</body>
</html>
