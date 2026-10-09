# Handoff: Bongotea — B2B Bulk Tea Storefront

## Overview
Bongotea (বঙ্গোটি) is a Bangla-language storefront for selling packaged tea **in bulk, by the kilogram**, to small businesses in Bangladesh: tea stalls (টং দোকান), restaurants, hotels, offices/canteens, grocery shops, resellers and caterers.

Core idea: **the more total kilograms a customer orders, the lower the per-kg price** for every tea in the cart. The UX targets users with low digital literacy:
- No account needed to order. Name, phone and address only.
- Cash on delivery. A representative calls to confirm every order.
- Prices are always shown in ৳ with Bangla numerals and update live as the quantity changes.
- The UI keeps nudging toward the next discount tier ("add 3 kg more to save ৳X").

## About the Design Files
`Bongotea.dc.html` is a **design reference built in HTML**: a working prototype that shows the intended look and behavior. It is not production code. Rebuild it in the target codebase using that codebase's framework and patterns. If there is no codebase yet, pick a suitable stack (e.g. Next.js + Tailwind). Open the file directly in a browser to use it (`support.js` must sit next to it).

All copy is in Bangla. Keep it verbatim. The pricing data and logic sit at the bottom of the file (the `<script data-dc-script>` block); treat it as the spec for the calculation rules below.

## Fidelity
**High-fidelity.** Colors, type, spacing, copy and interactions are final. Product photos are placeholders (labeled boxes) to be replaced with real photography.

---

## Pricing — How It Works (critical business logic)

### 1. Segments
Every product belongs to one of three quality segments:

| id | Label | Positioning | Products |
|---|---|---|---|
| `premium` | প্রিমিয়াম | Hand-picked leaf, deep color, strong aroma. Hotels, restaurants, guests | premium, special, royal, orthodox, garden, masala |
| `gold` | গোল্ড | Best balance of quality and price. Works for milk tea and plain tea | gold, blend, classic, dust, milkmix, morning |
| `silver` | সিলভার | Budget daily tea. More cups for less money | strong, regular, daily, saver, canteen, tong |

The header menu "আমাদের চা" lists exactly these three segments.

### 2. Volume tiers (shared by all products)
Tiers are based on the **total kg across the whole cart** (all teas, all segments combined), not per product.

| Tier index | Range |
|---|---|
| 0 | 1–5 kg (regular price) |
| 1 | 6–10 kg |
| 2 | 11–15 kg |
| 3 | 16–20 kg |
| 4 | 21–50 kg |
| 5 | 51+ kg |

```js
const TIERS = [{min:1,max:5},{min:6,max:10},{min:11,max:15},{min:16,max:20},{min:21,max:50},{min:51,max:Infinity}];
tierIndex = totalKg === 0 ? 0 : TIERS.findIndex(t => totalKg >= t.min && totalKg <= t.max);
```

### 3. Per-product discount
Each product has a `base` price (৳/kg at tier 0) and a discount ladder with one step per tier. A ladder is one of two types:
- `pct`: percent off the base. `price = round(base × (1 − step/100))`
- `fixed`: fixed ৳ off per kg. `price = round(base − step)`

```js
{id:'premium', seg:'premium', base:620, disc:{type:'pct',   steps:[0,5,10,13,16,20]}}
{id:'gold',    seg:'gold',    base:550, disc:{type:'fixed', steps:[0,30,60,80,100,120]}}
p.prices = p.disc.steps.map(v => Math.round(p.disc.type === 'pct' ? p.base*(1-v/100) : p.base - v));
```

Full product table (base ৳/kg · type · steps for tiers 0–5):

| id | seg | base | type | steps |
|---|---|---|---|---|
| premium | premium | 620 | pct | 0,5,10,13,16,20 |
| special | premium | 650 | pct | 0,5,8,12,15,18 |
| royal | premium | 680 | pct | 0,5,9,12,15,18 |
| orthodox | premium | 740 | fixed | 0,30,60,90,110,130 |
| garden | premium | 700 | pct | 0,4,8,11,14,17 |
| masala | premium | 690 | fixed | 0,25,50,75,95,115 |
| gold | gold | 550 | fixed | 0,30,60,80,100,120 |
| blend | gold | 530 | fixed | 0,25,50,75,95,115 |
| classic | gold | 520 | pct | 0,5,9,12,15,18 |
| dust | gold | 500 | fixed | 0,25,50,70,85,100 |
| milkmix | gold | 540 | pct | 0,4,8,12,14,17 |
| morning | gold | 510 | fixed | 0,20,45,65,80,95 |
| strong | silver | 460 | fixed | 0,20,40,60,75,90 |
| regular | silver | 440 | pct | 0,4,8,12,15,18 |
| daily | silver | 420 | pct | 0,4,8,11,14,16 |
| saver | silver | 390 | fixed | 0,15,30,45,55,65 |
| canteen | silver | 410 | pct | 0,5,8,11,13,15 |
| tong | silver | 430 | fixed | 0,20,35,50,60,75 |

