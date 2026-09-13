# GioPages

A performance-first landing-page ecommerce system. A merchant authors landing pages, attaches products to them, and takes orders from paid traffic, paid at the door or online.

This glossary is the shared vocabulary for that domain. It says what each term means, not how anything is built.

## Language

### The selling surface

**Page**:
A publicly reachable landing page, authored as a complete HTML document and served at its own slug. A Page is either a draft or published; only a published Page is reachable.
_Avoid_: post, article, template, funnel step

**Slug**:
The flat path a Page is served at, and its permanent public identity. A slug is claimed for good once used, because it may be baked into ad creatives that outlive the Page itself.
_Avoid_: permalink, path, URL

**Went live**:
The moment a Page first became reachable at its slug. A Page can be pulled back to draft and published again, but it went live only once — the date is what a Merchant matches a Page against ad spend, so it is the first one, never the most recent.
_Avoid_: launched, activated, last published

**Product**:
Something a Customer can order, carrying a single price and no variations. Products have no public address of their own; they are only ever reached through a Page.
_Avoid_: item, SKU, variant, listing

**Checkout**:
The form on a Page that captures an Order, and asks how it will be paid when the Page offers more than one way. There is no cart and no multi-step flow: a Checkout captures one Order in one submission; an online payment happens after Capture, never before it. Every Page has its own Checkout, defined by its own Checkout Config.
_Avoid_: cart, basket, funnel

**Checkout Config**:
Everything one Page decides about its own Checkout: what it asks for, what it charges for delivery, which Products it offers, which Payment Methods it offers and in what order, and how it looks. A Config belongs to a single Page and is complete on its own, so changing it changes that Page and nothing else. Anything shared by every Page is a Setting, not part of a Config.
_Avoid_: settings, preset, theme, template

**Checkout Layout**:
How a Checkout is arranged and how it looks: what it draws and where, and nothing about what it asks for or what it charges. Ten of them — Stacked, Split, Split reversed, Stepper, Accordion, Bento grid, Compact embed, Chips, Invoice and Dark panel. Every question, the Product List when it is shown, the delivery choice, the payment choice, the totals and the failure slots are drawn by all ten, in the same order, and every one captures the same complete Order. A layout may own a slot of its own — a numbered heading, a step label, a card title, an empty-state line — and only a Page arranged in that layout is asked what the slot says. Two layouts behave as well as arrange: Stepper shows one panel at a time and Accordion opens one section at a time, and without script both fall back to every panel in order, so what is captured never depends on the behaviour. Dark panel's navy is its own, the one colour on the form that is not the Page's. Half-width pairing of questions is refused by the narrow-column layouts, Compact embed and Split reversed.
_Avoid_: template, theme, skin, variant, form-only, summary-first

**Field Width**:
How much of a row one Checkout Field takes: the whole of it, or half, so that two short questions sit side by side. A width is a choice between those two and never a figure, and it is a promise about wide screens only — below the breakpoint every Field fills the row, with nothing a Merchant can say to change it, because the traffic these Pages answer is overwhelmingly phone-sized. A narrow-column Checkout Layout fills the row at every width, because a Merchant choosing one has already said what they want the form to do and paired questions work against it. A width is kept either way, so a Page moved off a narrow-column layout asks its questions the way it always did.
_Avoid_: column, span, size, grid

**Style Token**:
One thing about a Checkout a Merchant can restyle — a colour, a length, a typeface. Every token is set once for the whole Checkout and applies to every Checkout Layout alike; no single Checkout Field restyles itself, so a form is styled in exactly one place. Tokens belong to one Page's Checkout Config like everything else in it: nothing a Merchant sets reaches another Page.
_Avoid_: theme, variable, style setting, CSS variable, field appearance

**Checkout Words**:
Everything a Checkout says in the Merchant's own words: the Order Button, the totals labels, each Payment Method's long and short label, and the slots a Checkout Layout owns. Every word has a default drawn from the designs, Bangla first and English second, so a Page that says nothing reads the way the design does. Words are copy and never figures, so none is in the Pricing Fingerprint. A word belonging to a layout the Page is not arranged in is kept unseen and comes back when the layout does.
_Avoid_: labels, copy, content, microcopy, translations

