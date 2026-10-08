{{--
    The Order Complete Page: where a Customer lands once their Order is settled
    as far as the Checkout is concerned. It draws that one state; the
    controller sends an Unpaid Order to the Retry Page before this is reached.

    This document is ours, so it carries its own styles inline: no stylesheet,
    font or picture from anywhere. A page carrying a Customer's details makes
    no request they did not ask for, and the one exception is the one the
    Merchant asked for by configuring a Destination — the Pixel in `$snippet`,
    which is absent entirely where there is none. It is never cached: it is
    addressed per order and hit once.

    It shows the Customer only to the browser that placed the Order. This URL
    ends up in browser history and Referer headers, so the address unlocks
    nothing; what does is the cookie handed to that browser, which the
    controller reads into `$placedByThisBrowser` (ADR-0014). Anyone else sees
    the Order and not the Customer: shown less, never refused.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Order placed · Order {{ $order->order_number }}</title>
@if ($snippet !== '')
{!! $snippet !!}
@endif
    <style>
        :root { color-scheme: light; }
        body { margin: 0; padding: 3.5rem 1.5rem; background: #f7f8fa; color: #111318; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; line-height: 1.5; }
        .card { box-sizing: border-box; max-width: 32.5rem; margin: 0 auto; padding: 2.25rem 2rem; background: #fff; border: 1px solid #e5e7eb; display: flex; flex-direction: column; gap: 1.125rem; }
        h1 { margin: 0; font-size: 1.75rem; text-align: center; }
        .number { margin: 0; text-align: center; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-weight: 600; }
        .summary { margin: 0; padding: 1.125rem 0 0; border-top: 1px solid #e5e7eb; display: flex; flex-direction: column; gap: .625rem; font-size: .875rem; }
        .row { display: flex; justify-content: space-between; gap: .75rem; margin: 0; }
        .total { padding: .625rem 0 0; border-top: 1px solid #e5e7eb; font-size: 1.05rem; font-weight: 700; }
        .paid { margin-left: .5rem; padding: .125rem .5rem; border-radius: 999px; background: #dcfce7; color: #166534; font-size: .75em; font-weight: 600; }
        .deliver-to { margin: 0; font-size: .875rem; }
        .deliver-to p { margin: 0; }
        .eyebrow { margin: 0 0 .25rem; font-size: .6875rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; opacity: .7; }
        .note { margin: 0; font-size: .875rem; opacity: .72; text-align: center; }
    </style>
</head>
<body>
    <main class="card">
        <h1>Order placed</h1>
        <p class="number">Order {{ $order->order_number }}</p>

        {{--
            Fixed labels nobody can change, so the page can never say
            something different from what the Order recorded.
        --}}
        <div class="summary">
            @foreach ($order->lines as $line)
                <p class="row">
                    <span>{{ $line->product_name }} × {{ $line->quantity }}</span>
                    <span>{{ \App\Support\Money::format($line->line_total) }}</span>
                </p>
            @endforeach
            <p class="row">
                <span>Subtotal</span>
                <span>{{ \App\Support\Money::format($order->subtotal) }}</span>
            </p>
            @if ($order->delivery_label !== null || $order->delivery_charge > 0)
                <p class="row">
                    <span>{{ $order->delivery_label ?? 'Delivery' }}</span>
                    <span>{{ \App\Support\Money::format($order->delivery_charge) }}</span>
                </p>
            @endif
            <p class="row">
                <span>Payment</span>
                <span>{{ $order->payment_method->getLabel() }}@if ($order->isPaid())<span class="paid">Paid</span>@endif</span>
            </p>
            <p class="row total">
                <span>{{ $order->payment_method->isOnline() ? 'Total' : 'Pay on delivery' }}</span>
                <span>{{ \App\Support\Money::format($order->total) }}</span>
            </p>
        </div>

        {{-- Where the Order is going, for the browser that placed it and nobody else. --}}
        @if ($placedByThisBrowser)
            <section class="deliver-to" aria-labelledby="deliver-to">
                <h2 id="deliver-to" class="eyebrow">Deliver to</h2>
                <p>{{ $order->customer_name }}</p>
                <p>{{ $order->customer_phone }}</p>
                @if (filled($order->shop_name))
                    <p>{{ $order->shop_name }}</p>
                @endif
                @if (filled($order->customer_address))
                    <p>{{ $order->customer_address }}</p>
                @endif
                <p>{{ $order->customer_district }}</p>
                @if (($note = $order->deliveryNote()) !== null)
                    <p>Note: {{ $note }}</p>
                @endif
            </section>
        @endif

        <p class="note">A representative will call to confirm your order.</p>
    </main>
</body>
</html>