Discount label: `pct` → "১০% ছাড়", `fixed` → "৳৬০ ছাড়", step 0 → "নিয়মিত দাম".

### 4. Delivery charge (by total cart kg)

| Total kg | Fee |
|---|---|
| 1–5 | ৳120 |
| 6–10 | ৳150 |
| 11–20 | ৳200 |
| 21–50 | ৳250 |
| 51+ | Free (ফ্রি) |

### 5. Cart math
```
totalKg      = Σ qty
tier         = tierIndex(totalKg)
subtotal     = Σ qty × product.prices[tier]
regularTotal = Σ qty × product.prices[0]
savings      = regularTotal − subtotal
delivery     = deliveryFee(totalKg)          // 0 when 51+ kg
grandTotal   = subtotal + delivery
avgRate      = subtotal / totalKg            // shown as "৳/কেজি"
nextTierHint = kg needed to reach next tier, and extra ৳ saved:
               Σ qty × (prices[tier] − prices[tier+1])
freeDelHint  = 51 − totalKg kg more for free delivery
```
Quantity per product is clamped to 0–999 integers.

**Worked example:** Premium 8 kg + Strong 4 kg = 12 kg → tier 2 (11–15 kg).
Premium 620 × 0.90 = ৳558/kg → ৳4,464. Strong 460 − 40 = ৳420/kg → ৳1,680.
Subtotal ৳6,144, delivery (11–20 kg) ৳200, **total ৳6,344**. Regular price would have been ৳6,800, so the customer saves ৳656.

### 6. Number formatting
- Show all numbers in Bangla digits (০–৯): `String(n).replace(/\d/g, d => '০১২৩৪৫৬৭৮৯'[d])`
- Currency: `'৳' + bn(Math.round(n).toLocaleString('en-IN'))` → e.g. ৳৬,৩৪৪ (Indian grouping)
- Tier label: "১১–১৫ কেজি", "৫১+ কেজি"
- Keep raw numbers in state. Format only for display.

---

## Screens / Views
Single-page app with a `page` state: `home | pricing | product | order | info | done | account | about | contact`. Every page change scrolls to the top. Section links (`#products`, `#seg-premium`, `#how`) navigate to Home first if needed, then smooth-scroll with a sticky-header offset (130px on mobile, 170px on desktop).

