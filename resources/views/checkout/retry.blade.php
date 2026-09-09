{{--
    The Retry Page: where a customer lands with an Unpaid Order.

    Ours like the Order Complete Page, and never cached: addressed per order and
    read while the customer decides. It shows the Order and not the Customer,
    for the reason the Order Complete Page does — this URL is a bearer handle that
    ends up in history and in a gateway's logs — and it draws the Page's
    Payment Methods again, so the customer finishes without re-typing a thing.

    A plain form with a real action: a customer whose script never runs can
    still switch to cash on delivery. The chosen method is written onto the
    Order and the customer is sent where a capture under it would send them.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Order {{ $order->order_number }} is unpaid</title>
    <style>
        :root { color-scheme: light; }
        body { margin: 0; padding: 1.5rem 1rem; background: #f9fafb; color: #111827; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; line-height: 1.5; }
        .card { box-sizing: border-box; max-width: 30rem; margin: 0 auto; padding: 1.5rem; background: #fff; border: 1px solid #e5e7eb; border-radius: .5rem; }
        .mark { width: 3rem; height: 3rem; margin: 0 auto .75rem; display: block; }
        h1 { margin: 0 0 .25rem; font-size: 1.375rem; text-align: center; }
        .lede { margin: 0 0 1.25rem; text-align: center; color: #4b5563; }
        .reference { margin: 0 0 1.25rem; padding: .75rem; text-align: center; background: #f3f4f6; border-radius: .375rem; }
        .reference-label { display: block; font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; color: #6b7280; }
        .reference-number { font-size: 1.5rem; font-weight: 700; letter-spacing: .1em; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        table { width: 100%; border-collapse: collapse; font-size: .9375rem; }
        th { text-align: left; font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; color: #6b7280; padding: 0 0 .5rem; font-weight: 600; }
        td { padding: .5rem 0; border-top: 1px solid #f3f4f6; vertical-align: top; }
        .numeric { text-align: right; white-space: nowrap; }
        .totals { margin: 1rem 0 0; padding: .75rem 0 0; border-top: 1px solid #e5e7eb; font-size: .9375rem; }
        .row { display: flex; justify-content: space-between; gap: 1rem; margin: 0 0 .375rem; }
        .grand { margin: .5rem 0 0; font-size: 1.125rem; font-weight: 700; }
        fieldset { margin: 1.5rem 0 0; padding: 0; border: 0; }
        legend { padding: 0; font-weight: 600; margin: 0 0 .5rem; }
        .choice { display: flex; align-items: center; gap: .75rem; padding: .75rem; margin: 0 0 .5rem; border: 1px solid #e5e7eb; border-radius: .375rem; cursor: pointer; }
        .choice:has(:checked) { border-color: #2563eb; box-shadow: 0 0 0 1px #2563eb; }
        .choice input { margin: 0; }
        .error { margin: 0 0 .75rem; color: #b91c1c; font-size: .875rem; }
        button { display: block; width: 100%; box-sizing: border-box; margin: 1rem 0 0; padding: .875rem 1rem; font: inherit; font-weight: 600; color: #fff; background: #2563eb; border: 0; border-radius: .375rem; cursor: pointer; }
        .note { margin: 1.25rem 0 0; padding: .75rem; background: #fffbeb; border-radius: .375rem; font-size: .875rem; color: #92400e; text-align: center; }
    </style>
</head>
<body>
    <div class="card">
        <svg class="mark" viewBox="0 0 48 48" fill="none" aria-hidden="true">
            <circle cx="24" cy="24" r="22" fill="#fef3c7"/>
            <path d="M24 14v12" stroke="#d97706" stroke-width="4" stroke-linecap="round"/>
            <circle cx="24" cy="33" r="2.5" fill="#d97706"/>
        </svg>

        <h1>Your order is saved but not yet paid</h1>
        <p class="lede">Choose how you would like to pay to finish.</p>

        <p class="reference">
            <span class="reference-label">Order number</span>
            <span class="reference-number">{{ $order->order_number }}</span>
        </p>

        <table>
            <thead>
                <tr>
                    <th scope="col">Item</th>
                    <th scope="col" class="numeric">Qty</th>
                    <th scope="col" class="numeric">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($order->lines as $line)
                    <tr>
                        <td>{{ $line->product_name }}</td>
                        <td class="numeric">{{ $line->quantity }}</td>
                        <td class="numeric">{{ \App\Support\Money::format($line->line_total) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="totals">
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
            <p class="row grand">
                <span>Total</span>
                <span>{{ \App\Support\Money::format($order->total) }}</span>
            </p>
        </div>

        {{--
            The Page's methods as it offers them now, the Order's own method
            picked where it is still among them so a customer retrying the
            same one needs no extra tap, else the first. The server refuses a
            name the Page does not offer.
        --}}
        <form method="post" action="{{ route('checkout.retry.choose', $order->order_number) }}">
            @csrf

            <fieldset>
                <legend>Pay by</legend>

                @php($picked = in_array($order->payment_method, $methods, true) ? $order->payment_method : $methods[0])

                @foreach ($methods as $method)
                    <label class="choice">
                        <input type="radio" name="payment_method" value="{{ $method->value }}" @checked($method === $picked) required>
                        <span>{{ $method->getLabel() }}</span>
                    </label>
                @endforeach
            </fieldset>

            @error('payment_method')
                <p class="error">{{ $message }}</p>
            @enderror

            <button type="submit">Continue</button>
        </form>

        <p class="note">Nothing has been charged. Your order is kept while you decide.</p>
    </div>
</body>
</html>
