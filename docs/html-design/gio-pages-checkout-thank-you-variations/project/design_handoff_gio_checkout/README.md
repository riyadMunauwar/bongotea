# Handoff: Gio Pages `[checkout]` Shortcode — 10 Layout Variations

## Overview
Ten checkout section layouts for the Gio Pages landing-page builder. Each renders inside a landing page via the `[checkout]` shortcode (no header/footer). All share the same data model (bundle of products with select/deselect + quantity stepper, customer fields, Inside/Outside Dhaka delivery, payment method, live totals) and end in an **Order Now** link to the separate checkout page. The builder should let the merchant pick a layout (`layout="stacked" | "split" | …`).

## About the Design Files
`Gio Checkout Layouts.dc.html` (+ `support.js`) is a **design reference built in HTML** — a prototype showing intended look and behavior, not production code. Recreate it in the Gio Pages codebase with **raw CSS (no framework, no Tailwind)** and whatever templating/JS the shortcode system already uses. Inline styles in the reference must become CSS classes; the values below are exact.

## Fidelity
**High-fidelity.** Colors, type, spacing, borders and states are final. Implement pixel-perfect. Desktop reference width is 920px; mobile reference is 375px. Layouts must be fluid between (use container queries or media queries as noted).

---

## Design Tokens

### Color
| Token | Value | Use |
|---|---|---|
| `--gp-accent` | `#2451d6` | buttons, selected borders, radio/checkbox accent, focus |
| `--gp-accent-hover` | `#1c43b5` | button hover |
| `--gp-accent-hover-dark` | `#4f79e6` | button hover on dark bars (1f) |
| `--gp-accent-tint` | `#f5f7fe` | selected product row bg |
| `--gp-ink` | `#111318` | primary text, numbered badges, dark total bar (1f), 1i button |
| `--gp-ink-2` | `#4b5563` | field labels, secondary text, inactive segmented text |
| `--gp-ink-3` | `#6b7280` | meta text, section eyebrows |
| `--gp-ink-4` | `#8a90a0` | hints, placeholder tile text |
| `--gp-placeholder` | `#9aa1b1` | input placeholder color |
| `--gp-border` | `#e3e6ec` | card/panel borders, dividers |
| `--gp-border-2` | `#d9dde5` | input borders, unselected option borders |
| `--gp-divider` | `#eef0f3` | hairline row dividers |
| `--gp-surface` | `#ffffff` | cards |
| `--gp-surface-2` | `#f7f8fa` | section backgrounds, summary panels, 1j inputs |
| `--gp-tile` | `#eef0f4` | product image placeholder |
| `--gp-navy` | `#0f1d4f` | 1j dark panel bg |
| `--gp-navy-border` | `#33468a` | 1j borders |
| `--gp-navy-text` | `#9fb0e8` | 1j secondary text |
| `--gp-navy-text-2` | `#c5d0f2` | 1j totals text |
| `--gp-bar-text` | `#b9bec9` | 1f dark bar secondary text; 1i underline fields |
| focus ring | `0 0 0 3px rgba(36,81,214,.15)` | inputs/selects/textarea `:focus` + border-color accent |

### Typography
- Family: `'Hind Siliguri', system-ui, sans-serif` (Google Fonts, weights 400/500/600/700). Covers Bangla + Latin.
- Mono (1i amounts, IDs): `ui-monospace, Menlo, monospace`.
- Scale (px / weight):
  - Section title h3: 15–18 / 700 (1a 16, 1b 15, 1c 18, 1d 17, 1e 15, 1g 16, 1i 20, 1j 18)
  - Eyebrow: 12 / 600 / letter-spacing .08em / uppercase / `--gp-ink-3` (1i field labels: 11 / .06em)
  - Product name: 14 / 600 (1c 15, 1b 13, 1g 13)
  - Product meta: 12 / 400 / `--gp-ink-3`
  - Field label: 12 / 600 / `--gp-ink-2`, 6px below-gap
  - Input text: 14 / 400 (1g 13, 1i 15)
  - Option (radio row) text: 14 (compact grids 13)
  - Totals rows: 13–14 / `--gp-ink-2`; Grand total 17–18 / 700 / ink (1e 22, 1h 24, 1j 22)
  - Order button: 15 / 700 (1g 14)
  - Line-height 1.3 on multi-line names; default elsewhere.