**Product List**:
The part of a Checkout that draws the Page's Products with their pictures, quantities and line totals. Shown unless the Merchant hides it, on any Checkout Layout, for a Page whose own markup has already sold the Product above the fold. A Checkout whose Product List is hidden sells everything the Page sells, one of each, and captures the same complete Order; it can offer no Product Picker, because there is nothing drawn to pick between.
_Avoid_: product panel, cart, form-only, summary

**Order Button**:
What a Checkout's button says, and the line beneath it, in the Merchant's own words. The wording may carry a total placeholder, filled in with what the courier will collect so the price sits on the button at the moment a Customer taps it. Both belong to one Page's Checkout Config; a Page that says nothing keeps the words the button has always had. Copy and not a figure, so neither is in the Pricing Fingerprint: a Merchant editing a word on a live Page never turns away an Order somebody has half-typed.
_Avoid_: CTA, call to action, submit label, microcopy

**Product Picker**:
Said of a Checkout that asks the Customer which of its Page's Products to buy, rather than selling all of them together. What it opens with is the Page's Default Selection. A Page down to a single Product asks nothing and sells it: a choice of one is not a choice, and neither is one on a Checkout whose Product List is hidden.
_Avoid_: cart, add-on, bundle, upsell toggle

**Default Selection**:
Which of a Page's Products its Product Picker opens ticked. None unless the Merchant says otherwise. One is a default the Customer sees a price from and then changes; several is a bundle the Merchant is asserting, and carries the risk the empty default exists to avoid — on a cash-on-delivery Page, an extra nobody chose is not a larger sale, it is a refused parcel. It settles nothing about what an Order costs; what was ticked at Capture does.
_Avoid_: preselected, default cart, bundle, preset

**Checkout Field**:
One thing a Checkout asks a Customer for. Built-in fields, which the system knows by name, and the Merchant's own sit in one ordered list, presented and validated alike.
_Avoid_: input, form field, custom field, attribute

**Field Constraints**:
What a Merchant has said an answer to one of their own Checkout Fields must be, chosen from a fixed vocabulary: a shortest and a longest for writing, a smallest and a largest for a number, whole numbers, and a shape picked from a named list. There is no rule a Merchant writes themselves anywhere, because a malformed one shows up as a conversion rate rather than as an error. One definition compiles into what the browser enforces and what the server checks, so the two can never disagree. A built-in field has none: what its column will hold is the built-in's to say.
_Avoid_: validation rules, rule string, regex, pattern string, constraints list

**Switched off**:
Said of a Checkout Field its Page has stopped asking for. It is absent from the form and from what the server checks, and an Order taken without it records nothing rather than a blank. It keeps its wording and its rules meanwhile, so switching it back on restores the question as it was — switched off and not compulsory are separate things, and a compulsory field that is switched off is simply inert. Phone is the one field that cannot be switched off, because a Customer is their phone number.
_Avoid_: disabled, hidden, removed, deleted, inactive, optional

**Shortcode**:
A placeholder a Merchant writes into a Page's HTML that the system replaces with generated content when the Page is rendered. It is the only way a Page gets content the Merchant did not type themselves.
_Avoid_: tag, macro, snippet, token, embed

**Block**:
A Shortcode's content chosen from an editor rather than typed as text. A Block and a Shortcode are the same definition reached two ways, never two systems: whatever a Shortcode can produce, a Block produces identically.
_Avoid_: widget, component, element, section

### Orders

**Order**:
A Customer's committed request for products, captured on a Page at an agreed price. An Order records everything it needs to be fulfilled without consulting anything else.
_Avoid_: purchase, transaction, sale, lead

**Order Line**:
One Product's worth of an Order, recording what was sold, at what unit price, and in what quantity, as agreed at Capture. A Product is a reference from an Order Line, never a source of truth for it.
_Avoid_: line item, order item, cart item

**Answer**:
What a Customer said to one of the Checkout Fields a Merchant added of their own, as an Order records it: the field's key, the wording it was asked in, the control it was asked with, the value, and for a choice the wording of the option picked. A built-in field's answer is not this — it goes to the column that has always held it. A question asked and left alone is recorded with nothing for a value, which is a different thing from a question that was never asked.
_Avoid_: custom field, meta, extra data, attribute

**Capture**:
The moment a visitor submits the Checkout and an Order comes into existence. What was agreed at Capture — the products, their prices, and the totals — is fixed from then on.
_Avoid_: conversion, submission

