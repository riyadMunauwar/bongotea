{{--
    The Order Complete Page: where a Customer lands once their Order is settled
    as far as the Checkout is concerned. It draws that one state; the
    controller sends an Unpaid Order to the Retry Page before this is reached.

    Unlike a landing page, this document is ours — the merchant does not author
    it — so it carries its own minimal styles inline. It is never cached: it is
    addressed per order and hit once.

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
    <title>Order {{ $order->order_number }} confirmed</title>
    <style>
        :root { color-scheme: light; }
        body { margin: 0; padding: 1.5rem 1rem; background: #f9fafb; color: #111827; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; line-height: 1.5; }
        .card { box-sizing: border-box; max-width: 30rem; margin: 0 auto; padding: 1.5rem; background: #fff; border: 1px solid #e5e7eb; border-radius: .5rem; }
        .tick { width: 3rem; height: 3rem; margin: 0 auto .75rem; display: block; }
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
        .paid { display: inline-block; margin-left: .5rem; padding: .125rem .5rem; background: #dcfce7; color: #166534; border-radius: 999px; font-size: .75rem; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; }
        .note { margin: 1.25rem 0 0; padding: .75rem; background: #ecfdf5; border-radius: .375rem; font-size: .875rem; color: #065f46; text-align: center; }
        .deliver-to { margin: 1.25rem 0 0; padding: .75rem 0 0; border-top: 1px solid #e5e7eb; font-size: .9375rem; }
        .deliver-to h2 { margin: 0 0 .5rem; font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; color: #6b7280; font-weight: 600; }
        .deliver-to p { margin: 0 0 .25rem; }
        .deliver-to .deliver-to-name { font-weight: 600; }
        .deliver-to .deliver-to-note { margin-top: .5rem; color: #4b5563; }
    </style>
</head>
<body>
    <div class="card">
        <svg class="tick" viewBox="0 0 48 48" fill="none" aria-hidden="true">
            <circle cx="24" cy="24" r="22" fill="#dcfce7"/>
            <path d="M15 24.5l6.5 6.5L33 19" stroke="#16a34a" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>

        <h1>Your order is confirmed</h1>
        <p class="lede">Thank you. Keep the number below handy.</p>

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
            {{--
                Named after the arrangement that was agreed, in the words the
                customer chose it by. Absent altogether where the page charged
                for no delivery: a "৳0.00" row invites a question about a figure
                that was never part of the order.

                Read off the Order rather than off the Page it came from, which
                is why the test is not the island's. An Order taken before
                delivery was an arrangement carries a charge and no name to put
                against it, and the row it is owed says "Delivery" — the word
                that stood there on the day.
            --}}
            @if ($order->delivery_label !== null || $order->delivery_charge > 0)
                <p class="row">
                    <span>{{ $order->delivery_label ?? 'Delivery' }}</span>
                    <span>{{ \App\Support\Money::format($order->delivery_charge) }}</span>
                </p>
            @endif
            {{--
                How the customer said they would pay, in the words the
                checkout offered it under. Read off the Order, not the Page:
                the method chosen on the day is what the call and the courier
                go by, whatever the Page offers now.
            --}}
            <p class="row">
                <span>Payment</span>
                <span>{{ $order->payment_method->getLabel() }}@if ($order->isPaid()) <span class="paid">Paid</span>@endif</span>
            </p>
            <p class="row grand">
                <span>{{ $order->payment_method->isOnline() ? 'Total' : 'Pay on delivery' }}</span>
                <span>{{ \App\Support\Money::format($order->total) }}</span>
            </p>
        </div>

        {{--
            Where the Order is going, for the browser that placed it and nobody
            else. Omitted rather than blanked for any other reader, so the
            page closes up and gives no hint that a block is missing. The
            address and district come from the Order as captured, which may
            be blank on a Page that stopped asking; a blank line is skipped.
        --}}
        @if ($placedByThisBrowser)
            <section class="deliver-to" aria-labelledby="deliver-to">
                <h2 id="deliver-to">Deliver to</h2>
                <p class="deliver-to-name">{{ $order->customer_name }}</p>
                <p>{{ $order->customer_phone }}</p>
                @if (filled($order->customer_address))
                    <p>{{ $order->customer_address }}</p>
                @endif
                @if (filled($order->customer_district))
                    <p>{{ $order->customer_district }}</p>
                @endif
                @if (($note = $order->deliveryNote()) !== null)
                    <p class="deliver-to-note">Note: {{ $note }}</p>
                @endif
            </section>
        @endif

        <p class="note">We will call you shortly on the number you gave to confirm your order.</p>
    </div>
</body>
</html>