### Spacing & shape
- Radius: `2px` on inputs/buttons/tiles; `0` on cards, panels, option rows; `50%` on step/number badges.
- Borders: 1px everywhere; 2px `--gp-ink` under 1i title; 1px `--gp-ink` above 1i TOTAL.
- No shadows.
- Card outer padding: desktop 28–40px; mobile 20px 16px.
- Section gap inside a card: 24–28px; field grid gap 12–14px; option gap 8–10px.
- Input padding: `11px 12px` (1c/1j `12px`, 1g `10px 12px`). `box-sizing:border-box; width:100%`.
- Radio/checkbox: native, `accent-color: #2451d6`, `margin:0`; checkbox 16×16 (1g 15).
- Stepper: bordered group `1px --gp-border-2`, buttons 30×30 (28 in compact, 22–26 in tiny) with no own border, `−`/`+` glyphs 15–16px; count 26px wide, 13/600 centered. Unselected product → stepper & line total at `opacity:.45`.
- Order button: `min-height:48px`, `padding:14px 20px`, full width where stacked; hover `--gp-accent-hover`; `:active translateY(1px)`; transitions 150ms.
- Product image placeholder: square 40–72px, `--gp-tile`, 1px `--gp-border`, radius 2. Replace with real `<img>`.

---

## Shared data & behavior (all layouts)

**Products** (sample): Herbal Hair Oil 200ml / হারবাল হেয়ার অয়েল ৳490; Neem Face Wash 100ml / নিম ফেস ওয়াশ ৳350; Aloe Vera Gel 150g / অ্যালোভেরা জেল ৳390. Default: first two selected, qty 1 each.

**State per instance**
```
selected: boolean[]   // per product
qty: number[]         // ≥1
delivery: 'inside' | 'outside'   // ৳60 | ৳120, default inside
payment: 'cod' | 'bkash' | 'nagad' | 'card'  // default cod
callBeforeDelivery: boolean (1b, 1f, 1j)
step: 1|2|3 (1d)      open: 1|2|3|0 (1e)
```
**Derived**: `subtotal = Σ selected ? price×qty : 0`; `shipping = inside ? 60 : 120`; `total = subtotal + shipping`; `count = selected.length`. Currency format `৳` + en-IN thousands.

**Rules**
- Toggle checkbox → flips `selected[i]`. `+` on an unselected item also selects it. `−` floors at 1.
- Selected product row: border `--gp-accent`, bg `--gp-accent-tint`. Unselected: border `--gp-border`, bg white.
- Selected radio option box: border `--gp-accent`; unselected `--gp-border-2`.
- Segmented buttons (delivery/payment in 1c, 1f, 1g, 1h): active `bg accent / #fff text`, inactive `bg #fff / --gp-ink-2 text`, 1px `--gp-border-2` group border, 1px left divider.
- Order Now is an `<a href="{checkout_page_url}?…">` carrying the state (product ids, qty, delivery, payment, form fields) as query params or a posted draft — whichever the platform's separate checkout page expects. Several layouts print the live total inside the button.
- Fields (validation on the separate checkout page, but mark required): name (text, required), phone (tel, required, BD 11-digit), address (text/textarea, required), note (optional), plus layout-specific dropdown/number fields listed below.
- Bilingual labels are literal strings — copy them verbatim from the HTML.

**Responsive**: each card is its own container (`container-type:inline-size`). Under `600px` container width: outer/inner padding → `20px 16px`; split columns stack (remove right border, add bottom border); sticky panels go static; total bars stack with full-width button; product rows wrap with the line total on its own row under the name (`padding-left:28px`, 13px); 1i table collapses to `24px 1fr`; step labels (1d) and header summaries (1e) hide; hero h3 → 15px; button 14px `14px 16px`. Field grids use `repeat(auto-fit, minmax(200–220px, 1fr))` so they collapse naturally.

---

## Layouts

### 1a — Stacked
Section bg `--gp-surface-2`, padding 40px 24px. Inner card `max-width:560px`, white, 1px border, padding 28px, column gap 28px. Three numbered sections (24px circle badge, `--gp-ink` bg, white 12/700 digit; h3 16/700 with 10px gap). 1) Product rows (checkbox · 44px tile · name/meta · stepper · 60px right-aligned line total). 2) Field grid `auto-fit minmax(220px,1fr)` gap 14: name, phone, address (span 2), **dropdown** Preferred time (Any/Morning/Afternoon/Evening), **number** Post code, note textarea rows 2 (span 2); then two delivery radio boxes side by side (`flex:1 1 200px`, padding 12px 14px, price bold right). 3) Payment: 4 stacked radio boxes; bKash/Nagad show a small "logo" slot (10px mono, 1px border, 2px 6px). Footer: top border, subtotal / delivery / total (18/700) rows, then full-width Order Now with total in label.

### 1b — Split, sticky summary
White, padding 32px, flex gap 28, wrap. Left `flex:1 1 380px`: Delivery details grid (name, phone, **dropdown** District, **number** Flat no., address textarea span 2, note span 2); Delivery area radios with 2-line labels (name + 12px `--gp-ink-3` ETA "1–2 days"/"3–5 days"); Payment grid `auto-fit minmax(150px,1fr)` 4 radio boxes 13px; checkbox "Call me before delivery". Right `flex:1 1 280px`, `--gp-surface-2` panel, 1px border, padding 20, `position:sticky; top:20px`: product rows in white boxes (checkbox · 40px tile · name + tiny 22px stepper · line total); totals (13px); Order Now; 11px centered hint "অর্ডার কনফার্ম করতে আমরা কল করব".

