{{--
    The Order Complete Page: where a Customer lands once their Order is settled
    as far as the Checkout is concerned — the mockup's Done screen. It draws
    that one state; the controller sends an Unpaid Order to the Retry Page
    before this is reached.

    This document is ours, so it carries its own styles inline: no stylesheet,
    font or picture from anywhere. A page carrying a Customer's details makes
    no request they did not ask for, and the one exception is the one the
    Merchant asked for by switching a Provider on — the Pixel in `$snippet`,
    which is absent entirely where there is none. It is never cached: it is
    addressed per order and hit once.

    It shows the Customer only to the browser that placed the Order. This URL
    ends up in browser history and Referer headers, so the address unlocks
    nothing; what does is the cookie handed to that browser, which the
    controller reads into `$placedByThisBrowser` (ADR-0014). Anyone else sees
    the Order and not the Customer: shown less, never refused.

    Every figure is the Order's own, drawn in Bangla digits; the order number
    stays Latin, because it is read out on the phone as it is printed.
--}}
@php
    use App\Support\PhoneNumber;
    use App\Support\Storefront\Bangla;

    $payingOnDelivery = ! $order->isPaid() && ! $order->payment_method->isOnline();
@endphp
<!doctype html>
<html lang="bn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>অর্ডার পেয়েছি · {{ $order->order_number }}</title>
@if ($snippet !== '')
{!! $snippet !!}
@endif
    <style>
        :root { color-scheme: light; }
        body { margin: 0; padding: clamp(40px, 7vw, 96px) 16px; background: #fff; color: #16261D; font-family: "Anek Bangla", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; line-height: 1.5; }
        main { box-sizing: border-box; max-width: 520px; margin: 0 auto; display: flex; flex-direction: column; align-items: center; text-align: center; gap: 12px; }
        .tick { width: 64px; height: 64px; border-radius: 50%; background: #EDF3EE; display: flex; align-items: center; justify-content: center; font-size: 28px; font-weight: 700; color: #2E6B45; }
        h1 { margin: 8px 0 0; font-weight: 600; font-size: clamp(24px, 3.4vw, 36px); line-height: 1.4; }
        .lead { margin: 0; font-size: 15px; line-height: 1.8; color: #5C6660; }
        .card { box-sizing: border-box; width: 100%; margin-top: 12px; border: 1px solid #E2E6E2; border-radius: 14px; padding: 18px 20px; display: flex; flex-direction: column; gap: 10px; text-align: left; }
        .row { display: flex; justify-content: space-between; gap: 12px; margin: 0; font-size: 14px; }
        .row > span:first-child { color: #5C6660; }
        .row b { font-weight: 600; }
        .lines { display: flex; flex-direction: column; gap: 8px; padding-bottom: 10px; border-bottom: 1px solid #EEF1EE; }
        .lines .row > span:first-child { color: #16261D; }
        .number { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; letter-spacing: .04em; }
        .free { color: #2E6B45; }
        .saving b { color: #2E6B45; }
        .paid { margin-left: 6px; padding: 2px 8px; border-radius: 999px; background: #EDF3EE; color: #1F4D31; font-size: 12px; font-weight: 600; }
        .total { align-items: baseline; padding-top: 10px; border-top: 1px solid #EEF1EE; }
        .total > span:first-child { color: #16261D; font-weight: 500; }
        .total b { font-size: 22px; font-weight: 700; color: #1F4D31; }
        .cod { margin: 0; font-size: 13px; color: #8A5A22; text-align: right; }
        .deliver-to h2 { margin: 0 0 2px; font-size: 13px; font-weight: 600; color: #5C6660; }
        .deliver-to p { margin: 0; font-size: 14px; }
        .actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; margin-top: 10px; }
        .button { display: inline-block; text-decoration: none; font-weight: 600; font-size: 15px; padding: 12px 22px; border-radius: 10px; border: 1px solid #2E6B45; }
        .primary { background: #2E6B45; color: #fff; }
        .secondary { color: #2E6B45; }
    </style>
</head>
<body>
    <main>
        <div class="tick" aria-hidden="true">✓</div>
        <h1>ধন্যবাদ, আপনার অর্ডার পেয়েছি</h1>
        <p class="lead">
            @if ($placedByThisBrowser)
                আমাদের প্রতিনিধি শীঘ্রই {{ Bangla::digits(PhoneNumber::asDialled($order->customer_phone) ?? $order->customer_phone) }} নম্বরে ফোন করে অর্ডার নিশ্চিত করবেন।
            @else
                আমাদের প্রতিনিধি শীঘ্রই ফোন করে অর্ডার নিশ্চিত করবেন।
            @endif
        </p>

        {{--
            Fixed labels nobody can change, so the page can never say
            something different from what the Order recorded.
        --}}
        <div class="card">
            <p class="row"><span>অর্ডার নম্বর</span><b class="number">{{ $order->order_number }}</b></p>

            <div class="lines">
                @foreach ($order->lines as $line)
                    <p class="row">
                        <span>{{ $line->banglaDescription() }}</span>
                        <b>{{ Bangla::taka($line->line_total) }}</b>
                    </p>
                @endforeach
            </div>

            @if ($order->isRetail())
                <p class="row"><span>মোট প্যাকেট</span><b>{{ Bangla::number((int) $order->lines->sum('quantity')) }}টি</b></p>
            @else
                <p class="row"><span>মোট পরিমাণ</span><b>{{ Bangla::number($order->total_kilograms) }} কেজি</b></p>
            @endif
            <p class="row"><span>চায়ের দাম</span><b>{{ Bangla::taka($order->subtotal) }}</b></p>
            <p class="row">
                <span>ডেলিভারি চার্জ</span>
                @if ($order->delivery_charge === 0)
                    <b class="free">ফ্রি</b>
                @else
                    <b>{{ Bangla::taka($order->delivery_charge) }}</b>
                @endif
            </p>
            <p class="row">
                <span>টাকা পরিশোধ</span>
                <b>{{ $order->payment_method->banglaLabel() }}@if ($order->isPaid())<span class="paid">পরিশোধিত</span>@endif</b>
            </p>
            <p class="row total">
                <span>{{ $payingOnDelivery ? 'মোট দাম (ক্যাশ অন ডেলিভারি)' : 'মোট দাম' }}</span>
                <b>{{ Bangla::taka($order->total) }}</b>
            </p>
            @if (($saving = $order->savingShown()) > 0)
                <p class="row saving"><span>আপনার সাশ্রয়</span><b>{{ Bangla::taka($saving) }}</b></p>
            @endif
            @if ($payingOnDelivery)
                <p class="cod">চা হাতে পেয়ে টাকা দিন</p>
            @endif
        </div>

        {{-- Where the Order is going, for the browser that placed it and nobody else. --}}
        @if ($placedByThisBrowser)
            <section class="card deliver-to" aria-labelledby="deliver-to">
                <h2 id="deliver-to">যেখানে পাঠানো হবে</h2>
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
                    <p>নোট: {{ $note }}</p>
                @endif
            </section>
        @endif

        <div class="actions">
            <a class="button primary" href="{{ $myOrdersUrl }}">আমার অর্ডার দেখুন</a>
            <a class="button secondary" href="{{ url('/') }}">হোমে ফিরে যান</a>
        </div>
    </main>
</body>
</html>
