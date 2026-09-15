{{--
    The Placeholder: what the Site Root serves when no Front Page is designated.

    A Placeholder is not a Page. It has no Slug, no Checkout, no Products and no
    Pixel; no Merchant authors it or takes an Order on it, and the Site Root is
    the only address it is reachable at.

    It is written to the static cache like anything else, which settles two
    things about how it is built.

    It is one document for everyone. There is no session on this path and the
    bytes on disk are the same bytes for every visitor, so it cannot greet a
    signed-in Merchant differently from a customer — which is why the line that
    is really for the Merchant is said out loud, in the open.

    And it carries no @vite. A Vite asset is addressed by a content hash that a
    rebuild changes, and these bytes are frozen on disk until something
    invalidates them — so a cached Placeholder would go on asking for a
    stylesheet that no longer exists. Everything it needs is inline, which also
    makes it a single request.

    noindex because the same document stands on every installation that has not
    chosen a Front Page yet. Thousands of byte-identical pages across unrelated
    domains is the shape a search engine penalises, and it is the Merchant's
    domain that wears it.

    No link to the admin panel: this is a public address, and pointing every
    visitor and crawler at where the panel lives buys a convenience the Merchant
    — who knows perfectly well where they just came from — does not need.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{{ config('app.name') }}</title>
<style>
:root {
    color-scheme: light dark;
    --ink: #14161a;
    --muted: #5c6470;
    --line: #e4e7ec;
    --ground: #fbfbfc;
    --card: #ffffff;
    --accent: #1f6feb;
}
@media (prefers-color-scheme: dark) {
    :root {
        --ink: #eceef2;
        --muted: #98a1af;
        --line: #262b33;
        --ground: #0e1013;
        --card: #15181d;
        --accent: #6ea8fe;
    }
}
* { box-sizing: border-box; }
body {
    margin: 0;
    padding: 0 20px;
    background: var(--ground);
    color: var(--ink);
    font: 16px/1.6 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    -webkit-font-smoothing: antialiased;
}
.wrap { max-width: 760px; margin: 0 auto; padding-block: 72px 96px; }
.mark {
    display: inline-block;
    font-size: 12px;
    letter-spacing: .14em;
    text-transform: uppercase;
    color: var(--muted);
    border: 1px solid var(--line);
    border-radius: 999px;
    padding: 5px 12px;
}
h1 {
    margin: 26px 0 0;
    font-size: clamp(30px, 6vw, 44px);
    line-height: 1.15;
    letter-spacing: -0.02em;
    font-weight: 650;
}
.lede { margin: 16px 0 0; font-size: clamp(17px, 2.4vw, 19px); color: var(--muted); max-width: 56ch; }
.notice {
    margin: 36px 0 0;
    padding: 18px 20px;
    background: var(--card);
    border: 1px solid var(--line);
    border-left: 3px solid var(--accent);
    border-radius: 10px;
}
.notice strong { display: block; margin-bottom: 4px; font-weight: 600; }
.notice span { color: var(--muted); }
h2 {
    margin: 56px 0 0;
    font-size: 13px;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--muted);
    font-weight: 600;
}
ul { list-style: none; margin: 20px 0 0; padding: 0; display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); }
li { background: var(--card); border: 1px solid var(--line); border-radius: 10px; padding: 18px 20px; }
li b { display: block; font-weight: 600; margin-bottom: 5px; }
li span { color: var(--muted); font-size: 15px; }
footer { margin-top: 64px; padding-top: 22px; border-top: 1px solid var(--line); color: var(--muted); font-size: 14px; }
</style>
</head>
<body>
<div class="wrap">
    <span class="mark">{{ config('app.name') }}</span>

    <h1>Landing pages that answer before the framework wakes up.</h1>

    <p class="lede">
        A published page is written to disk once and served by the webserver reading a single
        file. No database query, no boot, no framework in the request at all — which is what
        paid traffic costs you the least to land on.
    </p>

    <div class="notice">
        <strong>Nothing is being served here yet.</strong>
        <span>No front page has been chosen for this address. Pick one under Settings &rarr; General, and it will load here instead of this page.</span>
    </div>

    <h2>What this runs on</h2>

    <ul>
        <li>
            <b>A static page cache</b>
            <span>Every published page is frozen to disk with a pre-compressed copy beside it, and cleared the moment it is edited.</span>
        </li>
        <li>
            <b>Checkout without a cart</b>
            <span>One order, one submission. Every page carries its own form, its own questions and its own delivery pricing.</span>
        </li>
        <li>
            <b>Cash on delivery and online</b>
            <span>Take the money at the door or through a gateway, with retries handled and nothing captured twice.</span>
        </li>
        <li>
            <b>Couriers that book themselves</b>
            <span>Hand a parcel to a courier from the order screen, and let a sweep chase its status without anyone watching.</span>
        </li>
        <li>
            <b>Stock that traces back</b>
            <span>Every figure resolves to rows a person can read, moved when a parcel is booked and never guessed at.</span>
        </li>
        <li>
            <b>Reporting and tracking</b>
            <span>Visits, conversion and per-page sales, with a pixel injected into every page rather than pasted into each one.</span>
        </li>
    </ul>

    <footer>{{ config('app.name') }} &middot; this address is not indexed until a front page is chosen.</footer>
</div>
</body>
</html>