### 1c — Split reversed, product cards
No card padding; two halves `flex:1 1 380px`. Left `--gp-surface-2`, padding 32, right border: eyebrow "Bundle · N selected"; product cards white with 72px tile, name 15/600 + Bangla, price 15/700 right, bottom row = stepper (28px) + toggle button ("✓ Added / যুক্ত" accent-filled vs "+ Add / যুক্ত করুন" outlined, 12/600, padding 6px 12px); subtotal row pinned bottom. Right white, padding 32, gap 14: h3 18/700 "অর্ডার করতে ফর্মটি পূরণ করুন"; placeholder-labelled inputs (name, phone, address textarea, note); **segmented** delivery (2 buttons, 13/600, padding 11px 8px); payment as plain radios (no boxes, padding 6px 0); Order Now `justify-content:space-between` with total right (16px); 12px centered "Delivery ৳X included".

### 1d — Stepper (3 steps)
Bg `--gp-surface-2`, padding 32px 24px, `max-width:600px`. Step header: 3 clickable buttons (26px circle: done/current → accent bg white text; upcoming → white bg, `--gp-border-2` border, `--gp-ink-3` text; label 13/600, current = ink, others ink-3), 1px connector lines (accent when previous step done). Panel: white, 1px border, padding 24, `min-height:300px`, gap 16. Step 1: h3 + product rows (48px tile, "৳X each") + footer "N items · subtotal" and **Next →** button (accent, 12px 22px, 14/700). Step 2: h3 + field grid (name, phone, address textarea, note) + delivery radios + Back (outlined, `--gp-ink-2`) / Next. Step 3: h3 + payment grid `minmax(140px,1fr)` + summary box (`--gp-surface-2`, 14px padding, rows + 17/700 total) + Back / Order Now (flex:1). Only current step's content renders.

### 1e — Accordion
White, padding 32px 24px; `max-width:620px`, single 1px border wrapper. Three header buttons (full width, padding 16px 18px, 15/700, bg `--gp-surface-2` when open else white, top border on 2nd/3rd) with right-aligned summary (13/500 ink-3: "2 items · ৳840", "Inside Dhaka · ৳60", payment name) and ▲/▼ 12px ink-4. Clicking toggles; only one open. Bodies padding `0 18px 18px`. Products: compact rows (name · price inline, 28px stepper, 60px total). Delivery: placeholder-labelled grid (name, phone, address span 2, **dropdown** Thana, note) + radios. Payment: `minmax(140px,1fr)` grid. Footer bar `--gp-surface-2`, top border, padding 16px 18px: left "মোট / Total incl. delivery ৳X" 12px + total 22/700; Order Now `flex:1 1 200px`, padding 13px 28px.

### 1f — Bento grid
Bg `--gp-surface-2`, padding 28. Grid `auto-fit minmax(280px,1fr)` gap 14. Cell 01 (row-span 2): eyebrow "01 · প্রোডাক্ট"; product blocks = checkbox row (56px tile, name, Bangla, price 14/700) + "পরিমাণ / Qty" label with 28px stepper right. Cell 02: eyebrow "02 · আপনার তথ্য"; name, phone, address textarea, 2-col row of **dropdown** Size (S/M/L/XL) + **number** Age, note. Cell 03: eyebrow "03 · ডেলিভারি ও পেমেন্ট"; segmented delivery (10px 8px); 2×2 payment radio grid (labels COD/bKash/Nagad/Card, padding 10, 13px); checkbox "ডেলিভারির আগে কল করবেন". Full-width bar: bg `--gp-ink`, white text, padding 18px 20px; left meta 13px `--gp-bar-text` with bold white values (items/sub, Delivery, Total 18px); Order Now right, hover `#4f79e6`.

### 1g — Compact embed
Bg `--gp-surface-2`, padding 40px 20px, centered card `max-width:400px`, white, padding 20, gap 12. Title row: h3 16/700 "দ্রুত অর্ডার / Quick order" + "N items" 12px right. Product list inside 1px border, rows `10px 12px` with bottom border, 15px checkbox, name 13/600, 22px stepper, 52px total. 2-col name/phone (13px inputs, 10px 12px), address, note. Segmented delivery (12/600, 9px 6px) and **segmented payment** (4 buttons COD/bKash/Nagad/Card, 9px 4px). Totals line "৳X + ৳Y delivery" 13px left, total 16/700 right. Order Now 14/700, padding 13.