**Pricing Fingerprint**:
What a Checkout quoted, reduced to a value the rendered form carries and a Capture checks back: the Products the Page sells, their prices, and what each Delivery Option costs. A submission whose fingerprint is not the Page's current one is refused outright and the Customer told to reload, because a visitor may be holding a Page rendered days ago and the figures on their screen would otherwise not be the figures collected at the door. Nothing else about a Checkout is in it — a question added, reworded or restyled since leaves it alone, so editing those never turns away an Order somebody has half-typed.
_Avoid_: hash, checksum, nonce, version, signature

**Customer**:
A person identified by their phone number, and nothing else. A Customer holds no name and no address; those belong to each Order they placed, because a phone number is an identity, not a person.
_Avoid_: user, account, buyer, client, lead

**District**:
The delivery region agreed with the Customer at Capture, distinct from the free-text address that tells a courier where the door is. It is optional fulfilment data, captured because couriers want a structured region that cannot be recovered from an address afterwards, and it settles nothing about what an Order costs.
_Avoid_: zone, area, thana, city

**Delivery Option**:
One delivery arrangement a Page offers, named and priced by the Merchant — "Inside Dhaka", "Outside Dhaka", "Free" — with an optional detail line beneath the name, "1–2 days", that layouts with room draw and segmented ones leave out. A Page may offer none, one or several; which one is agreed at Capture is what a Delivery Charge comes from. The detail is copy, not a figure, and is outside the Pricing Fingerprint.
_Avoid_: shipping method, delivery zone, tier, rate

**Delivery Charge**:
The amount added to an Order for getting the parcel to the door, taken from the Delivery Option agreed at Capture. It is part of what the courier collects, and it is agreed at Capture like every other figure on the Order.
_Avoid_: shipping cost, courier fee, postage

**Cancelled**:
An Order that genuinely happened but will not be fulfilled — an unreachable number, a changed mind, a refused parcel. Distinct from a deleted Order, which is one that should never have existed at all. Cancelling says nothing about money: a Payment that completes against a Cancelled Order is recorded Paid, and the Order is both at once until somebody refunds it. It says nothing about a parcel either: a Shipment already moving goes on moving, and the Order is Cancelled and out for delivery at once until it comes back.
_Avoid_: rejected, void, failed, refunded

### Paying

**Payment Method**:
One of the named ways an Order can be paid, known to the system by name: Cash on Delivery and the online gateways. The Merchant switches each on for the installation and supplies what the gateway needs; a Page then chooses which of the switched-on ones it offers, and in what order. A method the installation has not switched on, or cannot reach, is offered nowhere, whatever a Page says. On a Checkout each method is drawn under a long label or a short one, both among the Page's Checkout Words; which one a Checkout Layout draws is the layout's decision.
_Avoid_: gateway, payment option, payment provider, driver

**Cash on Delivery**:
The Payment Method where the courier collects the total at the door. The one method every Page starts with, the one a Page falls back to when nothing else it lists can be offered, and the one that needs no Payment: an Order paid this way is simply due.
_Avoid_: COD-only, offline, manual

**Payment**:
One attempt to collect an Order's total through one online Payment Method, from the moment the Customer is sent to the gateway until the gateway's answer is verified. An Order may hold several; one is meant to end Paid, and a second that does is recorded Paid all the same and flags the Order as paid twice, because money moved and somebody must refund it. A Payment records the amount it was for, which is the Order's total today and need not always be. Its outcome is its own axis, separate from whether the Order is New, Confirmed or Cancelled: neither ever overwrites the other.
_Avoid_: transaction, charge, payment attempt, payment status (of an Order)

**Paid**:
Said of a Payment the gateway has been asked about and has confirmed, and by extension of the Order it belongs to. Only a Verification the system made itself can say it; what the gateway wrote into the Customer's return URL is never enough.
_Avoid_: successful, completed, settled

**Pending**:
Said of a Payment the Customer has been sent to the gateway for and nothing has been verified about yet. A Pending Payment older than a day that still cannot be verified is Expired, and an Expired or Failed Payment can be retried like any other.
_Avoid_: processing, in progress, awaiting

**Unpaid**:
Said of an Order that chose an online Payment Method and has no Paid Payment: none started, or every one Pending, Failed, Cancelled by the Customer, or Expired. An Unpaid Order sits in the fulfilment queue like any other, drawn distinctly, because the call an operator makes about it is a different call.
_Avoid_: abandoned, incomplete, outstanding

