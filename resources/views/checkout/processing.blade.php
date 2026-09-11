{{--
    The Processing Page: where a gateway sends a customer back.

    Ours like the Order Complete Page, and never cached. It says the payment is
    being verified, with a loading indicator, and has the browser call the
    Verification once drawn: what the gateway wrote into the return decides
    nothing, and the customer sees a "verifying" state rather than a blank
    wait. It prints nothing about the customer, and only the invoice
    reference about the Order, because this is the one address a gateway's
    own logs hold. It carries no Pixel either, for the same reason, and
    because it is transient: it is drawn for a second or two and moved on
    from, and a Purchase reported here would be reported before the money
    was known to have moved.

    A client whose script never runs follows the meta refresh to the same
    Verification, which answers with a redirect instead. Both are harmless to
    repeat: a Payment is asked about once, and a settled one is only sent on.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta http-equiv="refresh" content="8;url={{ $verifyUrl }}">
    <title>Verifying your payment</title>
    <style>
        :root { color-scheme: light; }
        body { margin: 0; padding: 1.5rem 1rem; background: #f9fafb; color: #111827; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; line-height: 1.5; }
        .card { box-sizing: border-box; max-width: 30rem; margin: 0 auto; padding: 2rem 1.5rem; background: #fff; border: 1px solid #e5e7eb; border-radius: .5rem; text-align: center; }
        .spinner { width: 3rem; height: 3rem; margin: 0 auto 1rem; border: 4px solid #e5e7eb; border-top-color: #2563eb; border-radius: 50%; animation: spin .8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        @media (prefers-reduced-motion: reduce) { .spinner { animation-duration: 2s; } }
        h1 { margin: 0 0 .25rem; font-size: 1.375rem; }
        .lede { margin: 0 0 1.25rem; color: #4b5563; }
        .reference { margin: 0; padding: .75rem; background: #f3f4f6; border-radius: .375rem; }
        .reference-label { display: block; font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; color: #6b7280; }
        .reference-number { font-size: 1.125rem; font-weight: 700; letter-spacing: .1em; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        .note { margin: 1.25rem 0 0; font-size: .875rem; color: #6b7280; }
        .note a { color: inherit; }
    </style>
</head>
<body>
    <div class="card" role="status" aria-live="polite">
        <div class="spinner" aria-hidden="true"></div>

        <h1>We are verifying your payment</h1>
        <p class="lede">This takes a moment. Please do not close this page or pay again.</p>

        <p class="reference">
            <span class="reference-label">Payment reference</span>
            <span class="reference-number">{{ $payment->invoice_reference }}</span>
        </p>

        <p class="note">Not moving on? <a href="{{ $verifyUrl }}">Continue</a>.</p>
    </div>

    <script>
        (function () {
            var url = @json($verifyUrl);

            /**
             * Asked once the page is drawn, and again after a pause if the
             * call itself fails: the answer is where to go, and the
             * Verification behind it is safe to repeat.
             */
            function verify() {
                fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                })
                    .then(function (response) { return response.json(); })
                    .then(function (body) {
                        if (body && body.redirect) {
                            window.location.replace(body.redirect);
                        } else {
                            throw new Error('No destination');
                        }
                    })
                    .catch(function () { window.setTimeout(verify, 3000); });
            }

            verify();
        })();
    </script>
</body>
</html>