### 1h — Chips
White, padding 36, `max-width:640px`, gap 24. Eyebrow "Select items"; chips row (gap 8, wrap): pill-less buttons `9px 14px`, 13/600, prefix "✓"/"+" then name then price at `.7` opacity; selected = accent bg/white, unselected = white/ink with `--gp-border-2`. Selected-items list between top/bottom `--gp-border` lines; rows `12px 0`, hairline `--gp-divider`, 40px tile, 28px stepper, 64px total; empty state 13px ink-4 centered "কোনো প্রোডাক্ট সিলেক্ট করা হয়নি / Nothing selected". Labelled grid (name, phone, address span 2, **dropdown** District, note). Two-column `minmax(260px,1fr)` block: Delivery segmented; Payment plain radios. Footer: top border, padding-top 20; "৳X + ৳Y delivery" 13px + total 24/700; Order Now `flex:1 1 220px`, padding 14px 32px.

### 1i — Invoice
White, padding 40px 32px, `max-width:620px`, gap 28. Title row: h3 20/700 "অর্ডার ফর্ম / Order form" + mono 12px "#GP-0001" right; 2px ink bottom border, padding-bottom 10. Table header row grid `24px 1fr auto 72px`, 11/600 uppercase .08em ink-3, 1px border below; item rows same grid, `12px 0`, hairline `--gp-divider`; qty stepper 24px with mono count; amount mono 14/600 right. Totals right-aligned mono 13px: Subtotal, Delivery, TOTAL 16/700 with 1px ink top border. **Underline-only fields**: no box, `border-bottom:1px #b9bec9`, padding 8px 0, 15px text, transparent bg, label 11/600 uppercase .06em; grid `minmax(220px,1fr)` gap 18px 24px: name, phone, address span 2, **dropdown** Slot, note. Two-column radios `minmax(240px,1fr)`: Delivery (price mono 13 right), Payment. Button: bg `--gp-ink`, hover accent, `justify-content:space-between`, label "অর্ডার কনফার্ম করুন / Place order" + mono total 16/700.

### 1j — Dark panel
No padding; two halves. Left `flex:1 1 340px`, bg `--gp-navy`, white text, padding 32, gap 14: eyebrow `--gp-navy-text`; product rows 1px `--gp-navy-border` (selected: border #fff, bg `rgba(255,255,255,.08)`), checkbox `accent-color:#fff`, 44px tile (bg `#1b2b63`, border navy-border), price 12px navy-text, stepper 26px transparent buttons white glyphs; totals pinned bottom: 1px navy-border top, 13px `--gp-navy-text-2`, total 22/700 white "মোট". Right `flex:1 1 400px`, white, padding 32, gap 14: h3 18/700; inputs bg `--gp-surface-2` padding 12 in `minmax(180px,1fr)` grid (name, phone, address textarea span 2, **dropdown** Time, note); delivery radio boxes `flex:1 1 160px`; payment 4 centered radio boxes `minmax(120px,1fr)` 13/600; checkbox "Call before delivery"; Order Now with total, pinned bottom.

---

## Interactions & Transitions
- Inputs: `border-color .15s, box-shadow .15s`; focus → accent border + ring.
- Buttons/segments: `background .15s, color .15s, border-color .15s`.
- Order link: `background .15s, transform .1s`; active `translateY(1px)`.
- Stepper (1d) and accordion (1e) switch content instantly (no animation).
- Totals update synchronously on any change.
- Hit targets on mobile ≥ 44px for order button and stepper groups (row height); stepper glyph buttons may be 22–30px but the row is tappable.

## Assets
- Font: Hind Siliguri (Google Fonts). Self-host if the builder cannot load external fonts.
- Product images: placeholders only — bind to product image URLs.
- bKash / Nagad logos: placeholder slots in 1a — use official brand marks from the payment providers.

## Files
- `Gio Checkout Layouts.dc.html` — all 10 layouts, desktop (920) + mobile (375) side by side, interactive. Option ids `1a`–`1j` match the sections above.
- `support.js` — runtime for the reference only; do not port.

## Suggested CSS architecture (raw CSS)
```
gp-checkout.css
  :root tokens (above)
  .gpc            container (container-type:inline-size)
  .gpc__card, .gpc__section, .gpc__eyebrow, .gpc__h3
  .gpc__field, .gpc__label, .gpc__input, .gpc__select, .gpc__textarea
  .gpc__product (--selected), .gpc__tile, .gpc__stepper, .gpc__line
  .gpc__option (--selected), .gpc__segment (--active)
  .gpc__totals, .gpc__total, .gpc__order
  .gpc--stacked, --split, --split-rev, --stepper, --accordion, --bento, --compact, --chips, --invoice, --dark  (layout modifiers)
  @container (max-width:600px) { … mobile rules above … }
```