**Verification**:
The system asking a gateway, in a call of its own, whether a Payment is Paid. It is the only thing that can mark one so, and it runs from three places that all say the same thing: the Processing Page, a Notification, and the sweep over Pending Payments.
_Avoid_: callback, validation, IPN check

**Notification**:
A gateway telling the system on its own that something happened to a Payment. Treated as a hint and never as an answer: it triggers a Verification and records nothing by itself.
_Avoid_: webhook, IPN, callback

**Processing Page**:
Where a gateway sends a Customer back. It says the Payment is being verified, runs the Verification, then sends the Customer to the Order Complete Page or the Retry Page. It prints nothing about the Customer, because it is the one address a gateway's own logs hold.
_Avoid_: callback page, return URL, success page

**Order Complete Page**:
Where a Customer lands once their Order is settled as far as the Checkout is concerned: straight after a Cash on Delivery Capture, or once a Payment is Paid. Addressed by order number alone. It shows the Order in full — the Customer's name, number and address included, and their note where the Page asked a question keyed `note` — only to the browser that placed it, for as long as that browser keeps what Capture handed it; anyone else holding the address sees the Order and not the Customer. The address itself unlocks nothing, so it may sit in history and Referer headers without giving anything away.
_Avoid_: thank-you page, confirmation page, success page, receipt (as a name for the page; Receipt is one Order Complete Layout)

**Order Complete Config**:
Everything one Page decides about its own Order Complete Page: how it is arranged, and what it says in the Merchant's own words. It belongs to a single Page and is complete on its own, like a Checkout Config, and is not part of one: the Checkout captures the Order, and this is where the Customer lands afterwards. A Page that says nothing keeps the words the page has always had.
_Avoid_: thank-you settings, success page settings, template, theme

**Order Complete Layout**:
How an Order Complete Page is arranged: what it draws, and nothing about what the Order contains. Every layout draws the order number, the Order's lines and totals, how it is paid, and where it is delivered when the browser is the one that placed it. A layout may draw a slot of its own — a status line above the headline, a row of steps, a set of tiles — and only a Page arranged in that layout is asked what the slot says. A layout rearranges by its own block of rules and never reorders the document: all ten — Centered classic, Split, Timeline, Receipt, Dark hero, Minimal, Cards grid, Editorial, Accent band and Compact — write the same parts in the same order and differ in where the rules put them. Timeline's slot is the four steps and the Order's capture time beside its number; Cards grid's is the three tiles; Receipt's is a Print button whose handler is the one line of script the page carries. Dark hero's band is a navy of its own, the one colour on the page that is not the Page's.
_Avoid_: template, theme, skin, variant, design

**Retry Page**:
Where a Customer lands with an Unpaid Order: after a Failed, Cancelled or Expired Payment, or after a Capture that chose a method the Page no longer offers. It shows the Order and offers the Page's Payment Methods again, Cash on Delivery among them if the Page offers it. Addressed by order number alone, like the Order Complete Page.
_Avoid_: failure page, error page, payment page

### Delivering

**Courier**:
A delivery company a parcel is handed to, known to the system by name — Steadfast, Pathao and RedEx to begin with. Like a Payment Method, the Merchant supplies what it needs once for the whole installation and a Courier that has not been supplied is offered nowhere. What any one of them can be asked is its own answer and no two agree: on what an address is, on whether a parcel's progress is announced or must be asked for, on whether many may be booked at once, or on whether they will say what they are holding.
_Avoid_: carrier, delivery partner, logistics provider, shipping method, vendor

**Shipment**:
One attempt to get one Order to a door through one Courier. An Order holds as many as it took — a parcel that came back and was sent again is two, each with its own Consignment, its own charge and its own account of what happened, because the first attempt cost money whatever the second one does. A Shipment records the recipient details it was actually sent with, which need not be what the Order says today. It exists from the moment the attempt is committed rather than from the moment a Courier accepts it: a batch writes every row before anything is dispatched, so a Shipment may be Awaiting Dispatch, and a parcel a Courier then refuses leaves the row behind saying so.
_Avoid_: delivery, parcel, booking, dispatch, consignment

**Consignment**:
The Courier's own identifier for a parcel, handed back when a Shipment is booked, and the only name that Courier will answer to afterwards. Every Courier spells it differently and issues it on its own terms, so a Consignment is quoted back and never interpreted, and it is never what the system knows a Shipment by.
_Avoid_: tracking id, tracking code, AWB, waybill, reference