1. **Header (sticky, every page)**
   - Top row: logo, search (46px tall, 1.5px #2E6B45 border, 10px radius, green submit button), account and cart.
   - Desktop nav (≥1024px): হোম · আমাদের চা ▾ (Premium/Gold/Silver with notes) · দাম হিসাব · কীভাবে অর্ডার করবেন ▾ · যোগাযোগ. Dropdowns open on hover (150ms close delay) or click.
   - Below 1024px: a hamburger menu instead.
2. **Home**
   - Hero carousel: 3 slides, auto-advances every 6.5s, swipe on touch (40px threshold), prev/next buttons and dots (the active dot is 28px wide). The active slide zooms in from scale 1.08.
   - Trust strip (4 items).
   - Segment tiles: one per segment with color, product count and lowest bulk price.
   - Product list grouped by segment (`#seg-<id>`). Cards show the segment pill, a strength bar (1–5), rating, price from / bulk price, a tier price ladder and an add-to-cart stepper.
   - Video reviews (horizontal swipe), text reviews, how-to-order (3 steps), footer.
3. **Pricing page (দাম হিসাব)**. Dedicated page reached from the header nav, the "how to order" dropdown and the footer. It contains:
   - **Single-product calculator**: segment toggle → product chips → kg stepper/slider. Shows rate/kg, total, savings vs regular, discount label and the next-tier hint. Clicking a tier row jumps kg to that tier's minimum.
   - **Mixed-cart calculator** (`bd`): add kg per product across all segments. Shows the tier progress bar, subtotal, delivery, total, savings and the next-tier hint. "অর্ডার করুন" copies these quantities into the cart and opens the Order page. "Reset" clears it.
   - **Price matrix**: products × 6 tiers, grouped by segment. The current cart tier is highlighted.
   - **Delivery chart**: bar per delivery tier, with the active one highlighted and the free tier in gold.
4. **Product detail**: specs (strength 1–5, liquor color, aroma), best-for chips, brewing guide, cups per kg, 3 tips, tier ladder, add to cart and 3 other products.
5. **Order (bulk cart)**: rows grouped by segment. Each row has −/+, a numeric input and quick chips 1/5/10/20. It shows the unit price, struck-through regular price, discount and next-tier hint. A newly added row flashes (#EDF3EE bg, #2E6B45 border) for 1.6s. A sticky summary on the right (360px column on desktop) shows the totals. The continue button is disabled (#A7B1AA) when the cart is empty.
6. **Info**: name, mobile number and address (all required). Invalid fields get a #F04438 border. Errors: empty fields → "লাল চিহ্নিত ঘরগুলো পূরণ করুন।"; phone with fewer than 11 digits → "সঠিক ১১ সংখ্যার মোবাইল নম্বর দিন।"
7. **Done**: confirmation screen. "Restart" clears the cart and form and returns to Home.
8. **Account (আমার অর্ডার)**: phone login with a 4-digit OTP (mocked). Order history as accordions with a 4-step status tracker (অর্ডার পেয়েছি → ফোনে নিশ্চিত → পাঠানো হয়েছে → পৌঁছে গেছে).
9. **About**: story, 4-step journey (garden → tasting → packing → your shop), values and audience chips.
10. **Contact**: topic chips (অর্ডার/দাম/ডেলিভারি/অন্যান্য) and a form (name + 11-digit phone required) with a success state.

Mobile: when the cart is empty on Home, a sticky bottom CTA appears.

## Responsive Breakpoints
- `< 600px`: small phone (narrower carousels and cards)
- `< 860px`: mobile layout (single column, 60px header, section padding 40px)
- `< 1024px`: compact nav (hamburger), 2-column product grid, swipeable reviews
- `≥ 1024px`: full nav, 3-column product grid, sticky calculator
- `≥ 1100px`: wide order rows

Desktop section padding is 72px. Content max-width is 1240px with `clamp(16px,4vw,32px)` side padding.

## State
`page`, `qty{productId:kg}` (cart), `cq{}` (pricing-page mixed calculator), `calcPid`, `calcKg`, `form{name,phone,address}`, `tried`, `err`, `q` (search), `slide`, `menu`, `sub` (open dropdown), `pid` (product detail), auth (`logged`, `lphone`, `otpSent`, `otp`), contact form state.

Backend to build later: product and price catalog API, order submission, OTP SMS, order status. Recalculate prices on the server. Never trust client totals.

## Design System / Tokens
**Font:** Anek Bangla (Google Fonts) at weights 400, 500, 600 and 700, with system-ui as fallback. One family for the whole UI.

**Core colors**
| Token | Hex | Use |
|---|---|---|
| primary | #2E6B45 | buttons, links, active states |
| primary-dark | #1F4D31 | hover, dark text on light green |
| ink | #16261D | body text, dark chips |
| muted | #5C6660 | secondary text |
| border | #E2E6E2 | cards, dividers |
| border-input | #D3D9D4 | inputs, chips |
| green-50 | #EDF3EE | active/selected background |
| green-25 | #F7FAF7 | row with qty > 0 |
| green-200 | #A9C7B2 | border with qty > 0 |
| gold-accent | #D4AE76 | highlights on dark panels, free delivery |
| gold-text | #9A6A2F | gold text on light |
| dark-panel | #1F3A2B | calculator / premium panel |
| on-dark-sub | #C5D3C9 | secondary text on dark |
| error | #F04438 | validation |
| disabled | #A7B1AA | disabled CTA |
| page-bg | #FFFFFF | background |

**Segment colors** (text `c` / background tint `bgc`)
- Premium: #8A5A1F / #F6EEDF. Panel #1F3A2B (dark, white text, gold accent #D4AE76).
- Gold: #7A6410 / #F4EFD9. Panel #FAF5EA, accent #9A6A2F, pill #B07A3B.
- Silver: #4D5A55 / #ECEFEE. Panel #FFFFFF, accent #5C6A66, pill #6B7772.

**Status:** in progress #FBF3E8 bg / #8A5A22 text. Delivered #EDF3EE / #1F4D31.

**Radii:** 10px for inputs and buttons, 18px for the hero. Cards follow the same soft-radius family (see file).

**Interaction:** primary button hover goes to #1F4D31, links are #2E6B45 with #1F4D31 on hover, and selected chips flip to filled ink or primary.

**Tone:** simple spoken Bangla, short sentences, always concrete numbers ("আর ৩ কেজি নিলে আরও ৳১২০ সাশ্রয়"). No jargon.

## Assets
There are no final images yet. All photos are labeled placeholders (e.g. "ছবি: ভোরের চা বাগান"). Video reviews use a YouTube embed whose ID is a prop (`videoId`, `reviewVideoId`). Icons are simple text glyphs (৳ ✓ ⇄ ☎ → ▾). Swap them for an icon set.

## Files
- `Bongotea.dc.html`: the complete prototype (template, data and logic)
- `support.js`: the runtime needed to open the prototype in a browser