**Parcel Detail**:
Something one Courier needs told about a parcel that no other Courier asks about — how it is to be delivered, what is inside it, what it weighs, which store it leaves from. Each is declared by that Courier's driver and nowhere else, so the send dialog puts the chosen Courier's questions and no other's in front of a User, opened on what the Merchant decided once in Settings. A change there belongs to that parcel alone: it is recorded on the Shipment as sent and configures nothing. A question with only one possible answer — a Merchant with a single pickup store — is never asked, and is sent all the same.
_Avoid_: courier option, custom field, extra field, metadata, parameter

**Delivery Area**:
One of a Courier's own delivery areas, known by the id that Courier gave it. Not somewhere the system knows of: it is a word one Courier insists on hearing, because it will not read an address in free text the way the others do. The system caches that Courier's list, guesses which area an Order's address and District mean, and puts the guess in front of a User already chosen for them to confirm or correct — a guess, never a decision, because a parcel sent to a guessed depot is one the Merchant pays to have returned. A parcel whose area nobody chose is not sent at all.
_Avoid_: zone, thana, area id, location, region

**Shipment Event**:
One thing that happened to a Shipment, as the system came to know it. It carries two times and not one: when the Courier says it happened, where the Courier said, and when we found out, which is always. The two sit far apart for a Courier that must be asked rather than one that announces, and a timeline showing only one would quietly misdate every parcel from the couriers that say least.
_Avoid_: status change, tracking update, history entry, log

**Announcement**:
A Courier telling the system, on its own, that something happened to a parcel. Like a Notification it is a hint and never an answer: one claiming a parcel's state has moved is read only far enough to name the Shipment, and the system then asks that Courier itself and records what its own call learned. One carrying only a message — a line of prose about the parcel, from the Courier that keeps no history anyone could ask — is written to the timeline as text and can move no status, no filter, no figure and no Shipment's Active-ness. A Courier that announces nothing is polled, which for it is the only channel there ever was. A webhook is the post it arrives on and a Courier is asked whether it has one; the Announcement is what the post said.
_Avoid_: callback, push, tracking update, status update

**Active**:
Said of a Shipment still worth waiting on: not Delivered, not partly delivered, not Returned, not refused at booking, and not Abandoned. An Order has at most one, which is what stops a parcel being booked twice and what the Orders list draws. An Order with none is an Order that may be sent.
_Avoid_: open, live, in progress, pending, current

**Awaiting Dispatch**:
Said of a Shipment written down and not yet handed to anybody. Only a batch leaves one: a single parcel is booked while a User waits on the Consignment, where a batch of two hundred is written first and worked through by the sweep, because nobody is watching it and no Courier can be asked two hundred questions inside one request. The row is the whole point — a batch interrupted halfway has a record of every parcel it booked, and a parcel is offered exactly once because it is offered from its own row. It is Active from the moment it is written, so the Order it belongs to cannot go into a second batch.
_Avoid_: queued, pending, scheduled, draft, unsent

**Refused at Booking**:
Said of a Shipment a Courier would not take when it was offered. A time and the Courier's own words, never a status, exactly as Abandoned is — the row goes on saying truthfully that it was written and never dispatched, and the refusal is what takes it out of Active so the Order is one to send again. Only a batch produces one: a booking made while a User waits words the refusal back at them and records nothing at all.
_Avoid_: rejected, failed, cancelled, error, bounced

**Returned**:
Said of a Shipment whose parcel came back undelivered. It is the Courier's outcome and not the Merchant's decision, and it is emphatically not Cancelled: a returned parcel is a question — ring the Customer, correct the address, send it again, or give up — and the answer is a User's. Two of the three couriers say "cancelled" in their own words for exactly this, which is precisely why the system does not.
_Avoid_: cancelled, failed, undelivered, refused, RTO

**Abandoned**:
Said of a Shipment a User has written off — a parcel a Courier never collected, or one held so long there is nothing left to wait for. It is a time and a name and never a status, because the Courier's last word is worth keeping: a Shipment On Hold and then abandoned says both, where a status overwritten would say only that somebody gave up. Abandoning frees the Order to be sent again, and asserts that the first parcel is dead.
_Avoid_: cancelled, void, closed, written off, expired

**Balance (courier-reported)**:
What a Courier says it is holding of the Merchant's money, in that Courier's own words about its own account. Only one of the three publishes such a figure at all, and the other two are said to report none rather than shown a blank — a blank in a money column is read as a zero, and a zero here tells a Merchant their money has been paid over. A figure that could not be fetched is the last one read, with the time it was read at, or the reason there is none; it is never a zero and it is never added to COD in Flight.
_Avoid_: courier balance, wallet, float, due, payable

**COD in Flight**:
What the couriers are between them holding of the Merchant's money: what every Active Shipment was sent out to collect. Counted from the Merchant's own Orders rather than from anything a Courier says, so every Courier has the figure alike — and never added to a Courier's reported balance, because one is what we believe and the other is what we were told.
_Avoid_: outstanding, receivable, pending COD, courier balance, float

### Reporting

**Reporting Window**:
The span of days every report figure is counted over, chosen once for a whole screen and obeyed by everything on it: Today, Yesterday, Last 7 days, Last 30 days, This month, or two dates. A day begins and ends in the Merchant's timezone, never the server's, because a Merchant judges a day's spend against a day's Orders and both happen in the same place. Every window has a comparison window of the same length immediately before it, which is what a change is measured against.
_Avoid_: date range, period, filter, timeframe

**Page Report**:
Everything one Page's own figures say over a Reporting Window — its Orders, Sales, Cancellation Rate and Average Order Value, what became of its Orders, which of its Products carried it, and how it was paid and delivered — on a screen of its own. One Page's answer to the questions the Dashboard asks of every Page at once, which is why it is drawn by the Dashboard's own widgets counting that Page alone. A Page that never took an Order has one, reading as zeros; a retired Page keeps one, because what it earned before it was taken down is still worth reading.
_Avoid_: analytics, stats, insights, page metrics

**Orders (as a figure)**:
How many Orders were captured in a Reporting Window, whatever became of them afterwards. It is the number ad spend is judged against: a Cancelled Order still cost a click.
_Avoid_: conversions, leads, sales

**Sales**:
The money side of a Reporting Window: the totals, Delivery Charge included, of every Order captured in it that is not Cancelled. Counted at Capture, not when it is paid or delivered, because a Cash on Delivery Order has no other moment. Orders and Sales are always shown side by side and never collapsed into one.
_Avoid_: revenue, turnover, income, GMV

**Cancellation Rate**:
Cancelled Orders as a share of Orders in a Reporting Window. Drawn as its own figure so that cancelling an Order moves a rate a Merchant watches rather than quietly shrinking a total.
_Avoid_: return rate, failure rate, churn

**Average Order Value**:
Sales divided by the Orders that count towards Sales in a Reporting Window. Zero, not undefined, when there are none.
_Avoid_: AOV, basket size, ticket size

**Needs Attention**:
The Orders and Payments an operator must act on regardless of any Reporting Window: a New Order nobody has called in a day, a Payment Pending for over an hour, an Order Paid twice, an Order Paid and Cancelled at once. It is a to-do list, not a report, so it ignores the window.
_Avoid_: alerts, exceptions, issues, anomalies

**Visit**:
One load of a published Page by a browser, counted per Page per day. Every load, not every browser, matching how an ad platform counts the same thing, so the two figures agree. Loads of the Order Complete Page and the Retry Page are not Visits: whoever is on them has already ordered, and counting them would dilute the Conversion Rate with people who converted. Counted only where the Merchant has switched Visit counting on, because counting is a database write on the path of every page load; what is stored is the count and never the load, so it holds nothing about anybody and is kept indefinitely.
_Avoid_: page view, hit, session, traffic

**Conversion Rate**:
Orders as a share of Visits in a Reporting Window, drawn per Page on the Page leaderboard. Every Order counts towards it, Cancelled ones included, for the same reason `Orders` as a figure counts them: the Visit converted whatever became of the Order afterwards. It owes nothing to any ad platform — a Merchant who reports to none still has one — and it is unknown rather than zero where Visits are not counted.
_Avoid_: CVR, conversion, close rate, win rate

**Courier Report**:
Everything the couriers' figures say over a Reporting Window — parcels sent, delivered and returned, how often each Courier delivers, how long it takes, and what it charged — on a screen of its own, broken down by Courier and never blended into one total. A figure a Courier never reports reads as nothing for that Courier rather than as zero for the trade, because the couriers do not answer the same questions and a single blended figure would present one courier's silence as everybody's.
_Avoid_: delivery analytics, logistics report, shipping stats, courier dashboard

### Tracking

**Moment**:
Something that happens in this domain and is worth reporting to an ad platform: a Visit, a Checkout Started, a Capture, a Paid. Tracking is organised around Moments rather than around any one platform's event names, so what the system reports is described in its own words and each Provider translates. A Moment produces zero, one or several provider events, and which ones is the Provider's answer.
_Avoid_: event, conversion, trigger, pixel event

**Checkout Started**:
The Moment a visitor first touches a Checkout Field on a page load. It says the visitor began filling the form, not that they submitted it; a Capture is a different Moment.
_Avoid_: begin checkout, add to cart, engagement

**Provider**:
An ad platform events can be reported to — Facebook first, TikTok and Google Ads later. A Provider owns everything platform-specific: which of its own events a Moment produces, how many may travel in one request, how long after the fact it still accepts them, and why a Destination cannot be delivered to.
_Avoid_: channel, integration, vendor, network

**Destination**:
One configured place a Provider's events are sent: for Facebook, a dataset and the access token that writes to it, under a name the Merchant chose. A Merchant may hold several — separate brands, separate clients — and marks exactly one default per Provider, which is where a Page that has picked nothing reports. Switching a Destination off pauses reporting to it without deleting it.
_Avoid_: pixel, account, connection, integration

**Purchase**:
Exactly one is counted per Order. A Cash on Delivery Order reports it at Capture, because there is no later moment; an Order paid online reports it only once a Payment is Paid, so an Order abandoned at the gateway is never reported as revenue. What it is worth is the Order's total, Delivery Charge included, so it agrees with Sales. It is sent twice — once from the Customer's browser and once from the server — and counted once, because both copies carry the order number as the deduplication id.
_Avoid_: conversion, sale, transaction

**Pixel**:
The browser's half of reporting: a snippet a page carries that reports a Moment from the Customer's own browser, beside the copy the server sends. Both copies carry the same deduplication id, so the Provider counts one event. A Destination is not a Pixel — the Pixel is what addresses one, and a page with no Destination carries none.
_Avoid_: tag, tracker, script, beacon

**Tracking Outbox**:
Where a Moment is written down, one row per Destination per provider event, so that no web request ever calls an ad platform. A scheduled sweep delivers what is waiting. A row holds no personal detail: what a Provider is told about the Customer is read from the Order when the row is delivered.
_Avoid_: queue, event log, buffer

**Tracking Context**:
What the browser told us when an Order was captured — the click identifiers, the IP address, the User-Agent and the Page it was on — kept apart from the Order in a table of its own. It exists for the Moment that arrives with no browser present: an Order paid online reports its Purchase when a Verification says Paid, which happens from a sweep or a gateway's own notification. Kept only where such a Moment is still to come, because an Order reporting at Capture has already written the same things onto its outbox row. Not part of the Order: an Order records what it needs to be fulfilled and is kept indefinitely, and a browser identifier is neither.
_Avoid_: session, visitor record, attribution data, fingerprint

**Tracking Config**:
Everything one Page decides about its own tracking: which Destination it reports to, which of each Provider's events it fires there, and whether what it reports may name what was bought. It belongs to a single Page and is complete on its own, like a Checkout Config and an Order Complete Config, and is a third thing beside them. Every decision in it can be left unsaid: no Destination means the default for each Provider, and an event nobody has decided is whatever Settings says. Nothing in it is a figure, so it is not in the Pricing Fingerprint — switching an event off never turns away an Order somebody has half-typed.
_Avoid_: pixel settings, tracking options, event config

### People

**User**:
Someone who signs into the admin to author Pages and work Orders. A User is never a Customer; the two never overlap.
_Avoid_: admin, operator, staff

**Merchant**:
The business running an installation of GioPages. Relevant because the system is self-hosted by many merchants, so anything a merchant would reasonably differ on — currency, the list of Districts — belongs in configuration rather than baked into the system.
_Avoid_: tenant, client, store owner

### The installation

**Settings**:
The values a Merchant decides once for the whole installation rather than per Page: how the Merchant is reached by payment providers and couriers, and anything else that would be the same on every Page. A Setting is never part of a Checkout Config; if a Page could reasonably differ on it, it is not a Setting.
_Avoid_: config, options, preferences, site settings, global settings
